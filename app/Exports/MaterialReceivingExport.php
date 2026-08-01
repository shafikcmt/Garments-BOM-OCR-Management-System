<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel export of the Receiving History (MRR) register. Renders a blade that
 * carries the same 25 columns as the PDF, so download and preview can never
 * drift apart (same approach as BulkIssueExport / StoreReportExport).
 *
 * Sheet layout, top to bottom:
 *
 *   1  Title            (merged across all columns)
 *   2  Subtitle         (record count + generated timestamp)
 *   3  spacer
 *   4  Column header    (frozen, repeated on every printed page, auto-filtered)
 *   5+ Data
 *      3 blank rows
 *      Signature line   (merged blocks with a bottom border)
 *      Signature labels (merged blocks, bold)
 *
 * Deliberately NOT ShouldAutoSize:
 *
 *  - The shared partial emits no inline <th> width in excel mode any more,
 *    because PhpSpreadsheet's HTML reader turns an inline width into an
 *    EXPLICIT column width and disables that column's auto-size. "width:4%"
 *    was landing as a 0.34-character column, which is what made the sheet look
 *    cut and overlapped even though ShouldAutoSize was declared.
 *  - With that removed auto-size would work, but it sizes to the LONGEST cell,
 *    so a 200-character Remarks or Material Description would produce a column
 *    far wider than a screen — and would break the "one page wide" print rule.
 *    The long text columns get a sensible fixed width and wrap instead.
 */
class MaterialReceivingExport implements FromView, WithEvents, WithTitle
{
    /** Rows inserted above the rendered table: title, subtitle, spacer. */
    private const TITLE_ROWS = 3;

    /** Where the column header lands once the title rows are inserted. */
    private const HEADER_ROW = self::TITLE_ROWS + 1;

    /** Blank rows between the last data row and the signature block. */
    private const SIGNATURE_GAP = 3;

    private const FIRST_COL = 'A';
    private const LAST_COL = 'Y';

    /**
     * column => width in characters. Narrow for codes and units, wide for the
     * free-text columns that carry buyer/style/material/remarks. The total is
     * what has to fit one page wide in landscape, so these are kept tight.
     */
    private const WIDTHS = [
        'A' => 13,  // Inventory/MRR Date
        'B' => 22,  // GRN/MRN No
        'C' => 26,  // PO Number
        'D' => 22,  // PI Number
        'E' => 20,  // LC No
        'F' => 24,  // BL No / AWBL No
        'G' => 18,  // BOE No
        'H' => 12,  // BOE Date
        'I' => 16,  // Vendor Type
        'J' => 9,   // Season
        'K' => 24,  // Buyer
        'L' => 24,  // Style No.
        'M' => 36,  // Material Description
        'N' => 20,  // Art# No / SAP Code
        'O' => 16,  // Color
        'P' => 10,  // Size
        'Q' => 13,  // Booking Qty
        'R' => 13,  // Invoice Qty
        'S' => 7,   // UoM
        'T' => 13,  // Physical Qty
        'U' => 13,  // Short/Excess
        'V' => 11,  // Roll/Bale
        'W' => 14,  // Rate as per Commercial Invoice
        'X' => 15,  // Total Value
        'Y' => 36,  // Remarks
    ];

    /**
     * Every text column wraps — only the date and number columns below do not.
     *
     * The widths above already fit normal values on one line; wrapping is the
     * safety net for the exceptional ones. Excel clips non-wrapped text as soon
     * as the neighbouring cell is filled, and in a fully populated register it
     * always is, so an unusually long PO/BL number would otherwise be cut.
     */
    private const WRAP_COLS = [
        'B', 'C', 'D', 'E', 'F', 'G', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'S', 'V', 'Y',
    ];

