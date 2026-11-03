<?php

namespace App\Imports;

use App\Models\Supplier;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;

/**
 * Bulk upload for the vendor list (`suppliers`), the one Merchandising, Supply
 * Chain and Admin share.
 *
 * Same rules as the General Stock supplier upload: the file is checked as a whole
 * and a bad row leaves the list exactly as it was; a vendor already on the list is
 * reported as skipped and never overwritten (edit it on screen instead).
 */
class VendorListImport implements ToArray, WithCustomCsvSettings
{
    /**
     * Columns in order: the header row of the template and of the export, and the
     * order rows are read in, so the three can never disagree.
     *
     * @var list<string>
     */
    public const COLUMNS = [
        'Vendor Name*', 'Vendor Code', 'Legal Name', 'Contact Person', 'Email', 'Phone', 'Address', 'City', 'Country',
        'Item Type', 'Incoterm', 'Ship Mode', 'Tolerance %',
        'Bank Name', 'Bank Branch', 'Account Name', 'Account Number', 'Routing Number', 'SWIFT Code',
    ];

    /** Field each column fills, same order as COLUMNS; and the longest value kept. */
    private const FIELDS = [
        ['supplier_name', 255], ['supplier_code', 100], ['legal_name', 255], ['contact_person', 255], ['email', 255], ['phone', 50],
        ['address', 2000], ['city', 100], ['country', 100], ['item_type', 100], ['incoterm', 50], ['ship_mode', 50], ['tolerance_percent', 20],
        ['bank_name', 255], ['bank_branch', 255], ['bank_account_name', 255], ['bank_account_number', 100], ['bank_routing_number', 100], ['bank_swift_code', 50],
    ];

    public const SAMPLE_ROW = [
        'EXAMPLE — delete this row', 'SUP-001', 'Example Trading Ltd', 'Rahim Uddin', 'rahim@example.com', '+8801711000000', '12 Station Road',
        'Dhaka', 'Bangladesh', 'Zipper', 'FOB', 'SEA', '3',
        'Dutch-Bangla Bank', 'Motijheel', 'Example Trading Ltd', '1234567890', '', 'DBBLBDDH',
    ];

    /** Names starting with this are treated as template placeholders. */
    public const EXAMPLE_PREFIX = 'example';

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
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{vendors: list<array<string, mixed>>, errors: list<string>, skipped: list<string>}
     */
    public static function parse(array $rows): array
    {
        $vendors = [];
        $errors = [];
        $skipped = [];

        $names = Supplier::pluck('supplier_name')->mapWithKeys(fn ($n) => [mb_strtolower(trim($n)) => true]);
        $codes = Supplier::whereNotNull('supplier_code')->pluck('supplier_code')->mapWithKeys(fn ($c) => [mb_strtolower(trim($c)) => true]);
        $seenNames = [];
        $seenCodes = [];

        foreach ($rows as $index => $row) {
            $line = $index + 1;

            if (self::isBlank($row)) {
                continue;
            }

            if ($index === 0 && self::isHeader($row)) {
                continue;
            }

            $data = [];
            foreach (self::FIELDS as $i => [$field, $limit]) {
                $data[$field] = self::text($row[$i] ?? null, $limit);
            }

            $name = $data['supplier_name'];

            if ($name === null) {
                $errors[] = 'Row ' . $line . ': Vendor Name is required.';

                continue;
            }

            $nameKey = mb_strtolower($name);

            if (str_starts_with($nameKey, self::EXAMPLE_PREFIX)) {
                $skipped[] = 'Row ' . $line . ': the template example row was ignored.';

                continue;
            }

            if (isset($names[$nameKey])) {
                $skipped[] = 'Row ' . $line . ': "' . $name . '" is already in the vendor list (edit it on screen to change it).';

                continue;
            }

            if (isset($seenNames[$nameKey])) {
                $skipped[] = 'Row ' . $line . ': "' . $name . '" is listed more than once in this file.';

                continue;
            }

            if ($data['email'] !== null && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Row ' . $line . ': "' . $data['email'] . '" is not a valid email address.';
            }

            if ($data['tolerance_percent'] !== null) {
                $tolerance = str_replace(['%', ' '], '', $data['tolerance_percent']);

                if (! is_numeric($tolerance) || (float) $tolerance < 0 || (float) $tolerance > 100) {
                    $errors[] = 'Row ' . $line . ': Tolerance % must be a number from 0 to 100.';
                } else {
                    $data['tolerance_percent'] = round((float) $tolerance, 2);
                }
            }

            if ($data['supplier_code'] !== null) {
                $codeKey = mb_strtolower($data['supplier_code']);

                if (isset($codes[$codeKey]) || isset($seenCodes[$codeKey])) {
                    $errors[] = 'Row ' . $line . ': Vendor Code "' . $data['supplier_code'] . '" is already used by another vendor.';
                }
                $seenCodes[$codeKey] = true;
            }

            // An export masks the account number ("****7890"); that is not a number to keep.
            if ($data['bank_account_number'] !== null && str_starts_with($data['bank_account_number'], '*')) {
                $data['bank_account_number'] = null;
            }

            $seenNames[$nameKey] = true;
            $vendors[] = $data + ['is_active' => true];
        }

        return ['vendors' => $vendors, 'errors' => $errors, 'skipped' => $skipped];
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

        return in_array(rtrim($first, '*'), ['vendor name', 'supplier name', 'vendor', 'supplier', 'name'], true);
    }

    private static function text(mixed $value, int $limit): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}
