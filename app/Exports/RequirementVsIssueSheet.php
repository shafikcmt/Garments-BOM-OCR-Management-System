<?php

namespace App\Exports;

use App\Services\RequirementVsIssueService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

/**
 * One sheet of the Requirement vs Issue workbook.
 *
 *   1  Company name
 *   2  Purchase Requisition vs Issue (Month of August 2026)
 *   3  Dept. of CAD & Sample
 *   4  Applied filters (when any)
 *   5  Totals — above the header, as the reference file has it
 *   6  Column header
 *   7+ Rows, then the totals once more
 *
 * Every figure is written as a number, never a formula, so nothing breaks
 * when the file is copied or re-sorted. A blank value is written as "—".
 */
class RequirementVsIssueSheet implements WithEvents, WithTitle
{
    public const HEADINGS = [
        'SL', 'Item Name', 'UOM', 'Specification / Brand', 'User Dept./Section', 'Approx Rate',
        'Requested Qty', 'Requested Value (BDT)', 'Issued Qty', 'Issued Value (BDT)',
        'Issue Bal. Qty', 'Issue Bal. Value', 'Issued %', 'Status',
    ];

    private const WIDTHS = [6, 34, 8, 26, 22, 11, 12, 15, 11, 15, 12, 15, 10, 14];

    public function __construct(
        private readonly array $data,
        private readonly string $sheetTitle,
        private readonly string $sectionName,
        private readonly Collection $rows,
    ) {
    }

    public function title(): string
    {
        return $this->sheetTitle;
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => fn (AfterSheet $event) => $this->write($event->sheet->getDelegate())];
    }

    private function write(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
    {
        $last = Coordinate::stringFromColumnIndex(count(self::HEADINGS));
        $summary = app(RequirementVsIssueService::class)->summary($this->rows);

        $sheet->setCellValue('A1', config('stock.company_name', 'Humana Apparels Pvt. Ltd.'));
        $sheet->setCellValue('A2', 'Purchase Requisition vs Issue ('.$this->data['period'].')');
        $sheet->setCellValue('A3', 'Dept. of '.$this->sectionName);
        $filtersLine = str_replace(' · '.$this->data['period'], '', $this->data['title']);
        $sheet->setCellValue('A4', $filtersLine === $this->data['period'] ? '' : 'Filters: '.$filtersLine);

        foreach ([1, 2, 3, 4] as $r) {
            $sheet->mergeCells("A{$r}:{$last}{$r}");
            $sheet->getStyle("A{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2:A3')->getFont()->setBold(true)->setSize(11);
        $sheet->getStyle('A4')->getFont()->setItalic(true)->setSize(9);

        $this->totalsRow($sheet, 5, $summary);
        $sheet->fromArray(self::HEADINGS, null, 'A6');

        $r = 7;
        foreach ($this->rows->values() as $i => $row) {
            $sheet->fromArray([
                $i + 1,
                $row['item_name'],
                $row['uom'] ?? '—',
                $row['specification'] ?? '—',
                $row['section_name'],
                $row['rate'] === null ? '—' : round($row['rate'], 2),
                round($row['requested_qty'], 2),
                $row['requested_value'] === null ? '—' : round($row['requested_value'], 2),
                round($row['issued_qty'], 2),
                $row['issued_value'] === null ? '—' : round($row['issued_value'], 2),
                round($row['balance_qty'], 2),
                $row['balance_value'] === null ? '—' : round($row['balance_value'], 2),
                $row['pct'] === null ? '—' : round($row['pct'] / 100, 4),
                $row['status_label'],
            ], null, 'A'.$r, true);

            if ($row['balance_qty'] < 0) {
                $sheet->getStyle("K{$r}:L{$r}")->getFont()->getColor()->setRGB('C62828');
            }
            $r++;
        }

        if ($this->rows->isEmpty()) {
            $sheet->setCellValue('A'.$r, 'No matching items');
            $sheet->mergeCells("A{$r}:{$last}{$r}");
            $r++;
        }

        $this->totalsRow($sheet, $r, $summary);
        $end = $r;

        if ($summary['not_requested_qty'] > 0) {
            $r += 2;
            $sheet->setCellValue('B'.$r, 'Not Requested (issued without a requisition) — not in the totals or Issued %');
            $sheet->setCellValue('I'.$r, round($summary['not_requested_qty'], 2));
            $sheet->setCellValue('J'.$r, round($summary['not_requested_value'], 2));
            $sheet->getStyle("I{$r}:J{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle("B{$r}:J{$r}")->getFont()->setItalic(true);
        }

        // Styling
        $sheet->getStyle("A5:{$last}6")->getFont()->setBold(true);
        $sheet->getStyle("A6:{$last}6")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8EEF7');
        $sheet->getStyle("A6:{$last}6")->getAlignment()->setWrapText(true)->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("A{$end}:{$last}{$end}")->getFont()->setBold(true);
        $sheet->getStyle("A5:{$last}{$end}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("F5:L{$end}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("M5:M{$end}")->getNumberFormat()->setFormatCode('0.0%');
        $sheet->getStyle("F5:M{$end}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("A7:A{$end}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("B7:E{$end}")->getAlignment()->setWrapText(true);

        foreach (self::WIDTHS as $i => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth($width);
        }

        $sheet->freezePane('A7');
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(6, 6);
    }

    private function totalsRow(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $r, array $summary): void
    {
        $sheet->fromArray([
            '', 'Total', '', '', '', '',
            round($summary['requested_qty'], 2),
            round($summary['requested_value'], 2),
            round($summary['issued_qty'], 2),
            round($summary['issued_value'], 2),
            round($summary['balance_qty'], 2),
            round($summary['balance_value'], 2),
            $summary['pct'] === null ? '—' : round($summary['pct'] / 100, 4),
            '',
        ], null, 'A'.$r, true);

        $sheet->getStyle("A{$r}:N{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF4CE');
    }
}