    /**
     * Numeric columns => display format. The blade already emits these raw
     * (no separators, '.' decimal) so the HTML reader stores real numbers;
     * this only controls how they are shown, and right-aligns them.
     */
    private const NUMBER_FORMATS = [
        'Q' => '#,##0.####',  // Booking Qty
        'R' => '#,##0.####',  // Invoice Qty
        'T' => '#,##0.####',  // Physical Qty
        'U' => '#,##0.####',  // Short/Excess
        'W' => '#,##0.0000',  // Rate — 4dp, as on screen
        'X' => '#,##0.00',    // Total Value — 2dp
    ];

    /** Columns the blade writes as 'd-M-Y' text; converted to real dates. */
    private const DATE_COLS = ['A', 'H'];

    private const DATE_FORMAT = 'dd-mmm-yyyy';

    /**
     * The sign-off block. Column spans are chosen so the five blocks come out
     * close to equal PRINTED width — the columns themselves differ in width, so
     * splitting five-and-five would have produced very lopsided blocks.
     *
     * [first column, last column, label]
     */
    private const SIGNATURE_BLOCKS = [
        ['A', 'D', 'Prepared By'],
        ['E', 'J', 'Checked By QC'],
        ['K', 'M', 'Store Manager'],
        ['N', 'T', 'Accounts Manager'],
        ['U', 'Y', 'Approved By Finance and Commercial Head'],
    ];

    /**
     * @param  Collection<int, \App\Models\MaterialReceiving>  $receivings
     * @param  array<int, array<string, ?string>>  $docs  BOM document fields, keyed by excel_row_id
     * @param  ?string  $filterSummary  active filters, printed under the title; null when unfiltered
     */
    public function __construct(
        private readonly Collection $receivings,
        private readonly array $docs = [],
        private readonly ?string $filterSummary = null,
    ) {
    }

    public function view(): \Illuminate\Contracts\View\View
    {
        return view('store.material-stock.receivings-export', [
            'receivings' => $this->receivings,
            'docs' => $this->docs,
        ]);
    }

    public function title(): string
    {
        return 'Receiving History';
    }

