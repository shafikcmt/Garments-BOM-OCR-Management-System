<?php

namespace App\Exports\Concerns;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Shared Excel styling for the Store reports.
 *
 * Both the Consumable Stock Report and the monthly Purchase Requisition are
 * built with Laravel Excel's FromView, which turns a Blade table into cells.
 * That carries the DATA across but almost none of the presentation: a rendered
 * sheet arrives with no fills, no number formats, no borders, default column
 * widths and no frozen header, which is what made the downloads look nothing
 * like the workbooks they replace.
 *
 * This applies the missing half after the sheet is written, so the two reports
 * come out looking like they were produced by the same system.
 *
 * Nothing here reads or changes the report's data. It locates the header block,
 * then decides formatting from what is already in the cells — so adding or
 * moving a column needs no change in this file.
 */
trait FormatsStoreSheet
{
    /** Header background — the same navy the PDFs print. */
    private const HEADER_FILL = 'FF000B6F';

    private const HEADER_TEXT = 'FFFFFFFF';

    /** Grid lines, and the heavier line boxing the table in. */
    private const BORDER_LIGHT = 'FFC7D2FE';

    private const BORDER_STRONG = 'FF33439E';

    /** Total row background. */
    private const TOTAL_FILL = 'FFEEF2FF';

    /** Column width bounds, in Excel character units. */
    private const WIDTH_MIN = 7;

    private const WIDTH_MAX = 42;

    /** Above this many characters a text column wraps instead of running on. */
    private const WRAP_OVER = 26;

    /**
     * Headings whose column holds money — two decimals and a thousands
     * separator. Everything else numeric is treated as a quantity, which may be
     * fractional but should not show trailing zeros.
     */
    private const MONEY_HEADINGS = ['rate', 'amount', 'price', 'value'];

    protected function formatStoreSheet(Worksheet $sheet): void
    {
        $lastRow = $sheet->getHighestDataRow();
        $lastColumn = $sheet->getHighestDataColumn();
        $lastColumnIndex = Coordinate::columnIndexFromString($lastColumn);

        if ($lastRow < 1) {
            return;
        }

        [$headerStart, $headerEnd] = $this->locateHeader($sheet, $lastRow, $lastColumnIndex);

        if ($headerStart === null) {
            return;
        }

        $firstDataRow = $headerEnd + 1;

        // The signature block is found first, because it marks where the data
        // ends just as surely as a total does — and a sheet may carry one, the
        // other, or both.
        $signatureRow = $this->locateLabelRow($sheet, $firstDataRow, $lastRow, 'prepared');
        $totalRow = $this->locateLabelRow($sheet, $firstDataRow, ($signatureRow ?? $lastRow + 1) - 1, 'total');

        $lastDataRow = ($totalRow ?? $signatureRow ?? $lastRow + 1) - 1;

        $this->styleTitleBlock($sheet, $headerStart, $lastColumn);
        $this->styleHeader($sheet, $headerStart, $headerEnd, $lastColumn);
        $this->styleBody($sheet, $headerStart, $firstDataRow, $lastDataRow, $lastColumnIndex);

        if ($totalRow !== null) {
            $this->styleTotalRow($sheet, $totalRow, $lastColumn, $lastColumnIndex);
        }

        if ($signatureRow !== null) {
            // Starts below the total row, not on it — otherwise the blank
            // signing rows would reset the height the total row was just given.
            $this->styleSignatureBlock(
                $sheet,
                ($totalRow ?? $lastDataRow) + 1,
                $signatureRow,
                $lastRow,
                $lastColumn
            );
        }

        $this->sizeColumns($sheet, $headerStart, $lastRow, $lastColumnIndex);

        // The header stays put while the buyer scrolls a few hundred lines.
        $sheet->freezePane('A'.$firstDataRow);

        // Print setup, so File > Print is usable straight away rather than
        // spilling 21 columns across a dozen sheets of paper.
        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setFitToWidth(1);
        $sheet->getPageSetup()->setFitToHeight(0);
        $sheet->getPageMargins()->setTop(0.4)->setBottom(0.4)->setLeft(0.25)->setRight(0.25);
        $sheet->getSheetView()->setZoomScale(85);
    }

    /**
     * Find the table's header block.
     *
     * Both reports print a few heading lines first, so the header is not row 1.
     * It is found by its "SL" / "Sl" cell, and then extended by one row when the
     * next row is a second tier of headings — the reference workbook's row 9 /
     * row 10 pair, which arrives here as merged cells above plain ones.
     *
     * @return array{0: int|null, 1: int|null} first and last row of the header
     */
    private function locateHeader(Worksheet $sheet, int $lastRow, int $lastColumnIndex): array
    {
        $limit = min($lastRow, 40);

        for ($row = 1; $row <= $limit; $row++) {
            for ($col = 1; $col <= min($lastColumnIndex, 4); $col++) {
                $value = trim((string) $sheet->getCellByColumnAndRow($col, $row)->getValue());

                if (strcasecmp($value, 'sl') !== 0) {
                    continue;
                }

                // A merged "SL" spanning two rows means the tier below is part
                // of the same header.
                $end = $this->isSecondHeaderTier($sheet, $row + 1, $lastColumnIndex) ? $row + 1 : $row;

                return [$row, $end];
            }
        }

        return [null, null];
    }

