<?php

namespace App\Exports;

use App\Imports\ReceivingImport;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The blank "Download Sample Template" workbook for the receiving import.
 *
 * Headings come straight from ReceivingImport::COLUMNS rather than being
 * retyped here, so the template a user downloads always matches the columns the
 * importer actually reads — the same arrangement the item-master template uses.
 *
 * The column order mirrors the company's own Consumable Stock Report "Purchase"
 * sheet, so anyone comparing the two sees the same layout.
 */
class ReceivingTemplateExport implements FromArray, WithHeadings, WithTitle, WithStyles, ShouldAutoSize
{
    /** @return list<string> */
    public function headings(): array
    {
        return ReceivingImport::COLUMNS;
    }

    /** @return array<int, array<int, mixed>> */
    public function array(): array
    {
        // Two example lines sharing one challan, because the single thing a
        // user has to understand about this file is that consecutive rows with
        // the same challan are ONE delivery.
        // Positions are found by heading rather than counted, so inserting a
        // column into ReceivingImport::COLUMNS cannot silently drop the second
        // example's quantity into the wrong cell — which is exactly what the
        // hardcoded indexes here did when Brand, Size and Specification were
        // added ahead of them.
        $at = fn (string $heading) => array_search($heading, ReceivingImport::COLUMNS, true);

        $second = ReceivingImport::SAMPLE_ROW;
        $second[$at('Item Name*')] = 'EXAMPLE — a second item on the SAME challan';
        $second[$at('Purchased Qty*')] = 5;
        $second[$at('Unit Price')] = 20;
        $second[$at('Total Value')] = 100;

        return [ReceivingImport::SAMPLE_ROW, $second];
    }

    public function title(): string
    {
        return 'Receiving';
    }

    /** @return array<string, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        // The example rows are greyed and italic so nobody mistakes them for
        // data they are supposed to keep.
        $last = Coordinate::stringFromColumnIndex(count(ReceivingImport::COLUMNS));

        $sheet->getStyle('A2:'.$last.'3')->getFont()->setItalic(true)->getColor()->setARGB('FF9AA0AE');

        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
