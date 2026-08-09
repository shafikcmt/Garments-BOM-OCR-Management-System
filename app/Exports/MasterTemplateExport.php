<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Blank "Download Sample Template" workbook for the Indent Section, Indent
 * Person, Approved By and Category bulk uploads.
 *
 * One column, because MasterListImport only ever reads the first one. The
 * example row is worded so the importer discards it: "example" and "delete this
 * row" are both in MasterListImport::NOTE_PHRASES, so a user who fills the
 * template in without clearing that line does not import it as a real name.
 */
class MasterTemplateExport implements FromArray, WithHeadings, WithTitle, WithStyles, ShouldAutoSize
{
    public function __construct(
        private readonly string $label,
        private readonly string $sample,
    ) {
    }

    /** @return list<string> */
    public function headings(): array
    {
        return ['Name'];
    }

    /** @return array<int, array<int, mixed>> */
    public function array(): array
    {
        return [['Example — delete this row: '.$this->sample]];
    }

    public function title(): string
    {
        // Excel sheet titles cap at 31 characters and reject : \ / ? * [ ].
        return mb_substr(str_replace([':', '\\', '/', '?', '*', '[', ']'], '', $this->label), 0, 31);
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
