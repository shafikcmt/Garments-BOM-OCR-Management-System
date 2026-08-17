<?php

namespace App\Imports;

use App\Models\GeneralStockSupplier;
use App\Models\StockItem;
use App\Models\StockPurchase;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Bulk goods-receiving upload for General Stock.
 *
 * Reads the company's own "Consumable Stock Report" Purchase sheet as-is, so
 * years of historical deliveries can be loaded without being retyped or
 * reformatted, and doubles as the format of the downloadable blank template.
 *
 * Two things make this different from ItemMasterImport, which it otherwise
 * follows closely:
 *
 *   - Columns are matched by HEADER NAME, not by position. The item master
 *     importer only ever sees its own template, so position is safe there. This
 *     one has to read a legacy workbook nobody is going to re-order for us, and
 *     header matching means an extra column or a moved one changes nothing.
 *   - The header row is SEARCHED for rather than assumed to be row 1. The
 *     legacy sheet opens with five rows of company name, title and subtotals.
 *
 * Rows are grouped into deliveries. Several consecutive lines sharing an RV No,
 * challan number, challan date and supplier are the item lines of ONE delivery
 * and are recorded together, exactly as the manual form records them.
 *
 * A delivery is the unit of success: one bad line rejects its own delivery and
 * leaves every other delivery in the file importable. Re-uploading a corrected
 * file is therefore safe, because a delivery already in the system is
 * recognised and skipped rather than recorded twice — see recordedKey() for how
 * one is recognised with and without a challan number.
 */
class ReceivingImport implements ToArray, WithCustomCsvSettings
{
    /**
     * Template columns, in the order the legacy workbook writes them. Also the
     * heading row of the sample template download, so the two cannot disagree.
     *
     * Month, RV No, Uom, Category and Total Value are read but never stored —
     * stock_purchases has no column for any of them. They are kept here so the
     * legacy file uploads unchanged, and are used only to group rows and to
     * cross-check what the item master already says.
     *
     * @var list<string>
     */
    public const COLUMNS = [
        'Challan Date*',
        'RCV Date',
        'Month',
        'GRN No',
        'Challan No/Invoice No',
        'Supplier Name',
        'Item Name*',
        // A reference column. The company's own workbook carries it beside the
        // item name, so the template matches what people paste from. It
        // describes the ITEM, not the delivery, and the item master stays the
        // authority for it — see the cross-checks in parse().
        'Brand/Specification',
        'Uom',
        'Category',
        'Purchased Qty*',
        'Unit Price',
        'Total Value',
        'Remarks',
    ];

    /**
     * Header spellings accepted for each field, normalised by self::key().
     * The first entry of each list is what the template writes; the rest are
     * what the legacy workbook and its variants have been seen to use.
     *
     * @var array<string, list<string>>
     */
    private const HEADINGS = [
        'purchase_date' => ['challandate', 'challandt', 'date'],
        'rcv_date' => ['rcvdate', 'receivedate', 'receiveddate', 'grndate'],
        // "GRN No" is what the template writes now; "RV No" is what it wrote
        // before the rename and what every workbook already on someone's desk
        // still says. Both are accepted, so an old file keeps importing.
        'rv_no' => ['grnno', 'grn', 'rvno', 'rv'],
        'challan_no' => ['challannoinvoiceno', 'challanno', 'invoiceno', 'challaninvoiceno'],
        'supplier_name' => ['suppliername', 'supplier', 'vendorname', 'vendor'],
        'item_name' => ['itemname', 'nameofitem', 'nameofitems', 'item', 'particulars'],
        // Brand, Size and Specification became one column. A file written to
        // the older template still uploads: its Brand column is read here, its
        // Specification column is read as the stand-in below, and its Size
        // column is ignored.
        'brand' => ['brandspecification', 'brand', 'brandname', 'make'],
        'legacy_specification' => ['specification', 'spec', 'specs', 'specifications'],
        'uom' => ['uom', 'unit'],
        'category' => ['category', 'itemcategory'],
        'qty' => ['purchasedqty', 'qty', 'quantity', 'receivedqty'],
        'unit_price' => ['unitprice', 'rate', 'unitrate', 'price'],
        'total_value' => ['totalvalue', 'total', 'amount'],
        'remarks' => ['remarks', 'remark', 'note', 'notes'],
    ];

