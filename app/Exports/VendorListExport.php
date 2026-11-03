<?php

namespace App\Exports;

use App\Imports\VendorListImport;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The vendor list as Excel, in the same columns the upload template uses, plus
 * Status. The account number is masked to its last four digits, as on the list
 * screen: a file leaves the system, so the full number stays on the edit form.
 */
class VendorListExport implements FromCollection, WithHeadings, WithTitle, WithStyles, ShouldAutoSize
{
    /** @param  Collection<int, \App\Models\Supplier>  $vendors */
    public function __construct(private Collection $vendors)
    {
    }

    /** @return list<string> */
    public function headings(): array
    {
        return array_merge(VendorListImport::COLUMNS, ['Status']);
    }

    public function collection(): Collection
    {
        return $this->vendors->map(fn ($v) => [
            $v->supplier_name, $v->supplier_code, $v->legal_name, $v->contact_person, $v->email, $v->phone, $v->address, $v->city, $v->country,
            $v->item_type, $v->incoterm, $v->ship_mode, $v->tolerance_percent !== null ? (float) $v->tolerance_percent : null,
            $v->bank_name, $v->bank_branch, $v->bank_account_name, $this->masked($v->bank_account_number), $v->bank_routing_number, $v->bank_swift_code,
            $v->is_active ? 'Active' : 'Inactive',
        ]);
    }

    public function title(): string
    {
        return 'Vendors';
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }

    private function masked(?string $number): ?string
    {
        $digits = preg_replace('/\s+/', '', (string) $number);

        return $digits === '' ? null : '****' . substr($digits, -4);
    }
}