    /**
     * @return array<string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // The rendered table occupies rows 1..N; make room above it for
                // the title block, after which the header sits on HEADER_ROW.
                $sheet->insertNewRowBefore(1, self::TITLE_ROWS);

                $lastDataRow = max($sheet->getHighestRow(), self::HEADER_ROW);

                $this->sizeColumns($sheet);
                $this->buildTitle($sheet);
                $this->styleHeader($sheet);
                $this->styleBody($sheet, $lastDataRow);
                $this->formatNumbers($sheet, $lastDataRow);
                $this->formatDates($sheet, $lastDataRow);

                $signatureLastRow = $this->buildSignature($sheet, $lastDataRow);

                // A 25-column register is read by scrolling right, so the
                // header has to stay put; the filter makes it a usable
                // register rather than a flat dump.
                $sheet->freezePane(self::FIRST_COL.(self::HEADER_ROW + 1));
                $sheet->setAutoFilter(
                    self::FIRST_COL.self::HEADER_ROW.':'.self::LAST_COL.$lastDataRow
                );

                $this->setUpPrinting($sheet, $signatureLastRow);
            },
        ];
    }

    private function sizeColumns(Worksheet $sheet): void
    {
        foreach (self::WIDTHS as $col => $width) {
            $sheet->getColumnDimension($col)->setAutoSize(false)->setWidth($width);
        }
    }

    private function buildTitle(Worksheet $sheet): void
    {
        $span = fn (int $row) => self::FIRST_COL.$row.':'.self::LAST_COL.$row;

        $sheet->mergeCells($span(1));
        $sheet->setCellValue(self::FIRST_COL.'1', 'Receiving Register (MRR)');
        $sheet->getStyle($span(1))->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '1E3A8A']],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(26);

        $sheet->mergeCells($span(2));
        $sheet->setCellValue(self::FIRST_COL.'2', sprintf(
            'Store · Buyer / Style Stock · %d record(s) · Generated: %s',
            $this->receivings->count(),
            now()->format('d-M-Y H:i'),
        ));
        $sheet->getStyle($span(2))->applyFromArray([
            'font' => ['size' => 10, 'italic' => true, 'color' => ['rgb' => '475569']],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(18);

        // Row 3 doubles as the filter line when the register is scoped, and as
        // a thin spacer when it is not. Keeping it a single row either way
        // means HEADER_ROW never moves.
        if ($this->filterSummary !== null) {
            $sheet->mergeCells($span(3));
            $sheet->setCellValue(self::FIRST_COL.'3', 'Filtered — '.$this->filterSummary);
            $sheet->getStyle($span(3))->applyFromArray([
                'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'B45309']],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ]);
            $sheet->getRowDimension(3)->setRowHeight(18);

            return;
        }

        $sheet->getRowDimension(3)->setRowHeight(6);
    }

    private function styleHeader(Worksheet $sheet): void
    {
        $range = self::FIRST_COL.self::HEADER_ROW.':'.self::LAST_COL.self::HEADER_ROW;

        // Light fill with dark text rather than the PDF's solid blue: this
        // sheet gets filtered and printed on office mono printers.
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '1E3A8A']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DBEAFE']],
            'alignment' => [
                'wrapText' => true,
                'vertical' => Alignment::VERTICAL_CENTER,
                'horizontal' => Alignment::HORIZONTAL_CENTER,
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '94A3B8']],
            ],
        ]);

        // Headers wrap onto two lines, so give the row room for them.
        $sheet->getRowDimension(self::HEADER_ROW)->setRowHeight(32);
    }

    private function styleBody(Worksheet $sheet, int $lastDataRow): void
    {
        if ($lastDataRow <= self::HEADER_ROW) {
            return;
        }

        $firstDataRow = self::HEADER_ROW + 1;
        $body = self::FIRST_COL.$firstDataRow.':'.self::LAST_COL.$lastDataRow;

        $sheet->getStyle($body)->applyFromArray([
            'font' => ['size' => 10],
            'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']],
            ],
        ]);

        foreach (self::WRAP_COLS as $col) {
            $sheet->getStyle($col.$firstDataRow.':'.$col.$lastDataRow)
                ->getAlignment()->setWrapText(true);
        }

        for ($row = $firstDataRow; $row <= $lastDataRow; $row++) {
            // Zebra striping on every second data row. Counted from the first
            // data row so the banding does not shift when the title block
            // above it changes height.
            if (($row - $firstDataRow) % 2 === 1) {
                $sheet->getStyle(self::FIRST_COL.$row.':'.self::LAST_COL.$row)
                    ->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('F1F5F9');
            }

            // -1 = auto height, so wrapped Material Description / Remarks grow
            // the row instead of being clipped. The HTML reader can leave a
            // fixed height behind, so this is set explicitly.
            $sheet->getRowDimension($row)->setRowHeight(-1);
        }
    }

    private function formatNumbers(Worksheet $sheet, int $lastDataRow): void
    {
        if ($lastDataRow <= self::HEADER_ROW) {
            return;
        }

        foreach (self::NUMBER_FORMATS as $col => $format) {
            $range = $col.(self::HEADER_ROW + 1).':'.$col.$lastDataRow;
            $sheet->getStyle($range)->getNumberFormat()->setFormatCode($format);
            $sheet->getStyle($range)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
    }

    /**
     * The blade prints dates as 'd-M-Y' text (the PDF needs them that way), so
     * the HTML reader stores them as strings. Convert to real Excel dates here
     * so the column sorts and filters by date rather than alphabetically.
     *
     * BOE Date is a raw OCR cell and can hold text that is not a date at all;
     * anything that will not parse is left exactly as written.
     */
    private function formatDates(Worksheet $sheet, int $lastDataRow): void
    {
        if ($lastDataRow <= self::HEADER_ROW) {
            return;
        }

        foreach (self::DATE_COLS as $col) {
            for ($row = self::HEADER_ROW + 1; $row <= $lastDataRow; $row++) {
                $cell = $sheet->getCell($col.$row);
                $value = trim((string) $cell->getValue());

                if ($value === '') {
                    continue;
                }

                $date = \DateTime::createFromFormat('!d-M-Y', $value);

                // Re-format and compare rather than trusting the parser: this
                // guarantees the whole string was a date, so OCR text like
                // "as per invoice" is left alone instead of becoming a number.
                if (! $date instanceof \DateTime || strcasecmp($date->format('d-M-Y'), $value) !== 0) {
                    continue;
                }

                $cell->setValueExplicit(ExcelDate::PHPToExcel($date), DataType::TYPE_NUMERIC);
            }

            $range = $col.(self::HEADER_ROW + 1).':'.$col.$lastDataRow;
            $sheet->getStyle($range)->getNumberFormat()->setFormatCode(self::DATE_FORMAT);
            $sheet->getStyle($range)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
    }

    /**
     * Sign-off block: an empty bordered row to sign on, with the bold label
     * underneath — the usual order on an official document.
     *
     * Written once, after the last data row, so it appears only at the end of
     * the register. Only the column header is set to repeat when printing.
     *
     * @return int the last row used, so the print area can include it
     */
    private function buildSignature(Worksheet $sheet, int $lastDataRow): int
    {
        $lineRow = $lastDataRow + self::SIGNATURE_GAP + 1;
        $labelRow = $lineRow + 1;

        foreach (self::SIGNATURE_BLOCKS as [$from, $to, $label]) {
            $lineRange = $from.$lineRow.':'.$to.$lineRow;
            $labelRange = $from.$labelRow.':'.$to.$labelRow;

            $sheet->mergeCells($lineRange);
            $sheet->mergeCells($labelRange);

            // The signature line itself: an empty merged cell whose bottom
            // border is what gets signed over.
            $sheet->getStyle($lineRange)->applyFromArray([
                'borders' => [
                    'bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '334155']],
                ],
            ]);

            $sheet->setCellValue($from.$labelRow, $label);
            $sheet->getStyle($labelRange)->applyFromArray([
                'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '0F172A']],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_TOP,
                    'wrapText' => true,
                ],
            ]);
        }

        // Room to actually sign, and room for the longest label to wrap.
        $sheet->getRowDimension($lineRow)->setRowHeight(38);
        $sheet->getRowDimension($labelRow)->setRowHeight(30);

        return $labelRow;
    }