    /** Fields without which a row cannot be read at all. */
    private const REQUIRED_HEADINGS = ['purchase_date', 'item_name', 'qty'];

    /**
     * One example row, so the template shows the expected shape. Marked with
     * EXAMPLE_PREFIX and skipped on import — a user who fills in rows beneath it
     * without deleting it would otherwise import a junk delivery.
     */
    public const SAMPLE_ROW = [
        '2026-08-01', '2026-08-02', 'Aug-26', '', 'CH-862', 'Pioneer Sewing',
        'EXAMPLE — delete this row', 'Groz-Beckert DBx1 90/14, ball point',
        'Pkt', 'Needle', 10, 145, 1450, 'Optional note',
    ];

    /** Item names starting with this are treated as template placeholders. */
    public const EXAMPLE_PREFIX = 'example';

    /** How far down the sheet to look for the heading row. */
    private const HEADER_SEARCH_LIMIT = 30;

    /**
     * Pinned for the same reason as ItemMasterImport: on a file with few commas
     * PhpSpreadsheet's delimiter detection can guess the space character and
     * split an item name into pieces.
     *
     * @return array<string, string>
     */
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
     * Parse and validate a sheet into ready-to-write deliveries.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{challans: list<array<string, mixed>>, errors: list<string>, skipped: list<string>, notes: list<string>}
     */
    public static function parse(array $rows): array
    {
        // array_merge, NOT the + operator: on a duplicate key + keeps the LEFT
        // side, so `$blank + ['errors' => [...]]` returned $blank's empty errors
        // and threw the message away. Same bug as IssueImport carried, from the
        // same copy.
        $blank = ['challans' => [], 'errors' => [], 'skipped' => [], 'notes' => []];

        [$headerIndex, $map] = self::locateHeader($rows);

        if ($headerIndex === null) {
            return array_merge($blank, ['errors' => [
                'No heading row was found. The sheet needs a row naming at least Challan Date, Item Name and Purchased Qty — download the sample template to see the expected columns.',
            ]]);
        }

        $missing = array_values(array_diff(self::REQUIRED_HEADINGS, array_keys($map)));

        if ($missing) {
            return array_merge($blank, ['errors' => [
                'The heading row is missing these required columns: '
                    .implode(', ', array_map(fn ($f) => self::label($f), $missing)).'.',
            ]]);
        }

        // One query each, not one per row.
        $items = StockItem::get(['id', 'name', 'uom', 'category', 'brand'])
            ->keyBy(fn (StockItem $item) => mb_strtolower(trim($item->name)));

        $suppliers = GeneralStockSupplier::get(['id', 'name'])
            ->keyBy(fn (GeneralStockSupplier $s) => mb_strtolower(trim($s->name)));

        $alreadyRecorded = self::recordedChallanKeys();

        /** @var array<string, array<string, mixed>> $groups */
        $groups = [];
        $skipped = [];

        foreach ($rows as $index => $row) {
            if ($index <= $headerIndex || self::isBlank($row)) {
                continue;
            }

            // The row number as the user sees it in Excel.
            $line = $index + 1;

            $cell = fn (string $field) => isset($map[$field]) ? ($row[$map[$field]] ?? null) : null;

            $itemName = self::text($cell('item_name'));

            // A repeated heading row, or a subtotal line carrying figures but no
            // item — neither is data, and neither is an error worth reporting.
            if ($itemName === null) {
                continue;
            }

            if (str_starts_with(mb_strtolower($itemName), self::EXAMPLE_PREFIX)) {
                $skipped[] = 'Row '.$line.': the template example row was ignored.';
                continue;
            }

            $challanDate = self::date($cell('purchase_date'));
            $rcvDate = self::date($cell('rcv_date'));

            // Grouped on the SOURCE file's own identifiers, including the legacy
            // RV No — which is only ever read here, never stored. The new RV No
            // is allocated on save by the same generator the manual form uses.
            $groupKey = implode('|', [
                mb_strtolower(trim((string) self::text($cell('rv_no')))),
                mb_strtolower(trim((string) self::text($cell('challan_no')))),
                is_string($challanDate) ? $challanDate : 'invalid-'.$line,
                mb_strtolower(trim((string) self::text($cell('supplier_name')))),
            ]);

            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'purchase_date' => $challanDate,
                    'rcv_date' => $rcvDate,
                    'challan_no' => self::text($cell('challan_no'), 100),
                    'supplier_name' => self::text($cell('supplier_name')),
                    'legacy_rv' => self::text($cell('rv_no'), 100),
                    'first_line' => $line,
                    'lines' => [],
                    'errors' => [],
                    'notes' => [],
                ];
            }

