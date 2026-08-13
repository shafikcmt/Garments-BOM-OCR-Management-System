<?php

namespace App\Imports;

use App\Models\StockItem;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Bulk item-master upload for General Stock.
 *
 * Columns are matched by HEADING, with the order below as the fallback for a
 * file that carries no heading row at all. Reading them by position alone
 * stopped being safe when Size and Specification were merged into
 * Brand/Specification: a template downloaded before that change still sits on
 * people's desks, and reading its twelve columns as ten would have put Uom
 * under Category and a date under Opening Stock without a word of complaint.
 *
 * Validation is all-or-nothing on purpose: the file is checked in full and
 * saved only if every row passes. A half-imported item master — some items in,
 * some missing, no clear record of which — is harder to recover from than a
 * rejected file, and the caller wraps the save in a transaction to guarantee it.
 */
class ItemMasterImport implements ToArray, WithCustomCsvSettings
{
    /**
     * Template columns, in order. Also the header row written by the sample
     * template download, so the two can never disagree.
     *
     * @var list<string>
     */
    public const COLUMNS = [
        'Item Name*',
        // One field, not three. Brand, Size and Specification were filled
        // inconsistently and read as a single line anyway.
        'Brand/Specification',
        'Uom*',
        'Category*',
        'Opening Stock',
        'Counted On',
        'Safety Stock',
        'Re-order Level',
        'Lead Time',
        'Remarks',
    ];

    /**
     * Header spellings accepted for each field, normalised by self::key().
     * The first entry of each list is what the template writes; the rest cover
     * the older template and the wordings people retype by hand.
     *
     * `legacy_specification` is the old separate Specification column: it is
     * read only to fill Brand/Specification when the file's Brand column is
     * empty. The old Size column is deliberately absent — it is ignored.
     *
     * @var array<string, list<string>>
     */
    private const HEADINGS = [
        'name' => ['itemname', 'name', 'item'],
        'brand' => ['brandspecification', 'brand', 'brandname', 'make'],
        'legacy_specification' => ['specification', 'spec', 'specs', 'specifications'],
        'uom' => ['uom', 'unit'],
        'category' => ['category', 'itemcategory'],
        'opening_qty' => ['openingstock', 'opening', 'openingqty'],
        'opening_as_on' => ['countedon', 'ason', 'openingason', 'countdate'],
        'safety_stock_qty' => ['safetystock', 'safety', 'safetystocklevel'],
        'reorder_level' => ['reorderlevel', 'reorder'],
        'lead_time_days' => ['leadtime', 'leadtimedays', 'lead'],
        'remarks' => ['remarks', 'remark', 'note', 'notes'],
    ];

    /**
     * One example row, so the template shows the expected shape. It is marked
     * with EXAMPLE_PREFIX and skipped on import: a user who fills in rows under
     * it without deleting it would otherwise create a junk item, and that is
     * exactly the mistake a sample row invites.
     */
    public const SAMPLE_ROW = [
        'EXAMPLE — delete this row', 'Organ DPX17-14 FFG, chrome',
        'Pkt', 'Needle', 25, '2026-08-01', '', '', 7, 'Optional note',
    ];

    /** Item names starting with this are treated as template placeholders. */
    public const EXAMPLE_PREFIX = 'example';

