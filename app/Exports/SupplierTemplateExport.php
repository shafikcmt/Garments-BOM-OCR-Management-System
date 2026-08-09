<?php

namespace App\Exports;

use App\Imports\SupplierListImport;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Blank "Download Sample Template" workbook for the General Stock supplier
 * bulk upload. Headings come from SupplierListImport::COLUMNS, so the template
 * always matches the columns the importer reads.
 */
class SupplierTemplateExport implements FromArray, WithHeadings, WithTitle, WithStyles, ShouldAutoSize
{
    /** @return list<string> */
    public function headings(): array
    {
        return SupplierListImport::COLUMNS;
    }

    /** @return array<int, array<int, mixed>> */
    public function array(): array
    {
        return [SupplierListImport::SAMPLE_ROW];
    }

    public function title(): string
    {
        return 'Suppliers';
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        // Greyed italic example row, so nobody mistakes it for real data.
        $sheet->getStyle('A2')->getFont()->setItalic(true)->getColor()->setARGB('FF9AA0AE');

        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
