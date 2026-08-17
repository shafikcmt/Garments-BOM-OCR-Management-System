<?php

namespace App\Exports;

use App\Imports\ItemMasterImport;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The blank "Download Sample Template" workbook for the item-master import.
 *
 * Headings come straight from ItemMasterImport::COLUMNS rather than being
 * retyped here, so the template a user downloads always matches the columns the
 * importer actually reads.
 */
class ItemMasterTemplateExport implements FromArray, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithStyles, WithTitle
{
    /** @return list<string> */
    public function headings(): array
    {
        return ItemMasterImport::COLUMNS;
    }

    /**
     * Unit Price is written and formatted as a NUMBER, not text.
     *
     * The example value in SAMPLE_ROW is a numeric literal, and the column
     * carries a number format so what the user types under it stays numeric
     * too. This is the same discipline the issue and receiving templates apply
     * to their date columns, for the same reason: a column that comes back as
     * text is a column no arithmetic, sort or filter understands, and the
     * importer would then have to guess at "12.50 " and "1,250".
     *
     * A range rather than the bare column — Maatwebsite widens a bare column
     * only as far as the last written row, leaving the empty rows the user is
     * actually going to fill in unformatted.
     *
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        $letter = self::columnLetter('Unit Price');

        return [$letter.'2:'.$letter.'1000' => NumberFormat::FORMAT_NUMBER_00];
    }

    /** A column's letter, found by heading rather than counted. */
    private static function columnLetter(string $heading): string
    {
        return Coordinate::stringFromColumnIndex(
            (int) array_search($heading, ItemMasterImport::COLUMNS, true) + 1
        );
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
        $last = Coordinate::stringFromColumnIndex(count(ItemMasterImport::COLUMNS));
        $sheet->getStyle('A2:'.$last.'2')->getFont()->setItalic(true)->getColor()->setARGB('FF9AA0AE');

        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
