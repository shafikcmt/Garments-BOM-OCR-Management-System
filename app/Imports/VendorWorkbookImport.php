<?php

namespace App\Imports;

use App\Models\Supplier;
use App\Models\SupplierAddress;
use App\Models\SupplierBankAccount;
use App\Models\SupplierContact;
use App\Models\SupplierItemCategory;
use Carbon\Carbon;

/**
 * Vendor upload with related records, one sheet per kind so a vendor is never
 * repeated in the main sheet:
 *
 *   Vendors | Contacts | Addresses | Item Categories | Bank Accounts
 *
 * Child sheets point at a vendor by its Vendor Name (or Vendor Code) from the
 * Vendors sheet. Sheets are recognised by their header row, not by tab order or
 * name. The OLD single-sheet template (VendorListImport::COLUMNS) is still read:
 * its contact, address, item type and bank columns become the vendor's first
 * contact / address / category / bank request.
 *
 * parse() is pure: it validates the whole file and reports every problem, and
 * writes nothing. The controller saves in one transaction only when there are no errors.
 */
class VendorWorkbookImport
{
    public const SHEETS = [
        'vendors' => ['Vendors', ['Vendor Name*', 'Vendor Code', 'Legal Name', 'Vendor Type', 'Vendor Category', 'TIN', 'VAT/BIN', 'Business Reg No', 'Country', 'Currency', 'Payment Terms', 'Incoterm', 'Ship Mode', 'Tolerance %', 'Lead Time (days)', 'MOQ', 'Effective Date', 'Expiry Date', 'ERP Vendor Code', 'Remarks']],
        'contacts' => ['Contacts', ['Vendor Name*', 'Contact Name*', 'Designation', 'Email', 'Phone', 'Contact Type', 'Primary (Y/N)']],
        'addresses' => ['Addresses', ['Vendor Name*', 'Address Type', 'Address*', 'City', 'State', 'Postal Code', 'Country', 'Primary (Y/N)']],
        'categories' => ['Item Categories', ['Vendor Name*', 'Category*', 'Item Type', 'Lead Time (days)', 'MOQ', 'Tolerance %']],
        'banks' => ['Bank Accounts', ['Vendor Name*', 'Bank Name*', 'Branch', 'Account Name*', 'Account Number*', 'Routing Number', 'SWIFT', 'IBAN', 'Currency', 'Primary (Y/N)']],
    ];

    public const EXAMPLES = [
        'vendors' => ['EXAMPLE TRADING LTD', 'SUP-001', 'Example Trading Limited', 'Local', 'Trims', '123456789012', '000123456-0101', 'C-123456', 'Bangladesh', 'USD', '30 days', 'FOB', 'SEA', '3', '21', '500', '2026-01-01', '2027-12-31', 'ERP-0001', ''],
        'contacts' => ['EXAMPLE TRADING LTD', 'Rahim Uddin', 'Sales Manager', 'rahim@example.com', '+8801711000000', 'Sales', 'Y'],
        'addresses' => ['EXAMPLE TRADING LTD', 'Factory', '12 Station Road', 'Dhaka', '', '1000', 'Bangladesh', 'Y'],
        'categories' => ['EXAMPLE TRADING LTD', 'Zipper', 'Accessory', '14', '1000', '3'],
        'banks' => ['EXAMPLE TRADING LTD', 'Dutch-Bangla Bank', 'Motijheel', 'Example Trading Limited', '1234567890', '', 'DBBLBDDH', '', 'USD', 'Y'],
    ];

    public const EXAMPLE_PREFIX = 'example';

    /** Vendors sheet column -> [field, max length]. */
    private const VENDOR_FIELDS = [
        ['supplier_name', 255], ['supplier_code', 100], ['legal_name', 255], ['vendor_type', 20], ['vendor_category', 100],
        ['tin', 100], ['vat_bin_number', 100], ['business_reg_number', 100], ['country', 100], ['default_currency', 10],
        ['default_payment_terms', 150], ['incoterm', 50], ['ship_mode', 50], ['tolerance_percent', 20], ['lead_time_days', 10],
        ['moq', 30], ['effective_date', 30], ['expiry_date', 30], ['external_erp_code', 100], ['remarks', 2000],
    ];