    /**
     * Landscape A4, and above all ONE PAGE WIDE: a register that spilled its
     * right-hand columns onto a second sheet of paper would be unusable.
     * Height is left free (setFitToHeight(0)) so long registers simply run on.
     */
    private function setUpPrinting(Worksheet $sheet, int $lastRow): void
    {
        $setup = $sheet->getPageSetup();
        $setup->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $setup->setPaperSize(PageSetup::PAPERSIZE_A4);
        $setup->setFitToPage(true);
        $setup->setFitToWidth(1);
        $setup->setFitToHeight(0);
        $setup->setHorizontalCentered(true);
        $setup->setPrintArea(self::FIRST_COL.'1:'.self::LAST_COL.$lastRow);

        // Only the column header repeats. The title block and the signature
        // block are deliberately left out so they appear once each.
        $setup->setRowsToRepeatAtTopByStartAndEnd(self::HEADER_ROW, self::HEADER_ROW);

        // Narrow but not cramped (inches).
        $margins = $sheet->getPageMargins();
        $margins->setTop(0.3)->setBottom(0.3)->setLeft(0.25)->setRight(0.25);
        $margins->setHeader(0.15)->setFooter(0.15);

        $sheet->getHeaderFooter()->setOddFooter('&L&8Receiving Register (MRR)&R&8Page &P of &N');
    }
}