            $group = &$groups[$groupKey];
            $group['last_line'] = $line;

            if ($challanDate === false) {
                $group['errors'][] = 'Row '.$line.': Challan Date is not a valid date. Use YYYY-MM-DD.';
                unset($group);

                continue;
            }

            if ($challanDate === null) {
                $group['errors'][] = 'Row '.$line.': Challan Date is required.';
                unset($group);

                continue;
            }

            if ($rcvDate === false) {
                $group['errors'][] = 'Row '.$line.': RCV Date is not a valid date. Use YYYY-MM-DD, or leave it blank.';
                unset($group);

                continue;
            }

            $item = $items->get(mb_strtolower($itemName));

            if (! $item) {
                // Never auto-created: an item invented from a typo would carry
                // no uom, category or safety level, and would then quietly
                // appear in the Stock Report as a real consumable.
                $group['errors'][] = 'Row '.$line.': "'.$itemName.'" is not in the item master. Add it under Items first, or correct the spelling.';
                unset($group);

                continue;
            }

            $qty = self::number($cell('qty'));

            if ($qty === false) {
                $group['errors'][] = 'Row '.$line.': Purchased Qty must be a number.';
                unset($group);

                continue;
            }

            // The legacy sheet has rows carrying a rate but no quantity. Nothing
            // was received on such a line, and importing it as zero would put a
            // meaningless line on the delivery.
            if ($qty === null || $qty <= 0) {
                $group['errors'][] = 'Row '.$line.': Purchased Qty must be greater than zero ("'.$itemName.'").';
                unset($group);

                continue;
            }

            $unitPrice = self::number($cell('unit_price'));

            if ($unitPrice === false || ($unitPrice !== null && $unitPrice < 0)) {
                $group['errors'][] = 'Row '.$line.': Unit Price must be a number of 0 or more, or blank.';
                unset($group);

                continue;
            }

            // ---- Cross-checks. None of these block the import: the item master
            // is the authority for uom and category, and the sheet's own total
            // is a stored formula result nobody has recalculated in years.
            $fileUom = self::text($cell('uom'), 50);
            if ($fileUom !== null && $item->uom && mb_strtolower($fileUom) !== mb_strtolower(trim($item->uom))) {
                $group['notes'][] = 'Row '.$line.': Uom in the file is "'.$fileUom.'" but "'.$itemName.'" is held in '.$item->uom.'. The item master was used.';
            }

            $fileCategory = self::text($cell('category'), 100);
            if ($fileCategory !== null && $item->category && mb_strtolower($fileCategory) !== mb_strtolower(trim($item->category))) {
                $group['notes'][] = 'Row '.$line.': Category in the file is "'.$fileCategory.'" but "'.$itemName.'" is categorised as '.$item->category.'. The item master was used.';
            }

