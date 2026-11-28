<?php

namespace App\Services;

use App\Models\GsPurchaseOrder;
use App\Support\AmountInWords;
use App\Support\PurchasingSettings;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The Internal PO as an .xlsx, laid out like the printed PO (same boxes, same order, same
 * numbers). Eight columns A:H: the item table uses all of them; the header boxes split
 * them into a left half (A:D) and a right half (E:H).
 */
class PurchaseOrderExcel
{
    private const GREY = 'F3F4F6';

    private const HEAD = 'E5E7EB';

    public function build(GsPurchaseOrder $order): Spreadsheet
    {
        $order->loadMissing(['items', 'checkedBy:id,name', 'authorisedBy:id,name']);
        $bin = PurchasingSettings::poDefaults()['bin_number'] ?: $order->bin_number;
        $signs = PurchasingSettings::poSignLabels();

        $book = new Spreadsheet();
        $ws = $book->getActiveSheet();
        $ws->setTitle('Purchase Order');

        foreach (['A' => 5, 'B' => 38, 'C' => 11, 'D' => 11, 'E' => 12, 'F' => 9, 'G' => 14, 'H' => 17] as $col => $width) {
            $ws->getColumnDimension($col)->setWidth($width);
        }

        // Title
        $ws->mergeCells('A1:H1');
        $ws->setCellValue('A1', 'PURCHASE ORDER');
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $ws->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $ws->getRowDimension(1)->setRowHeight(28);

        // Letterhead: company + address on the left, PO details on the right.
        $this->block($ws, 'A3:D5', "Humana Apparels Pvt. Ltd\n".trim((string) $order->invoice_to), true);
        $ws->getStyle('A3')->getFont()->setBold(true);
        $this->pair($ws, 3, 'PO No.', (string) $order->po_no, 'Date', (string) $order->po_date?->format('d-M-Y'));
        $this->pair($ws, 4, 'Reference No.', (string) ($order->reference_no ?: '-'), 'BIN', (string) $bin);
        $this->pair($ws, 5, 'Dispatched through', (string) ($order->dispatched_through ?: '-'), 'Destination', (string) ($order->destination ?: '-'));
        foreach ([3, 4, 5] as $r) {
            $ws->getRowDimension($r)->setRowHeight(26);
        }

        $this->label($ws, 'A6:D6', 'CONSIGNEE (SHIP TO)');
        $this->block($ws, 'A7:D7', trim((string) $order->consignee));
        $ws->getRowDimension(7)->setRowHeight(32);

        // Supplier and payment terms
        $this->label($ws, 'A9:D9', 'SUPPLIER (BILL FROM)');
        $this->label($ws, 'E9:H9', 'MODE / TERMS OF PAYMENT');
        $supplier = trim($order->supplier_name."\n".trim((string) $order->supplier_address)
            .(filled($order->supplier_phone) ? "\nCall: ".$order->supplier_phone : ''));
        $this->block($ws, 'A10:D10', $supplier);
        $this->block($ws, 'E10:H10', trim((string) $order->payment_terms));
        $ws->getStyle('A10')->getFont()->setBold(false);
        $ws->getRowDimension(10)->setRowHeight(64);

        // Terms of delivery
        $this->label($ws, 'A12:A12', 'TERMS OF DELIVERY');
        $ws->mergeCells('A12:B12');
        $this->border($ws, 'A12:B12');
        $this->block($ws, 'C12:H12', trim((string) $order->delivery_terms));
        $ws->getRowDimension(12)->setRowHeight(34);

        // Items
        $row = 14;
        foreach (['SL', 'Item Description of Goods', 'Due on', 'Qty', 'Rate', 'Per', 'Amount', 'Remarks'] as $i => $head) {
            $ws->setCellValue([$i + 1, $row], $head);
        }
        $ws->getStyle("A{$row}:H{$row}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::HEAD]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $first = $row + 1;
        foreach ($order->items as $n => $line) {
            $row++;
            $ws->setCellValue("A{$row}", $n + 1);
            $ws->setCellValue("B{$row}", (string) $line->item_name);
            $ws->setCellValue("C{$row}", (string) $line->due_on);
            $ws->setCellValue("D{$row}", (float) $line->qty);
            $ws->setCellValue("E{$row}", (float) $line->rate);
            $ws->setCellValueExplicit("F{$row}", strtoupper((string) $line->uom), DataType::TYPE_STRING);
            $ws->setCellValue("G{$row}", (float) $line->amount);
            $ws->setCellValue("H{$row}", (string) $line->remarks);
            $ws->getStyle("B{$row}")->getAlignment()->setWrapText(true);
        }

        // At least 10 table rows, like the printed PO, so a remark can be added by hand.
        while ($row - $first + 1 < 10) {
            $row++;
        }
        $last = $row;

        $ws->getStyle("A{$first}:A{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->getStyle("C{$first}:C{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->getStyle("F{$first}:F{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->getStyle("D{$first}:D{$last}")->getNumberFormat()->setFormatCode('#,##0.####');
        $ws->getStyle("E{$first}:E{$last}")->getNumberFormat()->setFormatCode('#,##0.00');
        $ws->getStyle("G{$first}:G{$last}")->getNumberFormat()->setFormatCode('#,##0.00');

        $row++;
        $ws->mergeCells("A{$row}:F{$row}");
        $ws->setCellValue("A{$row}", 'TOTAL (Tk)');
        $ws->setCellValue("G{$row}", (float) $order->total_amount);
        $ws->getStyle("G{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        $ws->getStyle("A{$row}:H{$row}")->getFont()->setBold(true);
        $ws->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $ws->getStyle("A{$row}:H{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::HEAD);
        $this->border($ws, "A14:H{$row}");

        // Amount in words
        $row += 2;
        $ws->mergeCells("A{$row}:H{$row}");
        $ws->setCellValue("A{$row}", 'Amount Chargeable (in words): '.AmountInWords::taka($order->total_amount));
        $ws->getStyle("A{$row}")->getFont()->setBold(true);
        $ws->getStyle("A{$row}")->getAlignment()->setWrapText(true);
        $ws->getRowDimension($row)->setRowHeight(28);
        $this->border($ws, "A{$row}:H{$row}");

        // Remarks, numbered
        $row += 2;
        $this->label($ws, "A{$row}:H{$row}", 'REMARKS');
        $remarks = array_values(array_filter(preg_split('/\r\n|\r|\n/', (string) $order->remarks), fn ($l) => trim($l) !== ''));
        $text = collect($remarks)->map(fn ($r, $i) => ($i + 1).'. '.trim($r))->implode("\n");
        $row++;
        $this->block($ws, "A{$row}:H{$row}", $text);
        $ws->getRowDimension($row)->setRowHeight(max(34, 15 * max(1, count($remarks)) + 6));

        // Signatures
        $row += 3;
        $ws->mergeCells("A{$row}:D{$row}");
        $ws->mergeCells("E{$row}:H{$row}");
        $ws->setCellValue("A{$row}", trim(($order->checkedBy?->name ?? '').' '.($order->checked_at?->format('d-m-Y') ?? '')));
        $ws->setCellValue("E{$row}", trim(($order->authorisedBy?->name ?? '').' '.($order->authorised_at?->format('d-m-Y') ?? '')));
        $row++;
        $ws->mergeCells("A{$row}:D{$row}");
        $ws->mergeCells("E{$row}:H{$row}");
        $ws->setCellValue("A{$row}", $signs['checked']."\nFor Humana Apparels Pvt Ltd.");
        $ws->setCellValue("E{$row}", $signs['authorised']."\nFor Humana Apparels Pvt Ltd.");
        $ws->getStyle("A{$row}:H{$row}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $ws->getStyle("A{$row}:H{$row}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        $ws->getStyle("E{$row}:H{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $ws->getRowDimension($row)->setRowHeight(30);

        // A4 portrait, one page wide.
        $ws->getPageSetup()->setOrientation(PageSetup::ORIENTATION_PORTRAIT)->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)->setFitToHeight(0);
        $ws->setShowGridlines(false);
        $ws->getPageMargins()->setTop(0.4)->setBottom(0.4)->setLeft(0.4)->setRight(0.4);

        return $book;
    }

    /** A merged, bordered, wrapped text box. */
    private function block(Worksheet $ws, string $range, string $text, bool $topLeft = false): void
    {
        $ws->mergeCells($range);
        $start = explode(':', $range)[0];
        $ws->setCellValue($start, $text);
        $ws->getStyle($range)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $this->border($ws, $range);
    }

    /** A grey caption bar. */
    private function label(Worksheet $ws, string $range, string $text): void
    {
        if (str_contains($range, ':') && explode(':', $range)[0] !== explode(':', $range)[1]) {
            $ws->mergeCells($range);
        }
        $start = explode(':', $range)[0];
        $ws->setCellValue($start, $text);
        $ws->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'size' => 9],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::GREY]],
        ]);
        $this->border($ws, $range);
    }

    /** Two caption/value pairs on one row of the right half (E:H). */
    private function pair(Worksheet $ws, int $row, string $k1, string $v1, string $k2, string $v2): void
    {
        $ws->setCellValue("E{$row}", $k1);
        $ws->setCellValue("F{$row}", $v1);
        $ws->setCellValue("G{$row}", $k2);
        $ws->setCellValue("H{$row}", $v2);
        $ws->getStyle("E{$row}:H{$row}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        foreach (["E{$row}", "G{$row}"] as $cell) {
            $ws->getStyle($cell)->applyFromArray([
                'font' => ['size' => 8, 'color' => ['rgb' => '6B7280']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::GREY]],
            ]);
        }
        foreach (["F{$row}", "H{$row}"] as $cell) {
            $ws->getStyle($cell)->getFont()->setBold(true);
        }
        $this->border($ws, "E{$row}:H{$row}");
    }

    /** Thin border on every cell of the range; set on each cell so merged boxes keep their edges in every viewer. */
    private function border(Worksheet $ws, string $range): void
    {
        $ws->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('9CA3AF');
    }
}
