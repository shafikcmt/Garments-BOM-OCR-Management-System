<?php

namespace App\Exports;

use App\Imports\IssueImport;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The blank "Download Sample Template" workbook for the issue import.
 *
 * Headings come straight from IssueImport::COLUMNS rather than being retyped
 * here, so the template a user downloads always matches the columns the
 * importer actually reads — the same arrangement the receiving and item-master
 * templates use.
 */
class IssueTemplateExport implements FromArray, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithStyles, WithTitle
{
    /**
     * How the Issue Date column is displayed — the same unambiguous format the
     * company's own Consumption workbook uses ("1/Aug/26"), chosen over a
     * numeric one because 01/08/26 reads as two different days depending on who
     * opens it.
     */
    public const DATE_FORMAT = '[$-409]d/mmm/yy;@';

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

        $first = IssueImport::SAMPLE_ROW;

        $second = $first;
        $second[$at('Item Name*')] = 'EXAMPLE — a second item on the SAME requisition';
        $second[$at('Issued Qty*')] = 2;
        $second[$at('Type')] = 'Replace';

        // Issue Date is written as a REAL Excel date — the serial number the
        // format in columnFormats() then displays — not as the text the
        // constant holds. Written as text, Excel treats what the user types
        // under it as text too, and the file comes back with dates that no
        // date function, sort or filter understands.
        //
        // The serial is produced here rather than in SAMPLE_ROW because that
        // constant is also the readable reference for the file's shape, and a
        // bare 46235 documents nothing.
        $dateColumn = $at('Issue Date*');
        $serial = ExcelDate::PHPToExcel(Carbon::parse(IssueImport::SAMPLE_ROW[$dateColumn])->startOfDay());

        $first[$dateColumn] = $serial;
        $second[$dateColumn] = $serial;

        // Month is derived from the date cell instead of being repeated as
        // text, so it cannot say Aug-26 next to a September date once the user
        // edits the row. It is display-only: IssueImport maps no heading to it
        // and never reads it.
        $monthColumn = $at('Month');
        $letter = self::dateColumnLetter();
        $first[$monthColumn] = '=TEXT('.$letter.'2,"MMM-YY")';
        $second[$monthColumn] = '=TEXT('.$letter.'3,"MMM-YY")';

        return [$first, $second];
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        // A range rather than the bare column, because Maatwebsite widens a
        // bare column only as far as the last written row — leaving the empty
        // rows the user is actually going to fill in unformatted.
        $letter = self::dateColumnLetter();

        return [$letter.'2:'.$letter.'1000' => self::DATE_FORMAT];
    }

    /**
     * The Issue Date column's letter, found by heading rather than assumed.
     *
     * Public because SkippedIssueRowsExport writes the same column, in the same
     * format, and the two must not drift apart — the skipped-rows file is
     * re-uploaded, so a date that lands there as text is the same bug this
     * class was fixed for.
     */
    public static function dateColumnLetter(): string
    {
        return Coordinate::stringFromColumnIndex(
            (int) array_search('Issue Date*', IssueImport::COLUMNS, true) + 1
        );
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
