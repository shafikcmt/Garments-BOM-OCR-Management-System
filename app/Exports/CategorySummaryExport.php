<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

/**
 * Excel download of the category-wise requisition summary: the same figures as
 * the screen, laid out for a meeting — letterhead, a KPI strip, one table with
 * real numbers (so it can be sorted and summed) and a share / change column.
 */
class CategorySummaryExport implements FromArray, WithEvents, WithTitle
{
    use Exportable;

    private const NAVY = 'FF000B6F';

    private const GRID = 'FFC7D2FE';

    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    public function array(): array
    {
        return [];
    }

    public function title(): string
    {
        return 'Category Summary';
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => fn (AfterSheet $event) => $this->draw($event->sheet->getDelegate())];
    }

    private function draw(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
    {
        $d = $this->data;
        $totals = $d['totals'];
        $last = 'H';

        // Letterhead.
        $sheet->setCellValue('A1', 'Humana Apparels Pvt. Ltd.');
        $sheet->setCellValue('A2', 'Purchase Requisition — Category Summary — '.$d['monthLabel']);
        $sheet->mergeCells('A1:H1')->mergeCells('A2:H2');
        $sheet->getStyle('A1')->applyFromArray(['font' => ['bold' => true, 'size' => 18], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER]]);
        $sheet->getStyle('A2')->applyFromArray(['font' => ['size' => 11, 'color' => ['argb' => 'FF475569']]]);
        $sheet->getRowDimension(1)->setRowHeight(28);
        $sheet->getRowDimension(2)->setRowHeight(18);

        // KPI strip: label above, figure below, four tiles of two columns each.
        $kpis = [
            ['Requisitions', (int) $totals['requisitions'], '#,##0'],
            ['Distinct Items', (int) $totals['items'], '#,##0'],
            ['To Be Procured', (float) $totals['to_be_procured'], '#,##0'],
            ['Total Amount', (float) $totals['amount'], '#,##0.00'],
        ];
        foreach ($kpis as $i => [$label, $value, $format]) {
            $from = chr(ord('A') + $i * 2);
            $to = chr(ord('A') + $i * 2 + 1);
            $sheet->setCellValue($from.'4', $label)->setCellValue($from.'5', $value);
            $sheet->mergeCells($from.'4:'.$to.'4')->mergeCells($from.'5:'.$to.'5');
            $sheet->getStyle($from.'4:'.$to.'5')->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF1F5F9']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFE2E8F0']]],
            ]);
            $sheet->getStyle($from.'4')->getFont()->setSize(9)->setBold(true)->getColor()->setARGB('FF64748B');
            $sheet->getStyle($from.'5')->getFont()->setSize(15)->setBold(true);
            $sheet->getStyle($from.'5')->getNumberFormat()->setFormatCode($format);
        }
        $sheet->getRowDimension(4)->setRowHeight(18);
        $sheet->getRowDimension(5)->setRowHeight(26);

        // Table header.
        $head = 7;
        foreach (['#', 'Category', 'Items', 'Qty Requested', 'To Be Procured', 'Amount', 'Share of Spend', 'vs '.$d['previousLabel']] as $i => $text) {
            $sheet->setCellValue(chr(ord('A') + $i).$head, $text);
        }
        $sheet->getStyle('A'.$head.':'.$last.$head)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::NAVY]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getRowDimension($head)->setRowHeight(30);

        // Body: real numbers, formatted by the cell.
        $row = $head;
        foreach ($d['categories'] as $i => $c) {
            $row++;
            $sheet->setCellValue('A'.$row, $i + 1)
                ->setCellValue('B'.$row, $c['name'])
                ->setCellValue('C'.$row, (int) $c['items'])
                ->setCellValue('D'.$row, (float) $c['qty_requested'])
                ->setCellValue('E'.$row, (float) $c['to_be_procured'])
                ->setCellValue('F'.$row, (float) $c['amount'])
                ->setCellValue('G'.$row, $c['share'] / 100)
                ->setCellValue('H'.$row, $c['change'] === null ? '—' : $c['change'] / 100);
            if ($c['change'] !== null) {
                // Green when spend fell, red when it rose: the same reading as the screen.
                $sheet->getStyle('H'.$row)->getFont()->getColor()->setARGB($c['change'] > 0 ? 'FFB91C1C' : 'FF15803D');
            }
            if ($i % 2 === 1) {
                $sheet->getStyle('A'.$row.':'.$last.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8FAFF');
            }
        }
        $firstBody = $head + 1;
        $lastBody = $row;

        // Total row.
        $row++;
        if ($lastBody >= $firstBody) {
            $sheet->setCellValue('B'.$row, 'Total');
            // Plain values, not =SUM(): Excel's Protected View (downloaded files)
            // does not calculate formulas and would show 0.
            $sheet->setCellValue('C'.$row, (int) $totals['items'])
                ->setCellValue('D'.$row, (float) $totals['qty_requested'])
                ->setCellValue('E'.$row, (float) $totals['to_be_procured'])
                ->setCellValue('F'.$row, (float) $totals['amount'])
                ->setCellValue('G'.$row, 1);
            $before = (float) $d['previous']['amount'];
            $sheet->setCellValue('H'.$row, $before > 0 ? ((float) $totals['amount'] - $before) / $before : '—');
            $sheet->getStyle('A'.$row.':'.$last.$row)->applyFromArray([
                'font' => ['bold' => true, 'size' => 11],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEEF2FF']],
                'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => self::NAVY]]],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(22);
        } else {
            $sheet->setCellValue('B'.$row, 'No counted requisitions in '.$d['monthLabel'].'.');
        }

        // Formats and alignment.
        $sheet->getStyle('A'.$firstBody.':'.$last.$row)->getFont()->setSize(10);
        $sheet->getStyle('C'.$firstBody.':C'.$row)->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle('D'.$firstBody.':E'.$row)->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle('F'.$firstBody.':F'.$row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('G'.$firstBody.':H'.$row)->getNumberFormat()->setFormatCode('0.0%');
        $sheet->getStyle('A'.$firstBody.':A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('C'.$firstBody.':H'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('A'.$head.':'.$last.($lastBody >= $firstBody ? $row - 1 : $head))->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => self::GRID]]],
        ]);

        // Footnote.
        $note = $row + 2;
        $sheet->setCellValue('A'.$note, 'Counted requisitions only (drafts and sheets still with a head are not included). To Be Procured = Qty − Stock in Hand + Safety Stock, stock counted once per item. Red = more spend than '.$d['previousLabel'].', green = less.');
        $sheet->mergeCells('A'.$note.':H'.($note + 1));
        $sheet->getStyle('A'.$note)->applyFromArray(['font' => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF64748B']], 'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_TOP]]);

        // Column widths, page setup.
        foreach (['A' => 6, 'B' => 34, 'C' => 11, 'D' => 15, 'E' => 16, 'F' => 18, 'G' => 15, 'H' => 18] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->setShowGridlines(false);
        $sheet->freezePane('A'.$firstBody);
        $setup = $sheet->getPageSetup();
        $setup->setPaperSize(PageSetup::PAPERSIZE_A4)->setOrientation(PageSetup::ORIENTATION_PORTRAIT)->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageSetup()->setFitToPage(true);
        $sheet->getPageMargins()->setLeft(0.4)->setRight(0.4)->setTop(0.5)->setBottom(0.5);
    }
}
