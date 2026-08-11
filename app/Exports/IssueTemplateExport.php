<?php

namespace App\Exports;

use App\Imports\IssueImport;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The blank "Download Sample Template" workbook for the issue import.
 *
 * Headings come straight from IssueImport::COLUMNS rather than being retyped
 * here, so the template a user downloads always matches the columns the
 * importer actually reads — the same arrangement the receiving and item-master
 * templates use.
 */
class IssueTemplateExport implements FromArray, WithHeadings, WithTitle, WithStyles, ShouldAutoSize
{
    /** @return list<string> */
    public function headings(): array
    {
        return IssueImport::COLUMNS;
    }

    /** @return array<int, array<int, mixed>> */
    public function array(): array
    {
        // Two example lines sharing one requisition, because the single thing
        // a user has to understand about this file is that rows sharing the
        // date, requisition number, section, person and approver are ONE issue.
        //
        // Positions are found by heading rather than counted: hardcoded indexes
        // here are what put a quantity under the wrong column when the
        // receiving template gained three columns.
        $at = fn (string $heading) => array_search($heading, IssueImport::COLUMNS, true);

        $second = IssueImport::SAMPLE_ROW;
        $second[$at('Item Name*')] = 'EXAMPLE — a second item on the SAME requisition';
        $second[$at('Issued Qty*')] = 2;
        $second[$at('Type')] = 'Replace';

        return [IssueImport::SAMPLE_ROW, $second];
    }

    public function title(): string
    {
        return 'Issues';
    }

    /** @return array<string, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        // The example rows are greyed and italic so nobody mistakes them for
        // data they are supposed to keep.
        $last = Coordinate::stringFromColumnIndex(count(IssueImport::COLUMNS));

        $sheet->getStyle('A2:'.$last.'3')->getFont()->setItalic(true)->getColor()->setARGB('FF9AA0AE');

        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
