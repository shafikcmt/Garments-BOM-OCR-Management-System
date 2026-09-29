<?php

namespace App\Exports;

use App\Imports\UnitPriceImport;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The blank workbook for the Unit Price import.
 *
 * Headings come from UnitPriceImport::COLUMNS rather than being retyped, so the
 * template always matches the columns the importer reads.
 *
 * Most people will not start here: the Item Master export already carries every
 * item's name and brand spelled exactly as stored, and deleting its other
 * columns is less work than retyping 1,241 names. This is for the short,
 * hand-built file — a dozen prices after a supplier quote.
 */
class UnitPriceTemplateExport implements FromArray, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithStyles, WithTitle
{
    /** @return list<string> */
    public function headings(): array
    {
        return UnitPriceImport::COLUMNS;
    }

    /** @return array<int, array<int, mixed>> */
    public function array(): array
    {
        return [UnitPriceImport::SAMPLE_ROW];
    }

    /** @return array<string, string> */
    public function columnFormats(): array
    {
        // Written and formatted as a number, so what the user types under the
        // example stays numeric instead of becoming text the importer has to
        // guess at.
        $letter = Coordinate::stringFromColumnIndex(
            (int) array_search('Unit Price*', UnitPriceImport::COLUMNS, true) + 1
        );

        return [$letter.'2:'.$letter.'1000' => \App\Support\Quantity::EXCEL_MONEY];
    }

    public function title(): string
    {
        return 'Unit Prices';
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        $last = Coordinate::stringFromColumnIndex(count(UnitPriceImport::COLUMNS));

        // Greyed and italic so nobody mistakes the example for data to keep.
        $sheet->getStyle('A2:'.$last.'2')->getFont()->setItalic(true)->getColor()->setARGB('FF9AA0AE');

        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