            // Brand/Specification is a reference column on this sheet: it
            // describes the item, which already exists by the time a row gets
            // this far, so the file cannot introduce or change it. Same
            // treatment as Uom and Category above — a difference is worth
            // telling somebody about, but never worth losing a delivery over,
            // so it is a note and the row imports.
            //
            // An older file's separate Specification column stands in when it
            // has no Brand column of its own.
            $fileBrand = self::text($cell('brand'), 190) ?? self::text($cell('legacy_specification'), 190);
            $masterBrand = $item->brand;

            if ($fileBrand !== null && $masterBrand
                && mb_strtolower($fileBrand) !== mb_strtolower(trim($masterBrand))) {
                $group['notes'][] = 'Row '.$line.': Brand/Specification in the file is "'.$fileBrand.'" but "'
                    .$itemName.'" is held as '.trim($masterBrand).'. The item master was used.';
            }

            $fileTotal = self::number($cell('total_value'));
            if ($fileTotal !== null && $fileTotal !== false && $unitPrice !== null) {
                $computed = round($qty * $unitPrice, 2);

                if (abs($computed - round($fileTotal, 2)) > 0.01) {
                    $group['notes'][] = 'Row '.$line.': Total Value in the file is '.number_format($fileTotal, 2)
                        .' but Qty x Rate is '.number_format($computed, 2).'. The recalculated value was used.';
                }
            }

            $group['lines'][] = [
                'stock_item_id' => $item->id,
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'remarks' => self::text($cell('remarks'), 1000),
                'line' => $line,
            ];

