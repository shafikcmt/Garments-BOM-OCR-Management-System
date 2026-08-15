<?php

namespace App\Imports;

use App\Models\GeneralStockSupplier;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;

/**
 * Bulk upload for the General Stock supplier list.
 *
 * A supplier is just a name, so the file is a single column. Header row is
 * skipped, and validation is all-or-nothing so a file with a bad row leaves the
 * list exactly as it was.
 */
class SupplierListImport implements ToArray, WithCustomCsvSettings
{
    /**
     * Template columns, in order. Also the header row the sample template
     * writes, so the two can never disagree.
     *
     * @var list<string>
     */
    public const COLUMNS = [
        'Supplier Name*',
        'Contact Person',
        'Phone',
        'Email',
        'Address',
    ];

    /** One example row, skipped on import (see EXAMPLE_PREFIX). */
    public const SAMPLE_ROW = [
        'EXAMPLE — delete this row',
        'Rahim Uddin',
        '+8801711000000',
        'rahim@example.com',
        '12 Station Road, Tangail',
    ];

    /** Supplier names starting with this are treated as template placeholders. */
    public const EXAMPLE_PREFIX = 'example';

    /**
     * Pinned so PhpSpreadsheet cannot guess the space character as the
     * delimiter and split "Sun Trading House" into pieces.
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
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{suppliers: list<array<string, mixed>>, errors: list<string>, skipped: list<string>}
     */
    public static function parse(array $rows): array
    {
        $suppliers = [];
        $errors = [];
        $skipped = [];

        $existing = GeneralStockSupplier::pluck('name')
            ->mapWithKeys(fn ($name) => [mb_strtolower(trim($name)) => true]);

        $seen = [];

        foreach ($rows as $index => $row) {
            $line = $index + 1;

            if (self::isBlank($row)) {
                continue;
            }

            if ($index === 0 && self::isHeader($row)) {
                continue;
            }

            $name = self::text($row[0] ?? null);

            if ($name === null) {
                $errors[] = 'Row '.$line.': Supplier Name is required.';
                continue;
            }

            $key = mb_strtolower($name);

            if (str_starts_with($key, self::EXAMPLE_PREFIX)) {
                $skipped[] = 'Row '.$line.': the template example row was ignored.';
                continue;
            }

            if (isset($existing[$key])) {
                $skipped[] = 'Row '.$line.': "'.$name.'" is already in the supplier list.';
                continue;
            }

            if (isset($seen[$key])) {
                $skipped[] = 'Row '.$line.': "'.$name.'" is listed more than once in this file.';
                continue;
            }

            $seen[$key] = true;

            // Name is the only column required. The contact columns are read
            // when present and simply come out null when they are not, so a
            // one-column file written to the older template - or a list
            // exported from someone's own spreadsheet - still imports.
            $suppliers[] = [
                'name' => $name,
                'contact_person' => self::text($row[1] ?? null),
                'phone' => self::text($row[2] ?? null, 50),
                'email' => self::text($row[3] ?? null),
                'address' => self::text($row[4] ?? null, 1000),
                'is_active' => true,
            ];
        }

        return ['suppliers' => $suppliers, 'errors' => $errors, 'skipped' => $skipped];
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

    /** @param array<int, mixed> $row */
    private static function isHeader(array $row): bool
    {
        $first = mb_strtolower(trim((string) ($row[0] ?? '')));

        return in_array(rtrim($first, '*'), ['supplier name', 'supplier', 'name'], true);
    }

    private static function text(mixed $value, int $limit = 255): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}