    /**
     * A second header tier is a row that is partly empty (under the merged
     * headings) and carries no numbers — the sub-headings "Qty", "Rate",
     * "Date". A data row would carry numbers.
     */
    private function isSecondHeaderTier(Worksheet $sheet, int $row, int $lastColumnIndex): bool
    {
        $filled = 0;

        for ($col = 1; $col <= $lastColumnIndex; $col++) {
            $value = trim((string) $sheet->getCellByColumnAndRow($col, $row)->getValue());

            if ($value === '') {
                continue;
            }

            if ($this->isNumeric($value)) {
                return false;
            }

            $filled++;
        }

        return $filled > 0 && $filled < $lastColumnIndex;
    }

    /**
     * The first row in the range whose LEFTMOST cell mentions $word — the
     * "Total-" / "Sub-Total" line, or the "Prepared by" signature line.
     *
     * Column A only, and "contains" rather than "starts with": the stock report
     * labels its footer "Sub-Total", which a starts-with test misses, while
     * searching every column would match an item that happened to be called
     * something like "Total Station".
     */
    private function locateLabelRow(Worksheet $sheet, int $from, int $to, string $word): ?int
    {
        for ($row = $from; $row <= $to; $row++) {
            $value = trim((string) $sheet->getCell('A'.$row)->getValue());

            if ($value !== '' && stripos($value, $word) !== false) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The letterhead above the table — company name, report title, and the
     * label/value information block.
     *
     * Sizes and row heights are the reference workbook's: 28pt company, 14pt
     * title, 11pt information lines. The Blade emits the merges (via colspan);
     * this supplies the type and spacing that HTML cannot carry into a
     * spreadsheet.
     */
    private function styleTitleBlock(Worksheet $sheet, int $headerStart, string $lastColumn): void
    {
        if ($headerStart <= 1) {
            return;
        }

        $sheet->getStyle('A1:'.$lastColumn.'1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 28],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(34);

        if ($headerStart > 2) {
            $sheet->getStyle('A2:'.$lastColumn.'2')->applyFromArray([
                'font' => ['bold' => true, 'size' => 14],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getRowDimension(2)->setRowHeight(20);
        }

        // The information block: label on the left of each pair, value beside
        // it, and the personnel block on the right — all merged by the Blade.
        for ($row = 3; $row < $headerStart; $row++) {
            $sheet->getStyle('A'.$row.':'.$lastColumn.$row)->applyFromArray([
                'font' => ['size' => 11],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);

            // The label reads as a label, not as more prose.
            $sheet->getStyle('A'.$row)->getFont()->setBold(true);
            $sheet->getRowDimension($row)->setRowHeight(16);
        }

        // A little air between the letterhead and the table.
        $sheet->getRowDimension($headerStart - 1)->setRowHeight(10);
    }

    private function styleHeader(Worksheet $sheet, int $start, int $end, string $lastColumn): void
    {
        $range = 'A'.$start.':'.$lastColumn.$end;

        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => self::HEADER_TEXT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::HEADER_FILL]],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => self::BORDER_STRONG]]],
        ]);

        // Reference proportions: the group headings sit a point larger than the
        // sub-headings under them, and both are tall enough to wrap.
        $sheet->getRowDimension($start)->setRowHeight(30);

