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
 * The rows an issue import could not take, as a workbook to fix and upload
 * again.
 *
 * The problem this solves is arithmetic: a 754-row file with 39 unusable rows
 * used to mean reading 754 rows against an on-screen list to find them. This
 * hands back just those rows, in the shape the importer reads, so the second
 * upload is a small file rather than a search.
 *
 * IT IS THE TEMPLATE PLUS ONE COLUMN. Headings come from IssueImport::COLUMNS
 * for the same reason IssueTemplateExport takes them from there — a file the
 * user is going to re-upload must carry the headings the importer accepts — and
 * REASON_COLUMN is appended on the end. Appended, not inserted: the importer
 * maps headings by name and ignores ones it does not know, so the extra column
 * rides along on the re-upload and is simply not read. The user does not have
 * to delete it before uploading, which they would otherwise forget to do.
 *
 * Dates are written the way IssueTemplateExport writes them — a real Excel
 * serial under IssueTemplateExport::DATE_FORMAT, both borrowed from that class
 * rather than restated here. Writing them as text is the specific bug that made
 * dates unreadable on re-upload once already, and this file exists to BE
 * re-uploaded, so it is the last place to repeat it.
 */
class SkippedIssueRowsExport implements FromArray, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithStyles, WithTitle
{
    /**
     * The one column this file has that the template does not: why the row is
     * in this file at all, so the user need not cross-reference the screen.
     */
    public const REASON_COLUMN = 'Reason Skipped';

    /**
     * @param  list<array<string, mixed>>  $rows  as returned in IssueImport::parse()['skipped_rows']
     */
    public function __construct(private readonly array $rows) {}

    /** @return list<string> */
    public function headings(): array
    {
        return [...IssueImport::COLUMNS, self::REASON_COLUMN];
    }

    /** @return array<int, array<int, mixed>> */
    public function array(): array
    {
        $dateColumn = (int) array_search('Issue Date*', IssueImport::COLUMNS, true);
        $monthColumn = (int) array_search('Month', IssueImport::COLUMNS, true);
        $letter = IssueTemplateExport::dateColumnLetter();

        $out = [];

        foreach ($this->rows as $offset => $row) {
            $values = $row['values'];

            // A date the importer understood is rewritten as a real Excel date.
            // One it could NOT understand is left exactly as the user typed it:
            // that cell is the thing they have to look at and correct, and
            // replacing it with a blank or a guess hides the fault.
            if (! empty($row['date'])) {
                $values[$dateColumn] = ExcelDate::PHPToExcel(Carbon::parse($row['date'])->startOfDay());

                // Derived from the date cell, exactly as the template does it,
                // so it cannot contradict the date beside it after an edit.
                // +2: one for the heading row, one for 1-based rows.
                $values[$monthColumn] = '=TEXT('.$letter.($offset + 2).',"MMM-YY")';
            }

            $values[] = $row['reason'];

            $out[] = $values;
        }

        return $out;
    }

    /** @return array<string, string> */
    public function columnFormats(): array
    {
        // Only as far as the rows written, unlike the blank template: there are
        // no empty rows below for the user to fill in here.
        $letter = IssueTemplateExport::dateColumnLetter();
        $last = count($this->rows) + 1;

        return $last > 1
            ? [$letter.'2:'.$letter.$last => IssueTemplateExport::DATE_FORMAT]
            : [];
    }

    public function title(): string
    {
        return 'Skipped Rows';
    }

    /** @return array<int, array<string, mixed>> */
    public function styles(Worksheet $sheet): array
    {
        // The Reason column is the one the user is here to read, so it is the
        // one that is allowed to wrap rather than run off the page.
        $reason = Coordinate::stringFromColumnIndex(count(IssueImport::COLUMNS) + 1);

        $sheet->getStyle($reason)->getAlignment()->setWrapText(true);
        $sheet->getColumnDimension($reason)->setAutoSize(false)->setWidth(52);

        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