    /**
     * Pinned for the same reason as MasterListImport: on a file with few commas
     * PhpSpreadsheet's delimiter detection can guess the space character and
     * silently split "B/S Needle DPX17-14" into pieces.
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
     * Parse and validate a sheet.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{items: list<array<string, mixed>>, errors: list<string>, skipped: list<string>}
     */
    public static function parse(array $rows): array
    {
        $items = [];
        $errors = [];
        $skipped = [];

        // Existing names, compared case-insensitively — one query, not one per row.
        $existing = StockItem::pluck('name')
            ->mapWithKeys(fn ($name) => [mb_strtolower(trim($name)) => true]);

        // Names claimed earlier in this same file, so a file that lists an item
        // twice reports it rather than inserting it twice.
        $seen = [];

        // Where each field sits in this particular file. Read from the heading
        // row when there is one, so a file written to the older template — or
        // one whose columns a user has reordered — still lands in the right
        // fields; positional, in COLUMNS order, when there is not.
        $map = self::isHeader($rows[0] ?? []) ? self::mapHeadings($rows[0] ?? []) : self::positions();

        foreach ($rows as $index => $row) {
            // Spreadsheet row number as the user sees it in Excel.
            $line = $index + 1;

            if (self::isBlank($row)) {
                continue;
            }

            if ($index === 0 && self::isHeader($row)) {
                continue;
            }

            $cell = fn (string $field) => isset($map[$field]) ? ($row[$map[$field]] ?? null) : null;

            $name = self::text($cell('name'));
            $uom = self::text($cell('uom'));
            $category = self::text($cell('category'));

            $missing = [];
            if ($name === null) { $missing[] = 'Item Name'; }
            if ($category === null) { $missing[] = 'Category'; }
            if ($uom === null) { $missing[] = 'Uom'; }

            if ($missing) {
                $errors[] = 'Row '.$line.': '.implode(' and ', $missing).' '.(count($missing) === 1 ? 'is' : 'are').' required.';
                continue;
            }

            $key = mb_strtolower($name);

            // The template's own example row, left in place by the user.
            if (str_starts_with($key, self::EXAMPLE_PREFIX)) {
                $skipped[] = 'Row '.$line.': the template example row was ignored.';
                continue;
            }

            if (isset($existing[$key])) {
                $skipped[] = 'Row '.$line.': "'.$name.'" already exists in the item master.';
                continue;
            }

            if (isset($seen[$key])) {
                $skipped[] = 'Row '.$line.': "'.$name.'" is listed more than once in this file.';
                continue;
            }

            $opening = self::number($cell('opening_qty'));
            if ($opening === false) {
                $errors[] = 'Row '.$line.': Opening Stock must be a number.';
                continue;
            }

            $countedOn = self::date($cell('opening_as_on'));
            if ($countedOn === false) {
                $errors[] = 'Row '.$line.': Counted On is not a valid date. Use YYYY-MM-DD.';
                continue;
            }

            $safety = self::number($cell('safety_stock_qty'));
            if ($safety === false) {
                $errors[] = 'Row '.$line.': Safety Stock must be a number, or blank to calculate it automatically.';
                continue;
            }

            $reorder = self::number($cell('reorder_level'));
            if ($reorder === false) {
                $errors[] = 'Row '.$line.': Re-order Level must be a number, or blank to calculate it automatically.';
                continue;
            }

            $leadTime = self::number($cell('lead_time_days'));
            if ($leadTime === false || ($leadTime !== null && $leadTime < 0)) {
                $errors[] = 'Row '.$line.': Lead Time must be a whole number of days.';
                continue;
            }

            $seen[$key] = true;

            $items[] = [
                'name' => mb_substr($name, 0, 255),
                // A file written to the older template carries the wording in
                // its Specification column instead, so it is used when the
                // Brand column has nothing. Its Size column is not read.
                'brand' => self::text($cell('brand')) ?? self::text($cell('legacy_specification')),
                'category' => mb_substr($category, 0, 100),
                'uom' => mb_substr($uom, 0, 50),
                // Blank opening means nothing on the shelf, counted today.
                'opening_qty' => $opening ?? 0,
                'opening_as_on' => $countedOn ?? now()->toDateString(),
                // Left null on purpose when blank: the Consumable Stock Report
                // then calculates these from last month's consumption, exactly
                // as it does for an item added through the form.
                'safety_stock_qty' => $safety,
                'reorder_level' => $reorder,
                'lead_time_days' => $leadTime !== null
                    ? (int) $leadTime
                    : (int) config('stock.general_stock.default_lead_time_days', 7),
                'remarks' => self::text($cell('remarks'), 1000),
                'is_active' => true,
            ];
        }

        return ['items' => $items, 'errors' => $errors, 'skipped' => $skipped];
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

    /**
     * The first row is a header if its first cell looks like the template's
     * first column rather than an item name.
     *
     * @param  array<int, mixed>  $row
     */
    private static function isHeader(array $row): bool
    {
        return in_array(self::key($row[0] ?? null), self::HEADINGS['name'], true);
    }

    /**
     * Which column each field sits in, read from a heading row.
     *
     * First spelling wins, so a legacy file carrying both "Brand" and
     * "Specification" keeps them apart — Brand is the field, Specification is
     * only the stand-in used when Brand is blank.
     *
     * @param  array<int, mixed>  $row
     * @return array<string, int>
     */
    private static function mapHeadings(array $row): array
    {
        $map = [];

        foreach ($row as $index => $heading) {
            $key = self::key($heading);

            if ($key === '') {
                continue;
            }

            foreach (self::HEADINGS as $field => $spellings) {
                if (! isset($map[$field]) && in_array($key, $spellings, true)) {
                    $map[$field] = (int) $index;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * Fallback for a file with no heading row: the columns in COLUMNS order.
     *
     * @return array<string, int>
     */
    private static function positions(): array
    {
        return [
            'name' => 0,
            'brand' => 1,
            'uom' => 2,
            'category' => 3,
            'opening_qty' => 4,
            'opening_as_on' => 5,
            'safety_stock_qty' => 6,
            'reorder_level' => 7,
            'lead_time_days' => 8,
            'remarks' => 9,
        ];
    }

    /** A heading reduced to letters and digits, so spacing and "*" cannot matter. */
    private static function key(mixed $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim((string) $value))) ?? '';
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

        // Tolerate thousands separators typed into a spreadsheet.
        $value = str_replace(',', '', $value);

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