        if ($end > $start) {
            $sheet->getStyle('A'.$end.':'.$lastColumn.$end)->getFont()->setSize(9);
            $sheet->getRowDimension($end)->setRowHeight(32);
        }
    }

    /**
     * Borders everywhere, then per-column alignment and number format decided
     * from what the column actually contains.
     */
    private function styleBody(Worksheet $sheet, int $headerStart, int $firstDataRow, int $lastDataRow, int $lastColumnIndex): void
    {
        if ($lastDataRow < $firstDataRow) {
            return;
        }

        $lastColumn = Coordinate::stringFromColumnIndex($lastColumnIndex);

        $sheet->getStyle('A'.$firstDataRow.':'.$lastColumn.$lastDataRow)->applyFromArray([
            'font' => ['size' => 9],
            'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => self::BORDER_LIGHT]]],
        ]);

        for ($col = 1; $col <= $lastColumnIndex; $col++) {
            $letter = Coordinate::stringFromColumnIndex($col);
            $range = $letter.$firstDataRow.':'.$letter.$lastDataRow;

            if ($this->columnIsNumeric($sheet, $col, $firstDataRow, $lastDataRow)) {
                $heading = $this->headingFor($sheet, $col, $headerStart, $firstDataRow - 1);

                $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $sheet->getStyle($range)->getNumberFormat()->setFormatCode(
                    $this->isMoneyHeading($heading) ? '#,##0.00' : '#,##0.####'
                );

                continue;
            }

            $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

            // Wrap only the genuinely long columns. Wrapping a three-character
            // Uom column just makes every row taller for nothing.
            if ($this->longestIn($sheet, $col, $firstDataRow, $lastDataRow) > self::WRAP_OVER) {
                $sheet->getStyle($range)->getAlignment()->setWrapText(true);
            }
        }

        // Left to itself Excel grows a row to fit the tallest wrapped cell;
        // -1 restores that automatic height after the header rows were pinned.
        for ($row = $firstDataRow; $row <= $lastDataRow; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(-1);
        }
    }

    /**
     * The "Total-" row: heavier type, a medium rule across the top separating
     * it from the last item, and the figure right-aligned under the Amount
     * column it belongs to.
     */
    private function styleTotalRow(Worksheet $sheet, int $row, string $lastColumn, int $lastColumnIndex): void
    {
        $sheet->getStyle('A'.$row.':'.$lastColumn.$row)->applyFromArray([
            'font' => ['bold' => true, 'size' => 12],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::TOTAL_FILL]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders' => [
                'top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => self::BORDER_STRONG]],
                'bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => self::BORDER_STRONG]],
            ],
        ]);

        $sheet->getRowDimension($row)->setRowHeight(23);

        // Both the label and the figure hug the Amount column, so the eye runs
        // straight from "Total-" to the number instead of across empty cells.
        for ($col = 1; $col <= $lastColumnIndex; $col++) {
            $sheet->getStyleByColumnAndRow($col, $row)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
    }

    /**
     * The Prepared by / Checked by / Approved by block.
     *
     * The thin rule along the top of the label row IS the signature line, with
     * blank rows above it left tall enough to actually sign into, and the
     * designation printed underneath — the reference workbook's arrangement.
     */
    private function styleSignatureBlock(Worksheet $sheet, int $from, int $labelRow, int $to, string $lastColumn): void
    {
        // Room to sign between the total and the line.
        for ($row = $from; $row < $labelRow; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(18);
        }

        $sheet->getStyle('A'.$labelRow.':'.$lastColumn.$labelRow)->applyFromArray([
            'font' => ['bold' => true, 'size' => 10],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => self::BORDER_STRONG]]],
        ]);
        $sheet->getRowDimension($labelRow)->setRowHeight(18);

        // The designation under each name, if the Blade printed one.
        if ($labelRow + 1 <= $to) {
            $sheet->getStyle('A'.($labelRow + 1).':'.$lastColumn.($labelRow + 1))->applyFromArray([
                'font' => ['size' => 9, 'italic' => true],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getRowDimension($labelRow + 1)->setRowHeight(15);
        }
    }

    /**
     * Explicit widths rather than ShouldAutoSize.
     *
     * Auto-size measures merged cells badly — a heading merged across four
     * columns makes the first of them enormous and leaves the rest at default —
     * which is most of why these sheets looked misaligned. Measuring the
     * content directly and clamping the result gives a readable column whether
     * the cell holds "Pkt" or a forty-character item name.
     */
    private function sizeColumns(Worksheet $sheet, int $headerStart, int $lastRow, int $lastColumnIndex): void
    {
        for ($col = 1; $col <= $lastColumnIndex; $col++) {
            $letter = Coordinate::stringFromColumnIndex($col);

            // Headings are excluded from the measurement: they wrap, so a long
            // one must not widen a column of short values.
            $longest = $this->longestIn($sheet, $col, $headerStart + 1, $lastRow);

            $width = max(self::WIDTH_MIN, min(self::WIDTH_MAX, $longest + 2));

            $sheet->getColumnDimension($letter)->setAutoSize(false);
            $sheet->getColumnDimension($letter)->setWidth($width);
        }
    }

    /** True when every non-empty value in the column is a number. */
    private function columnIsNumeric(Worksheet $sheet, int $col, int $from, int $to): bool
    {
        $seen = 0;

        for ($row = $from; $row <= $to; $row++) {
            $value = trim((string) $sheet->getCellByColumnAndRow($col, $row)->getValue());

            if ($value === '' || $value === '-' || $value === '—') {
                continue;
            }

            if (! $this->isNumeric($value)) {
                return false;
            }

            $seen++;
        }

        return $seen > 0;
    }

    /** The heading above a column, joining both tiers when there are two. */
    private function headingFor(Worksheet $sheet, int $col, int $start, int $end): string
    {
        $parts = [];

        for ($row = $start; $row <= $end; $row++) {
            $value = trim((string) $sheet->getCellByColumnAndRow($col, $row)->getValue());

            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return implode(' ', $parts);
    }

    private function isMoneyHeading(string $heading): bool
    {
        foreach (self::MONEY_HEADINGS as $word) {
            if (stripos($heading, $word) !== false) {
                return true;
            }
        }

        return false;
    }

    /** Longest value in a column, measured in characters. */
    private function longestIn(Worksheet $sheet, int $col, int $from, int $to): int
    {
        $longest = 0;

        for ($row = $from; $row <= $to; $row++) {
            $value = (string) $sheet->getCellByColumnAndRow($col, $row)->getValue();
            $longest = max($longest, mb_strlen(trim($value)));
        }

        return $longest;
    }

    /** Tolerates the thousands separators a rendered view may have left behind. */
    private function isNumeric(string $value): bool
    {
        return is_numeric(str_replace(',', '', $value));
    }
}