    /**
     * @param  array<int, array<int, array<int, mixed>>>  $sheets  Excel::toArray() result (all sheets)
     * @return array{vendors: list<array<string, mixed>>, errors: list<string>, skipped: list<string>, bank_rows: int}
     */
    public static function parse(array $sheets, bool $mayImportBanks = false): array
    {
        $errors = [];
        $skipped = [];

        [$vendorRows, $children, $legacy] = self::sort($sheets);

        $names = Supplier::withTrashed()->pluck('supplier_name')->mapWithKeys(fn ($n) => [mb_strtolower(trim($n)) => true]);
        $codes = Supplier::withTrashed()->whereNotNull('supplier_code')->pluck('supplier_code')->mapWithKeys(fn ($c) => [mb_strtolower(trim($c)) => true]);
        $bankHashes = SupplierBankAccount::where('status', 'active')->pluck('id', 'account_number_hash');

        $vendors = [];
        $byKey = [];      // name / code (lower) -> index in $vendors
        $seenCodes = [];
        $bankRows = 0;

        foreach ($vendorRows as $line => $row) {
            $v = [];
            foreach ($legacy ? self::LEGACY_MAP : self::VENDOR_FIELDS as $i => $def) {
                if ($def === null) {
                    continue;
                }
                $v[$def[0]] = self::text($row[$i] ?? null, $def[1]);
            }

            $name = $v['supplier_name'] ?? null;
            $at = 'Vendors row ' . $line;

            if ($name === null) {
                $errors[] = $at . ': Vendor Name is required.';

                continue;
            }

            $key = mb_strtolower($name);

            if (str_starts_with($key, self::EXAMPLE_PREFIX)) {
                $skipped[] = $at . ': the template example row was ignored.';

                continue;
            }

            if (isset($names[$key])) {
                $skipped[] = $at . ': "' . $name . '" is already in the vendor list (edit it on its profile).';

                continue;
            }

            if (isset($byKey[$key])) {
                $skipped[] = $at . ': "' . $name . '" is listed more than once in this file.';

                continue;
            }

            self::checkVendor($v, $at, $errors);

            if ($v['supplier_code'] !== null) {
                $codeKey = mb_strtolower($v['supplier_code']);

                if (isset($codes[$codeKey]) || isset($seenCodes[$codeKey])) {
                    $errors[] = $at . ': Vendor Code "' . $v['supplier_code'] . '" is already used by another vendor.';
                }

                $seenCodes[$codeKey] = true;
            }

            $entry = ['vendor' => $v, 'contacts' => [], 'addresses' => [], 'categories' => [], 'banks' => []];

            if ($legacy) {
                [$entry, $bankSkipped] = self::legacyChildren($entry, $row, $at, $errors);
                $bankRows += count($entry['banks']);
            }

            $byKey[$key] = count($vendors);
            if ($v['supplier_code'] !== null) {
                $byKey[mb_strtolower($v['supplier_code'])] = count($vendors);
            }
            $vendors[] = $entry;
        }

        // Child sheets (new layout).
        foreach ($children as $kind => $rows) {
            foreach ($rows as $line => $row) {
                $label = self::SHEETS[$kind][0] . ' row ' . $line;
                $owner = self::text($row[0] ?? null, 255);

                if ($owner === null || str_starts_with(mb_strtolower($owner), self::EXAMPLE_PREFIX)) {
                    continue;
                }

                $idx = $byKey[mb_strtolower($owner)] ?? null;

                if ($idx === null) {
                    $errors[] = $label . ': "' . $owner . '" is not on the Vendors sheet of this file.';

                    continue;
                }

                $parsed = match ($kind) {
                    'contacts' => self::contactRow($row, $label, $errors),
                    'addresses' => self::addressRow($row, $label, $errors),
                    'categories' => self::categoryRow($row, $label, $errors),
                    'banks' => self::bankRow($row, $label, $errors, $bankHashes),
                };

                if ($parsed !== null) {
                    $vendors[$idx][$kind][] = $parsed;
                    $bankRows += $kind === 'banks' ? 1 : 0;
                }
            }
        }

        // Bank rows are only read for people allowed to raise bank changes.
        if (! $mayImportBanks && $bankRows > 0) {
            foreach ($vendors as &$entry) {
                $entry['banks'] = [];
            }
            unset($entry);
            $skipped[] = 'Bank account rows were not imported: you do not have permission to request bank changes.';
            $bankRows = 0;
        }

        // Same account number twice inside one file.
        $seen = [];
        foreach ($vendors as $entry) {
            foreach ($entry['banks'] as $b) {
                $h = SupplierBankAccount::hashNumber($b['account_number']);
                if (isset($seen[$h])) {
                    $errors[] = 'Bank Accounts: account ending ' . substr($b['account_number'], -4) . ' appears more than once in this file.';
                }
                $seen[$h] = true;
            }
        }

        return ['vendors' => $vendors, 'errors' => $errors, 'skipped' => $skipped, 'bank_rows' => $bankRows];
    }

