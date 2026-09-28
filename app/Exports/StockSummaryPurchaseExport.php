<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The Stock Summary's Purchase View as one sheet: every filtered item, with
 * the totals row. Numbers, never formulas.
 */
class StockSummaryPurchaseExport implements WithEvents, WithTitle
{
    public const HEADINGS = ['SL', 'Item', 'UOM', 'Requested', 'Current Stock', 'Purchased', 'To Purchase', 'Status'];

    /** @param array<string, mixed> $data payload from StockSummaryService::build() */
    public function __construct(private readonly array $data)
    {
    }

    public function title(): string
    {
        return 'Purchase View';
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => fn (AfterSheet $event) => $this->write($event->sheet->getDelegate())];
    }

    private function write(Worksheet $sheet): void
    {
        $sheet->setCellValue('A1', config('stock.company_name', 'Humana Apparels Pvt. Ltd.'));
        $sheet->setCellValue('A2', 'Stock Summary — Purchase View ('.$this->data['period'].')');
        $sheet->setCellValue('A3', str_replace(' · '.$this->data['period'], '', $this->data['title']) === $this->data['period']
            ? '' : 'Filters: '.str_replace(' · '.$this->data['period'], '', $this->data['title']));

        foreach ([1, 2, 3] as $r) {
            $sheet->mergeCells("A{$r}:H{$r}");
            $sheet->getStyle("A{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2')->getFont()->setBold(true);
        $sheet->getStyle('A3')->getFont()->setItalic(true)->setSize(9);

        $sheet->fromArray(self::HEADINGS, null, 'A5');

        $r = 6;
        foreach ($this->data['purchase']['rows']->values() as $i => $row) {
            $sheet->fromArray([
                $i + 1,
                $row['item_name'],
                $row['uom'] ?: '—',
                round($row['requested'], 2),
                $row['stock_in_hand'] === null ? '—' : round($row['stock_in_hand'], 2),
                round($row['purchased'], 2),
                round($row['pending'], 2),
                $row['pending'] > 0 ? 'To Purchase' : ($row['over_received'] > 0 ? 'Over Received' : 'Purchased'),
            ], null, 'A'.$r, true);
            $r++;
        }

        $t = $this->data['purchase']['totals'];
        $sheet->fromArray(['', 'Total', '', round($t['requested'], 2), round($t['stock_in_hand'], 2), round($t['purchased'], 2), round($t['pending'], 2), ''], null, 'A'.$r, true);

        $sheet->getStyle("A5:H5")->getFont()->setBold(true);
        $sheet->getStyle("A5:H5")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8EEF7');
        $sheet->getStyle("A{$r}:H{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:H{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF4CE');
        $sheet->getStyle("A5:H{$r}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("D6:G{$r}")->getNumberFormat()->setFormatCode('#,##0.##');
        $sheet->getStyle("D5:G{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        foreach (['A' => 6, 'B' => 40, 'C' => 10, 'D' => 12, 'E' => 14, 'F' => 12, 'G' => 13, 'H' => 15] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        $sheet->freezePane('A6');
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(5, 5);
    }
}
