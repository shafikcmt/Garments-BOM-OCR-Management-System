<?php

namespace App\Imports;

use App\Imports\Concerns\ReadsItemSheets;
use App\Models\StockIssue;
use App\Models\StockItem;
use App\Models\StockPurchase;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;

/**
 * Backfilling the Stock Report's three blank columns for items carried over
 * from the old Excel sheets: Unit Price, Date of Last Addition and Date of Last
 * Consumption.
 *
 * IT FILLS BLANKS AND NOTHING ELSE. Every field, on every item, is written only
 * when the report has nothing to show there today. A value already present is
 * never replaced, however different the cell in the file — and the three fields
 * are decided INDEPENDENTLY, so an item missing only a price gets only its
 * price and keeps the dates it already had. This is the whole rule, and it is
 * why the import cannot damage a report that is already correct.
 *
 * WHAT "ALREADY HAS A VALUE" MEANS, per field:
 *
 *   Unit Price   a priced purchase exists on stock_purchases, OR the item
 *                master carries a standard price. Either one makes the report's
 *                Value column non-blank, so the item is not missing a price.
 *   Last Add.    any stock_purchases row exists for the item, OR a reference
 *                date was already set.
 *   Last Cons.   any stock_issues row exists for the item, OR a reference
 *                date was already set.
 *
 * The transaction checks are deliberately UNBOUNDED BY DATE, while the report
 * bounds its own lookups at the end of the period on screen. An item bought in
 * September shows a blank Last Addition while August is displayed, and this
 * still refuses to write a reference date for it — because a reference date is
 * a claim about an item with no history at all, and one that contradicted a
 * real movement in another month would be worse than a blank cell.
 *
 * IT NEVER WRITES A TRANSACTION. The two dates go to
 * stock_items.last_addition_reference and last_consumption_reference, which
 * exist precisely so that no backdated purchase or issue has to be invented to
 * make a derived column read differently. stock_purchases and stock_issues are
 * READ here, never written, and this importer has no code that could write
 * them.
 *
 * IT NEVER CREATES AN ITEM. A row matching nothing is a misspelling, and
 * coining an item that has a price and no uom, category or safety level would
 * put a phantom into the report — the same reasoning the price, issue and
 * receiving imports use.
 *
 * IT MATCHES ON NAME AND BRAND TOGETHER, through the shared identity in
 * ReadsItemSheets. 107 item names appear more than once and 106 of those carry
 * two or more brands, so a name-only file would backfill the wrong make and
 * look like it had worked.
 *
 * Parsing WRITES NOTHING. plan() returns what would change; applying it is the
 * controller's second step, after a person has seen the counts.
 */
class StockReferenceImport implements ToArray, WithCustomCsvSettings
{
    use ReadsItemSheets;

    /**
     * Five columns. Only the item is required — a file may carry any one of
     * the three values, or all three.
     *
     * @var list<string>
     */
    public const COLUMNS = [
        'Item Name*',
        'Brand/Specification',
        'Unit Price',
        'Last Addition Date',
        'Last Consumption Date',
    ];

    /**
     * Header spellings accepted for each field, normalised by self::key().
     * The first entry of each list is what the template writes; the rest let a
     * file built from the Item Master export, or from the Stock Report's own
     * column names, be used as-is.
     *
     * @var array<string, list<string>>
     */
    private const HEADINGS = [
        'name' => ['itemname', 'nameofitem', 'item', 'particulars'],
        'brand' => ['brandspecification', 'brand', 'brandname', 'make', 'specification'],
        'price' => ['unitprice', 'price', 'rate', 'unitrate'],
        'last_addition' => ['lastadditiondate', 'dateoflastaddition', 'lastaddition', 'lastadd', 'lastadddate'],
        'last_consumption' => ['lastconsumptiondate', 'dateoflastcons', 'lastconsumption', 'lastcons', 'lastconsdate'],
    ];

    /**
     * The item is all that is structurally required. A sheet naming no value
     * column at all parses to an empty plan rather than an error — it is a
     * file with nothing to say, not a malformed one.
     *
     * @var list<string>
     */
    private const REQUIRED_HEADINGS = ['name'];

    /** The three fields this importer can fill, in report column order. */
    public const FIELDS = ['unit_price', 'last_addition', 'last_consumption'];

    /** Human labels for the counts on the preview screen. */
    public const FIELD_LABELS = [
        'unit_price' => 'Unit Price',
        'last_addition' => 'Last Addition Date',
        'last_consumption' => 'Last Consumption Date',
    ];

    /** The item master column each field is written to. */
    public const FIELD_COLUMNS = [
        'unit_price' => 'unit_price',
        'last_addition' => 'last_addition_reference',
        'last_consumption' => 'last_consumption_reference',
    ];