            unset($group);
        }

        return self::settle($groups, $suppliers, $alreadyRecorded, $skipped);
    }

    /**
     * Turn the collected groups into deliveries to write, deliveries to report
     * as broken, and deliveries already in the system.
     *
     * @param  array<string, array<string, mixed>>  $groups
     * @param  \Illuminate\Support\Collection<string, GeneralStockSupplier>  $suppliers
     * @param  array<string, true>  $alreadyRecorded
     * @param  list<string>  $skipped
     * @return array{challans: list<array<string, mixed>>, errors: list<string>, skipped: list<string>, notes: list<string>}
     */
    private static function settle(array $groups, $suppliers, array $alreadyRecorded, array $skipped): array
    {
        $challans = [];
        $errors = [];
        $notes = [];

        foreach ($groups as $group) {
            $label = self::describe($group);

            // Every line of a delivery goes in together or none of it does. A
            // half-recorded challan would show a stock addition that does not
            // match the paper document it came from.
            if ($group['errors']) {
                $errors[] = $label.' was not imported:';

                foreach ($group['errors'] as $message) {
                    $errors[] = '   '.$message;
                }

                continue;
            }

            if (empty($group['lines'])) {
                continue;
            }

            // Goods cannot reach the store before the challan is written — the
            // same rule the manual form enforces.
            if ($group['rcv_date'] && $group['rcv_date'] < $group['purchase_date']) {
                $errors[] = $label.' was not imported: RCV Date ('.$group['rcv_date']
                    .') is earlier than the Challan Date ('.$group['purchase_date'].').';

                continue;
            }

            $recordedKey = self::recordedKey(
                $group['challan_no'],
                $group['purchase_date'],
                $group['supplier_name'],
                self::itemSetKey($group['lines'])
            );

            if (isset($alreadyRecorded[$recordedKey])) {
                $skipped[] = $label.' is already recorded in Purchase History and was skipped.';

                continue;
            }

            $supplier = $group['supplier_name']
                ? $suppliers->get(mb_strtolower(trim($group['supplier_name'])))
                : null;

            if ($group['supplier_name'] && ! $supplier) {
                // Kept as free text rather than added to the supplier master: a
                // misspelling in a legacy sheet would otherwise become a
                // permanent supplier nobody chose to create.
                $notes[] = $label.': "'.$group['supplier_name'].'" is not in the supplier list, so it was saved as plain text.';
            }

            foreach ($group['notes'] as $note) {
                $notes[] = $note;
            }

            $challans[] = [
                'purchase_date' => $group['purchase_date'],
                // Blank RCV Date follows the challan, which also puts the RV No
                // in the month the delivery actually belongs to.
                'rcv_date' => $group['rcv_date'] ?: $group['purchase_date'],
                'challan_no' => $group['challan_no'],
                'supplier_name' => $supplier?->name ?? $group['supplier_name'],
                'general_stock_supplier_id' => $supplier?->id,
                'lines' => $group['lines'],
                'label' => $label,
            ];
        }

        return ['challans' => $challans, 'errors' => $errors, 'skipped' => $skipped, 'notes' => $notes];
    }

    /** How a delivery is named in every message about it. */
    private static function describe(array $group): string
    {
        $parts = array_filter([
            $group['challan_no'] ? 'Challan '.$group['challan_no'] : null,
            $group['legacy_rv'] ? 'RV '.$group['legacy_rv'] : null,
            is_string($group['purchase_date']) ? $group['purchase_date'] : null,
            $group['supplier_name'],
        ]);

        $rows = $group['first_line'] === ($group['last_line'] ?? $group['first_line'])
            ? 'row '.$group['first_line']
            : 'rows '.$group['first_line'].'-'.$group['last_line'];

        return ($parts ? implode(' · ', $parts) : 'Delivery').' ('.$rows.')';
    }

    /**
     * Deliveries already in stock_purchases, so a re-uploaded file adds nothing
     * twice.
     *
     * Read in two passes, because the two kinds of delivery are not identified
     * the same way:
     *
     *   - A NUMBERED challan is identified by its number, with the date and
     *     supplier alongside it. The number is the supplier's own reference for
     *     that delivery and does not repeat.
     *   - An UNNUMBERED one has no such reference, and date + supplier alone is
     *     not enough: one supplier can deliver twice in a day against two
     *     separate documents, and treating the second as a repeat of the first
     *     would silently drop a real delivery. Its item set joins the key, so a
     *     repeat is only a repeat when the same items are on it.
     *
     * Unnumbered rows are gathered by rv_no, which is what marks one delivery in
     * the table — grouping them by date and supplier instead would merge two
     * separate deliveries into one combined item set that then matches neither.
     * Rows carrying neither number fall back to date + supplier, the most that
     * can be known about them.
     *
     * @return array<string, true>
     */
    private static function recordedChallanKeys(): array
    {
        $keys = StockPurchase::query()
            ->whereNotNull('challan_no')
            ->where('challan_no', '<>', '')
            ->select('challan_no', 'purchase_date', 'supplier_name')
            ->distinct()
            ->get()
            ->mapWithKeys(fn ($row) => [
                self::recordedKey(
                    $row->challan_no,
                    $row->purchase_date?->toDateString(),
                    $row->supplier_name
                ) => true,
            ])
            ->all();

        $deliveries = StockPurchase::query()
            ->where(fn ($q) => $q->whereNull('challan_no')->orWhere('challan_no', ''))
            ->get(['rv_no', 'challan_no', 'purchase_date', 'supplier_name', 'stock_item_id'])
            ->groupBy(fn ($row) => trim((string) $row->rv_no) !== ''
                ? 'rv|'.$row->rv_no
                : 'ds|'.$row->purchase_date?->toDateString().'|'.mb_strtolower(trim((string) $row->supplier_name)));

        foreach ($deliveries as $rows) {
            $first = $rows->first();

            $keys[self::recordedKey(
                null,
                $first->purchase_date?->toDateString(),
                $first->supplier_name,
                self::itemSetKey($rows->all())
            )] = true;
        }

        return $keys;
    }

    /**
     * How a delivery is recognised as one already recorded.
     *
     * The item set participates ONLY when there is no challan number. With a
     * number present it would do harm rather than good: adding a forgotten line
     * to a challan already entered would change its item set, the delivery would
     * no longer look recorded, and re-uploading the file would enter the whole
     * challan a second time.
     */
    private static function recordedKey(?string $challanNo, ?string $date, ?string $supplier, string $itemSet = ''): string
    {
        $challanNo = mb_strtolower(trim((string) $challanNo));

        return implode('|', [
            $challanNo,
            (string) $date,
            mb_strtolower(trim((string) $supplier)),
            $challanNo === '' ? $itemSet : '',
        ]);
    }

    /**
     * A delivery's items as one comparable value: distinct item ids, sorted, so
     * the same items listed in a different order still match.
     *
     * Ids rather than names — both sides of the comparison have already resolved
     * their item through the master, so an id cannot disagree with itself over
     * spelling or letter case the way a name can.
     *
     * @param  array<int, array<string, mixed>|\App\Models\StockPurchase>  $lines
     */
    private static function itemSetKey(array $lines): string
    {
        $ids = [];

        foreach ($lines as $line) {
            $ids[] = (int) (is_array($line) ? $line['stock_item_id'] : $line->stock_item_id);
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return implode(',', $ids);
    }

    /**
     * Find the heading row and map each known field to its column index.
     *
     * Searched for rather than assumed, because the legacy workbook puts five
     * rows of company name, report title and subtotals above it.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{0: int|null, 1: array<string, int>}
     */
    private static function locateHeader(array $rows): array
    {
        $limit = min(count($rows), self::HEADER_SEARCH_LIMIT);

        for ($index = 0; $index < $limit; $index++) {
            $map = self::mapHeadings($rows[$index] ?? []);

            // A row is the heading row once it names the three fields without
            // which a line cannot be read at all. Anything above it — titles,
            // subtotals, blank spacers — names none of them.
            if (count(array_intersect(self::REQUIRED_HEADINGS, array_keys($map))) === count(self::REQUIRED_HEADINGS)) {
                return [$index, $map];
            }
        }

        return [null, []];
    }

    /**
     * @param  array<int, mixed>  $row
     * @return array<string, int>
     */
    private static function mapHeadings(array $row): array
    {
        $map = [];

        foreach ($row as $column => $heading) {
            $key = self::key((string) $heading);

            if ($key === '') {
                continue;
            }

            foreach (self::HEADINGS as $field => $accepted) {
                // First column wins, so a sheet repeating a heading later does
                // not steal the mapping from the real one.
                if (! isset($map[$field]) && in_array($key, $accepted, true)) {
                    $map[$field] = $column;
                    break;
                }
            }
        }

        return $map;
    }

    /** Heading text reduced to letters and digits: "Challan No/Invoice No" -> "challannoinvoiceno". */
    private static function key(string $heading): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($heading))) ?? '';
    }

    private static function label(string $field): string
    {
        return match ($field) {
            'purchase_date' => 'Challan Date',
            'item_name' => 'Item Name',
            'qty' => 'Purchased Qty',
            default => $field,
        };
    }

    /** @param array<int, mixed> $row */
    private static function isBlank(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private static function text(mixed $value, int $limit = 255): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    /**
     * @return float|null|false  null when blank, false when not a number
     */
    private static function number(mixed $value): float|null|false
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        // Tolerate thousands separators and a currency symbol typed into a
        // spreadsheet cell that was never formatted as a number.
        $value = str_replace([',', ' '], '', $value);

        return is_numeric($value) ? (float) $value : false;
    }

    /**
     * @return string|null|false  null when blank, false when unparseable
     */
    private static function date(mixed $value): string|null|false
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        // A real Excel date cell arrives as a serial number, not a string.
        if (is_numeric($value)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
            } catch (\Throwable $e) {
                return false;
            }
        }

        try {
            return Carbon::parse(trim((string) $value))->toDateString();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
