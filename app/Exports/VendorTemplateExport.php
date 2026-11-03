<?php

namespace App\Exports;

use App\Imports\VendorListImport;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Blank upload template for the vendor list; headings come from VendorListImport::COLUMNS. */
class VendorTemplateExport implements FromArray, WithHeadings, WithTitle, WithStyles, ShouldAutoSize
{
    /** @return list<string> */
    public function headings(): array
    {
        return VendorListImport::COLUMNS;
    }

    /** @return array<int, array<int, mixed>> */
    public function array(): array
    {
        return [VendorListImport::SAMPLE_ROW];
    }

    public function title(): string
    {
        return 'Vendors';
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        // Greyed italic example row, so nobody mistakes it for real data.
        $sheet->getStyle('A2:S2')->getFont()->setItalic(true)->getColor()->setARGB('FF9AA0AE');

        return [1 => ['font' => ['bold' => true]]];
    }
}