    /**
     * Item names starting with this are treated as template placeholders.
     *
     * The template no longer writes an example row — it lists the real items
     * that need filling in — but this stays: a file built from the earlier
     * template, or from any sheet somebody pasted an example into, must still
     * skip that row rather than report it as an unknown item.
     */
    public const EXAMPLE_PREFIX = 'example';

    /** How far down the sheet to look for the heading row. */
    private const HEADER_SEARCH_LIMIT = 30;

    /** @return array<string, string> */
    public function getCsvSettings(): array
    {
        return ['delimiter' => ','];
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<int, array<int, mixed>>
     */
    public function array(array $rows): array
    {
        return $rows;
    }

    /**
     * Read a sheet into a plan: the writes that would happen, the rows that
     * could not be read, and the ones deliberately left alone.
     *
     * NOTHING IS WRITTEN HERE. The plan is shown to a person first, and only a
     * confirmed plan reaches the database.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{updates: list<array<string, mixed>>, errors: list<string>, skipped: list<string>, counts: array<string, int>, matched: int}
     */
    public static function plan(array $rows): array
    {
        $blank = [
            'updates' => [],
            'errors' => [],
            'skipped' => [],
            'counts' => array_fill_keys(self::FIELDS, 0),
            'matched' => 0,
        ];

        [$headerIndex, $map] = self::locateHeader($rows);

        if ($headerIndex === null) {
            return array_merge($blank, ['errors' => [
                'No heading row was found. The sheet needs a row naming at least Item Name — download the sample template to see the expected columns.',
            ]]);
        }

        $items = self::itemIdentityMap();
        $state = self::gaps($items->values()->all());

        $updates = [];
        $errors = [];
        $skipped = [];
        $counts = array_fill_keys(self::FIELDS, 0);
        $seen = [];
        $matched = 0;

        foreach ($rows as $index => $row) {
            if ($index <= $headerIndex || self::isBlank($row)) {
                continue;
            }

            $line = $index + 1;
            $cell = fn (string $field) => isset($map[$field]) ? ($row[$map[$field]] ?? null) : null;

            $name = self::text($cell('name'));

            // A repeated heading row, or a trailing note — neither is data.
            if ($name === null) {
                continue;
            }

            if (str_starts_with(mb_strtolower($name), self::EXAMPLE_PREFIX)) {
                $skipped[] = 'Row '.$line.': the template example row was ignored.';

                continue;
            }

            $brand = self::text($cell('brand'));
            $label = '"'.$name.'" ('.($brand ?? 'no brand').')';
            $key = self::identity($name, $brand);

            if (! isset($items[$key])) {
                // Reported, never created — see the class comment. Named in
                // full so the spelling that failed is the spelling shown.
                $errors[] = 'Row '.$line.': '.$label.' is not in the item master. '
                    .'Check the Item Name and Brand/Specification against the exported list — an item stocked under two brands needs the right one.';

                continue;
            }

            if (isset($seen[$key])) {
                $skipped[] = 'Row '.$line.': '.$label.' is listed more than once in this file; the first row was used.';

                continue;
            }

            $seen[$key] = true;
            $matched++;

            $id = $items[$key];
            $has = $state[$id]['has'];

            $values = self::readValues($cell, $label, $line, $errors);

            if ($values === null) {
                continue;
            }

            $write = [];

            foreach (self::FIELDS as $field) {
                if ($values[$field] === null) {
                    // A blank cell is not a way of clearing a value. Somebody
                    // filling a column of 1,241 rows leaves most of them empty
                    // on the first pass.
                    continue;
                }

                if ($has[$field]) {
                    $skipped[] = 'Row '.$line.': '.$label.' already has a '.self::FIELD_LABELS[$field]
                        .' on the report, so that column was left as it is.';

                    continue;
                }

                $write[$field] = $values[$field];
                $counts[$field]++;
            }

            if ($write === []) {
                continue;
            }

            $updates[] = [
                'id' => $id,
                'label' => $label,
                'values' => $write,
            ];
        }

        return [
            'updates' => $updates,
            'errors' => $errors,
            'skipped' => $skipped,
            'counts' => $counts,
            'matched' => $matched,
        ];
    }

    /**
     * The three cells of one row, or null when any of them is unreadable.
     *
     * A bad cell is an error rather than a skip: "13-14 Jan" in a date column
     * is somebody meaning something the importer cannot guess, and quietly
     * leaving it out would report success over a row nobody applied.
     *
     * @param  list<string>  $errors
     * @return array<string, mixed>|null
     */
    private static function readValues(callable $cell, string $label, int $line, array &$errors): ?array
    {
        $price = self::number($cell('price'));

        if ($price === false || (is_float($price) && $price < 0)) {
            $errors[] = 'Row '.$line.': Unit Price for '.$label.' must be a number of 0 or more.';

            return null;
        }

        $values = ['unit_price' => $price];

        foreach (['last_addition', 'last_consumption'] as $field) {
            $date = self::date($cell($field));

            if ($date === false) {
                $errors[] = 'Row '.$line.': '.self::FIELD_LABELS[$field].' for '.$label
                    .' could not be read as a date. Use a real date cell, or type it as 2026-01-15.';

                return null;
            }

            $values[$field] = $date;
        }

        return $values;
    }

    /**
     * What each item already has, and what it already shows.
     *
     * ONE definition of "missing", used by two callers: plan() reads the has-
     * flags to decide what it may write, and the template export reads the same
     * flags to decide which items to list and which cells to leave blank. If
     * these were computed separately the template could offer a column the
     * import would then refuse, which is exactly the confusion a pre-filled
     * template is meant to remove.
     *
     * has      whether the report already shows something in that column, by
     *          the same period-independent rule the import applies: a real
     *          transaction at ANY date, or a value on the item master.
     * current  what it shows, for the template to carry as read-only context.
     *          Null where there is nothing to show — including the rare item
     *          whose only purchase carries no date, which HAS an addition and
     *          no date to print for it.
     *
     * Four grouped reads, never a query per item: a file may name every one of
     * 1,241 items. stock_purchases and stock_issues are READ here and nowhere
     * written.
     *
     * @param  list<int>|null  $ids  null for every item in the master
     * @return array<int, array{has: array<string, bool>, current: array<string, mixed>}>
     */
    public static function gaps(?array $ids = null): array
    {
        $only = fn ($query, string $column) => $ids === null ? $query : $query->whereIn($column, $ids);

        // Presence in a grouped result IS existence, so no count is needed
        // beside the MAX. Both are plain aggregates, identical on MySQL,
        // PostgreSQL and SQLite.
        $purchases = $only(StockPurchase::query(), 'stock_item_id')
            ->select('stock_item_id')
            ->selectRaw('max(purchase_date) as last_date')
            ->groupBy('stock_item_id')
            ->get()
            ->keyBy('stock_item_id');

        $issues = $only(StockIssue::query(), 'stock_item_id')
            ->select('stock_item_id')
            ->selectRaw('max(issue_date) as last_date')
            ->groupBy('stock_item_id')
            ->get()
            ->keyBy('stock_item_id');

        // The price on the most recent challan, read as a lean ordered list and
        // reduced in PHP — the same approach, and the same figure, as the
        // report's own Unit Price column.
        $priced = $only(StockPurchase::query(), 'stock_item_id')
            ->whereNotNull('purchase_date')
            ->whereNotNull('unit_price')
            ->where('unit_price', '>', 0)
            ->orderByDesc('purchase_date')
            ->orderByDesc('id')
            ->get(['stock_item_id', 'unit_price'])
            ->groupBy('stock_item_id')
            ->map(fn ($group) => (float) $group->first()->unit_price);

        $master = $only(StockItem::query(), 'id')
            ->get(['id', 'unit_price', 'last_addition_reference', 'last_consumption_reference']);

        $state = [];

        foreach ($master as $item) {
            $id = (int) $item->id;

            $challanPrice = $priced[$id] ?? null;
            $masterPrice = $item->unit_price !== null ? (float) $item->unit_price : null;

            $lastAddition = $purchases[$id]->last_date ?? null;
            $lastConsumption = $issues[$id]->last_date ?? null;

            $state[$id] = [
                'has' => [
                    'unit_price' => $challanPrice !== null || $masterPrice !== null,
                    'last_addition' => isset($purchases[$id]) || $item->last_addition_reference !== null,
                    'last_consumption' => isset($issues[$id]) || $item->last_consumption_reference !== null,
                ],
                'current' => [
                    // A real movement or challan wins over the reference, the
                    // same order the report resolves them in.
                    'unit_price' => $challanPrice ?? $masterPrice,
                    'last_addition' => self::isoDate($lastAddition) ?? self::isoDate($item->last_addition_reference),
                    'last_consumption' => self::isoDate($lastConsumption) ?? self::isoDate($item->last_consumption_reference),
                ],
            ];
        }

        return $state;
    }

    /**
     * A stored date as plain ISO text.
     *
     * SQLite hands these back as "2026-03-01 00:00:00" where MySQL and
     * PostgreSQL give a date, so the string is trimmed to its date part rather
     * than printed as stored.
     */
    private static function isoDate(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }
}