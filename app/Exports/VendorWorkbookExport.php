<?php

namespace App\Exports;

use App\Imports\VendorWorkbookImport as W;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Vendor workbook in the upload layout: Vendors, Contacts, Addresses, Item Categories
 * and (only when allowed) Bank Accounts. With no vendors it is the blank template
 * (example rows, plus a short Instructions sheet).
 *
 * Bank numbers are masked to the last four digits and routing / IBAN are left out:
 * a file leaves the system, so the full values stay on the approval screens.
 */
class VendorWorkbookExport implements WithMultipleSheets
{
    /** @param Collection<int, \App\Models\Supplier>|null $vendors null = blank template */
    public function __construct(private ?Collection $vendors = null, private bool $withBanks = false)
    {
    }

    public function sheets(): array
    {
        $template = $this->vendors === null;
        $sheets = [];

        foreach (W::SHEETS as $kind => [$title, $headings]) {
            if ($kind === 'banks' && ! $this->withBanks && ! $template) {
                continue;
            }

            $rows = $template ? [W::EXAMPLES[$kind]] : $this->rows($kind);
            $sheets[] = new VendorSheetExport($title, $kind === 'vendors' && ! $template ? array_merge($headings, ['Approval', 'Status']) : $headings, $rows, $template);
        }

        if ($template) {
            $sheets[] = new VendorSheetExport('Instructions', ['How to fill this workbook'], [
                ['1. Put each vendor ONCE on the Vendors sheet. Only Vendor Name is required.'],
                ['2. Contacts, Addresses, Item Categories and Bank Accounts: one row each, starting with the same Vendor Name as on the Vendors sheet.'],
                ['3. Mark the main contact / address / bank with Y in the Primary column; otherwise the first row is primary.'],
                ['4. Vendor Type: Local or Foreign. Dates: YYYY-MM-DD. Tolerance 0-100.'],
                ['5. A vendor already in the system is skipped, never overwritten. Vendor Codes must be unique.'],
                ['6. Imported vendors are DRAFTS until submitted and approved. Bank accounts become requests that another approver must accept.'],
                ['7. Delete the grey EXAMPLE rows (they are ignored anyway). If any row has a problem, nothing is imported.'],
            ]);
        }

        return $sheets;
    }

    /** @return array<int, array<int, mixed>> */
    private function rows(string $kind): array
    {
        $out = [];

        foreach ($this->vendors as $v) {
            $name = $v->supplier_name;

            switch ($kind) {
                case 'vendors':
                    $out[] = [$name, $v->supplier_code, $v->legal_name, ucfirst((string) $v->vendor_type), $v->vendor_category, $v->tin, $v->vat_bin_number, $v->business_reg_number,
                        $v->country, $v->default_currency, $v->default_payment_terms, $v->incoterm, $v->ship_mode, $v->tolerance_percent !== null ? (float) $v->tolerance_percent : null,
                        $v->lead_time_days, $v->moq !== null ? (float) $v->moq : null, $v->effective_date?->format('Y-m-d'), $v->expiry_date?->format('Y-m-d'),
                        $v->external_erp_code, $v->remarks, \App\Models\Supplier::APPROVALS[$v->approval_status] ?? $v->approval_status, \App\Models\Supplier::STATUSES[$v->vendor_status] ?? $v->vendor_status];
                    break;
                case 'contacts':
                    foreach ($v->contacts as $c) {
                        $out[] = [$name, $c->name, $c->designation, $c->email, $c->phone, \App\Models\SupplierContact::TYPES[$c->contact_type] ?? $c->contact_type, $c->is_primary ? 'Y' : 'N'];
                    }
                    break;
                case 'addresses':
                    foreach ($v->addresses as $a) {
                        $out[] = [$name, \App\Models\SupplierAddress::TYPES[$a->address_type] ?? $a->address_type, $a->address, $a->city, $a->state, $a->postal_code, $a->country, $a->is_primary ? 'Y' : 'N'];
                    }
                    break;
                case 'categories':
                    foreach ($v->itemCategories as $c) {
                        $out[] = [$name, $c->category_name, \App\Models\SupplierItemCategory::TYPES[$c->item_type] ?? $c->item_type, $c->lead_time_days,
                            $c->moq !== null ? (float) $c->moq : null, $c->tolerance_percent !== null ? (float) $c->tolerance_percent : null];
                    }
                    break;
                case 'banks':
                    foreach ($v->bankAccounts->where('status', 'active') as $b) {
                        $out[] = [$name, $b->bank_name, $b->bank_branch, $b->account_name, $b->maskedNumber(), null, $b->swift_code, null, $b->currency, $b->is_primary ? 'Y' : 'N'];
                    }
                    break;
            }
        }

        return $out;
    }
}
