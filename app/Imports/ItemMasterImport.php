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
 * Columns are read by POSITION, in the order below, because the file comes from
 * the downloadable template. A header row is detected and skipped.
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
        'Brand',
        'Size',
        'Specification',
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
     * One example row, so the template shows the expected shape. It is marked
     * with EXAMPLE_PREFIX and skipped on import: a user who fills in rows under
     * it without deleting it would otherwise create a junk item, and that is
     * exactly the mistake a sample row invites.
     */
    public const SAMPLE_ROW = [
        'EXAMPLE — delete this row', 'Organ', '14', 'DPX17 FFG, chrome',
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

        foreach ($rows as $index => $row) {
            // Spreadsheet row number as the user sees it in Excel.
            $line = $index + 1;

            if (self::isBlank($row)) {
                continue;
            }

            if ($index === 0 && self::isHeader($row)) {
                continue;
            }

            // Column positions follow self::COLUMNS exactly:
            // 0 Item Name, 1 Brand, 2 Size, 3 Specification, 4 Uom, 5 Category,
            // 6 Opening Stock, 7 Counted On, 8 Safety, 9 Re-order, 10 Lead, 11 Remarks.
            $name = self::text($row[0] ?? null);
            $uom = self::text($row[4] ?? null);
            $category = self::text($row[5] ?? null);

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

            $opening = self::number($row[6] ?? null);
            if ($opening === false) {
                $errors[] = 'Row '.$line.': Opening Stock must be a number.';
                continue;
            }

            $countedOn = self::date($row[7] ?? null);
            if ($countedOn === false) {
                $errors[] = 'Row '.$line.': Counted On is not a valid date. Use YYYY-MM-DD.';
                continue;
            }

            $safety = self::number($row[8] ?? null);
            if ($safety === false) {
                $errors[] = 'Row '.$line.': Safety Stock must be a number, or blank to calculate it automatically.';
                continue;
            }

            $reorder = self::number($row[9] ?? null);
            if ($reorder === false) {
                $errors[] = 'Row '.$line.': Re-order Level must be a number, or blank to calculate it automatically.';
                continue;
            }

            $leadTime = self::number($row[10] ?? null);
            if ($leadTime === false || ($leadTime !== null && $leadTime < 0)) {
                $errors[] = 'Row '.$line.': Lead Time must be a whole number of days.';
                continue;
            }

            $seen[$key] = true;

            $items[] = [
                'name' => mb_substr($name, 0, 255),
                'brand' => self::text($row[1] ?? null),
                'size' => self::text($row[2] ?? null, 100),
                'specification' => self::text($row[3] ?? null, 2000),
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
                'remarks' => self::text($row[11] ?? null, 1000),
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
        $first = mb_strtolower(trim((string) ($row[0] ?? '')));

        return in_array(rtrim($first, '*'), ['item name', 'name', 'item'], true);
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