    /** Old template column -> field (same order as VendorListImport::COLUMNS); null = handled as a child. */
    private const LEGACY_MAP = [
        ['supplier_name', 255], ['supplier_code', 100], ['legal_name', 255], null, null, null, null, null, ['country', 100],
        null, ['incoterm', 50], ['ship_mode', 50], ['tolerance_percent', 20],
    ];

    /**
     * Split the sheets into: vendor rows, child rows by kind, and whether the old layout was used.
     * Row numbers are the Excel line numbers (header = 1).
     *
     * @return array{0: array<int, array<int, mixed>>, 1: array<string, array<int, array<int, mixed>>>, 2: bool}
     */
    private static function sort(array $sheets): array
    {
        $vendorRows = [];
        $children = [];
        $legacy = false;

        foreach ($sheets as $rows) {
            $header = self::headerKey($rows[0] ?? []);
            $kind = null;

            if ($header[0] === 'vendor name' || $header[0] === 'supplier name') {
                $kind = ($header[3] ?? '') === 'contact person' ? 'legacy' : (($header[1] ?? '') === 'vendor code' ? 'vendors' : null);
            }

            if ($kind === null) {
                foreach (['contacts' => 'contact name', 'addresses' => 'address type', 'categories' => 'category', 'banks' => 'bank name'] as $k => $second) {
                    if (($header[1] ?? '') === $second) {
                        $kind = $k;
                    }
                }
            }

            if ($kind === null) {
                continue; // an instructions tab or anything else
            }

            $numbered = [];
            foreach ($rows as $i => $row) {
                if ($i === 0 || self::blank($row)) {
                    continue;
                }
                $numbered[$i + 1] = $row;
            }

            if ($kind === 'legacy') {
                $legacy = true;
                $vendorRows = $numbered;
            } elseif ($kind === 'vendors') {
                $vendorRows = $numbered;
            } else {
                $children[$kind] = $numbered;
            }
        }

        return [$vendorRows, $children, $legacy];
    }

    private static function headerKey(array $row): array
    {
        return array_map(fn ($c) => mb_strtolower(trim(rtrim(trim((string) $c), '*'))), $row);
    }

    private static function checkVendor(array &$v, string $at, array &$errors): void
    {
        $type = mb_strtolower((string) ($v['vendor_type'] ?? ''));
        $v['vendor_type'] = in_array($type, ['local', 'foreign'], true) ? $type : 'local';

        if ($type !== '' && ! in_array($type, ['local', 'foreign'], true)) {
            $errors[] = $at . ': Vendor Type must be Local or Foreign.';
        }

        if (($v['tolerance_percent'] ?? null) !== null) {
            $t = str_replace(['%', ' '], '', $v['tolerance_percent']);
            if (! is_numeric($t) || (float) $t < 0 || (float) $t > 100) {
                $errors[] = $at . ': Tolerance % must be a number from 0 to 100.';
            } else {
                $v['tolerance_percent'] = round((float) $t, 2);
            }
        }

        if (($v['lead_time_days'] ?? null) !== null) {
            if (! ctype_digit((string) $v['lead_time_days']) || (int) $v['lead_time_days'] > 999) {
                $errors[] = $at . ': Lead Time must be a whole number of days (0-999).';
            } else {
                $v['lead_time_days'] = (int) $v['lead_time_days'];
            }
        }

        if (($v['moq'] ?? null) !== null) {
            if (! is_numeric($v['moq']) || (float) $v['moq'] < 0) {
                $errors[] = $at . ': MOQ must be a number.';
            } else {
                $v['moq'] = (float) $v['moq'];
            }
        }

        foreach (['effective_date', 'expiry_date'] as $f) {
            if (($v[$f] ?? null) !== null) {
                $d = self::date($v[$f]);
                if ($d === null) {
                    $errors[] = $at . ': ' . ucwords(str_replace('_', ' ', $f)) . ' "' . $v[$f] . '" is not a valid date (use YYYY-MM-DD).';
                }
                $v[$f] = $d;
            }
        }

        if (($v['effective_date'] ?? null) && ($v['expiry_date'] ?? null) && $v['expiry_date'] < $v['effective_date']) {
            $errors[] = $at . ': Expiry Date is before Effective Date.';
        }
    }

