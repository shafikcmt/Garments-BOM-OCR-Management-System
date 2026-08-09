<?php

namespace App\Exports;

use App\Imports\ItemMasterImport;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The blank "Download Sample Template" workbook for the item-master import.
 *
 * Headings come straight from ItemMasterImport::COLUMNS rather than being
 * retyped here, so the template a user downloads always matches the columns the
 * importer actually reads.
 */
class ItemMasterTemplateExport implements FromArray, WithHeadings, WithTitle, WithStyles, ShouldAutoSize
{
    /** @return list<string> */
    public function headings(): array
    {
        return ItemMasterImport::COLUMNS;
    }

    /** @return array<int, array<int, mixed>> */
    public function array(): array
    {
        // One filled example row, so the expected format is obvious. Blank
        // Safety Stock / Re-order Level show that leaving them empty is allowed.
        return [ItemMasterImport::SAMPLE_ROW];
    }

    public function title(): string
    {
        return 'Item Master';
    }

    /** @return array<string, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        // The example row is greyed and italic so nobody mistakes it for data
        // they are supposed to keep.
        // Range spans every column in ItemMasterImport::COLUMNS.
        $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count(\App\Imports\ItemMasterImport::COLUMNS));
        $sheet->getStyle('A2:'.$last.'2')->getFont()->setItalic(true)->getColor()->setARGB('FF9AA0AE');

        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