    private static function contactRow(array $r, string $at, array &$errors): ?array
    {
        $name = self::text($r[1] ?? null, 255);
        $email = self::text($r[3] ?? null, 255);
        $type = mb_strtolower((string) self::text($r[5] ?? null, 30));

        if ($name === null) {
            $errors[] = $at . ': Contact Name is required.';

            return null;
        }
        if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = $at . ': "' . $email . '" is not a valid email address.';
        }
        if ($type !== '' && ! array_key_exists($type, SupplierContact::TYPES)) {
            $errors[] = $at . ': Contact Type must be one of ' . implode(', ', SupplierContact::TYPES) . '.';
        }

        return ['name' => $name, 'designation' => self::text($r[2] ?? null, 100), 'email' => $email, 'phone' => self::text($r[4] ?? null, 50),
            'contact_type' => array_key_exists($type, SupplierContact::TYPES) ? $type : 'general', 'is_primary' => self::yes($r[6] ?? null)];
    }

    private static function addressRow(array $r, string $at, array &$errors): ?array
    {
        $address = self::text($r[2] ?? null, 2000);
        $type = mb_strtolower((string) self::text($r[1] ?? null, 30));

        if ($address === null) {
            $errors[] = $at . ': Address is required.';

            return null;
        }
        if ($type !== '' && ! array_key_exists($type, SupplierAddress::TYPES)) {
            $errors[] = $at . ': Address Type must be one of ' . implode(', ', SupplierAddress::TYPES) . '.';
        }

        return ['address_type' => array_key_exists($type, SupplierAddress::TYPES) ? $type : 'office', 'address' => $address,
            'city' => self::text($r[3] ?? null, 100), 'state' => self::text($r[4] ?? null, 100), 'postal_code' => self::text($r[5] ?? null, 30),
            'country' => self::text($r[6] ?? null, 100), 'is_primary' => self::yes($r[7] ?? null)];
    }

    private static function categoryRow(array $r, string $at, array &$errors): ?array
    {
        $name = self::text($r[1] ?? null, 120);
        $type = mb_strtolower((string) self::text($r[2] ?? null, 30));

        if ($name === null) {
            $errors[] = $at . ': Category is required.';

            return null;
        }
        if ($type !== '' && ! array_key_exists($type, SupplierItemCategory::TYPES)) {
            $errors[] = $at . ': Item Type must be Material, Accessory or General.';
        }

        $lead = self::text($r[3] ?? null, 10);
        $moq = self::text($r[4] ?? null, 30);
        $tol = self::text($r[5] ?? null, 20);

        if ($lead !== null && (! ctype_digit($lead) || (int) $lead > 999)) {
            $errors[] = $at . ': Lead Time must be a whole number of days (0-999).';
        }
        if ($moq !== null && (! is_numeric($moq) || (float) $moq < 0)) {
            $errors[] = $at . ': MOQ must be a number.';
        }
        if ($tol !== null && (! is_numeric(str_replace('%', '', $tol)) || (float) str_replace('%', '', $tol) > 100 || (float) str_replace('%', '', $tol) < 0)) {
            $errors[] = $at . ': Tolerance % must be a number from 0 to 100.';
        }

        return ['category_name' => $name, 'item_type' => array_key_exists($type, SupplierItemCategory::TYPES) ? $type : 'material',
            'lead_time_days' => $lead !== null && ctype_digit($lead) ? (int) $lead : null, 'moq' => is_numeric($moq) ? (float) $moq : null,
            'tolerance_percent' => $tol !== null && is_numeric(str_replace('%', '', $tol)) ? round((float) str_replace('%', '', $tol), 2) : null];
    }

    private static function bankRow(array $r, string $at, array &$errors, $existingHashes): ?array
    {
        $number = preg_replace('/\s+/', '', (string) self::text($r[4] ?? null, 100));

        // An export masks the number ("****7890"); that is not a number to keep.
        if ($number !== '' && str_starts_with($number, '*')) {
            return null;
        }

        $bank = self::text($r[1] ?? null, 255);
        $accountName = self::text($r[3] ?? null, 255);

        foreach ([['Bank Name', $bank], ['Account Name', $accountName], ['Account Number', $number !== '' ? $number : null]] as [$label, $value]) {
            if ($value === null) {
                $errors[] = $at . ': ' . $label . ' is required.';

                return null;
            }
        }

        if (isset($existingHashes[SupplierBankAccount::hashNumber($number)])) {
            $errors[] = $at . ': account ending ' . substr($number, -4) . ' is already on a vendor.';
        }

        return ['bank_name' => $bank, 'bank_branch' => self::text($r[2] ?? null, 255), 'account_name' => $accountName, 'account_number' => $number,
            'routing_number' => self::text($r[5] ?? null, 100), 'swift_code' => self::text($r[6] ?? null, 50), 'iban' => self::text($r[7] ?? null, 50),
            'currency' => self::text($r[8] ?? null, 10), 'is_primary' => self::yes($r[9] ?? null)];
    }

    /**
     * Old single-sheet layout: contact person/email/phone (3-5), address/city (6-7),
     * item type (9), bank (13-18) become the vendor's first related rows.
     *
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private static function legacyChildren(array $entry, array $row, string $at, array &$errors): array
    {
        $contact = self::text($row[3] ?? null, 255);
        $email = self::text($row[4] ?? null, 255);
        $phone = self::text($row[5] ?? null, 50);

        if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = $at . ': "' . $email . '" is not a valid email address.';
        }

        if ($contact !== null || $email !== null || $phone !== null) {
            $entry['contacts'][] = ['name' => $contact ?? $entry['vendor']['supplier_name'], 'designation' => null, 'email' => $email, 'phone' => $phone, 'contact_type' => 'general', 'is_primary' => true];
        }

        // A city with no street still deserves an address row (the table needs some address text).
        if (($address = self::text($row[6] ?? null, 2000) ?? self::text($row[7] ?? null, 100)) !== null) {
            $entry['addresses'][] = ['address_type' => 'office', 'address' => $address, 'city' => self::text($row[7] ?? null, 100), 'state' => null, 'postal_code' => null, 'country' => self::text($row[8] ?? null, 100), 'is_primary' => true];
        }

        if (($type = self::text($row[9] ?? null, 100)) !== null) {
            $entry['categories'][] = ['category_name' => $type, 'item_type' => 'material', 'lead_time_days' => null, 'moq' => null, 'tolerance_percent' => $entry['vendor']['tolerance_percent'] ?? null];
        }

        $number = preg_replace('/\s+/', '', (string) self::text($row[16] ?? null, 100));
        if (self::text($row[13] ?? null, 255) !== null && $number !== '' && ! str_starts_with($number, '*')) {
            $entry['banks'][] = ['bank_name' => self::text($row[13], 255), 'bank_branch' => self::text($row[14] ?? null, 255), 'account_name' => self::text($row[15] ?? null, 255) ?? $entry['vendor']['supplier_name'],
                'account_number' => $number, 'routing_number' => self::text($row[17] ?? null, 100), 'swift_code' => self::text($row[18] ?? null, 50), 'iban' => null, 'currency' => null, 'is_primary' => true];
        }

        return [$entry, false];
    }

    private static function yes(mixed $v): bool
    {
        return in_array(mb_strtolower(trim((string) $v)), ['y', 'yes', '1', 'true', 'primary'], true);
    }

    private static function date(string $value): ?string
    {
        try {
            if (is_numeric($value) && (float) $value > 20000 && (float) $value < 80000) {
                return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value))->toDateString();
            }

            return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) ? Carbon::parse($value)->toDateString() : (preg_match('/^\d{1,2}[\/.-]\d{1,2}[\/.-]\d{4}$/', $value) ? Carbon::createFromFormat('d/m/Y', str_replace(['.', '-'], '/', $value))->toDateString() : null);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function blank(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private static function text(mixed $value, int $limit): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}
