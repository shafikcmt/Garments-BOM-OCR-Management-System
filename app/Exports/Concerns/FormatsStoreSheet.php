<?php

namespace App\Exports\Concerns;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
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

    /**
     * Floor and slack for a figures column.
     *
     * Wider than the text floor because a value column's shortest sensible
     * content is already something like "1,234.00", and because the total row
     * prints it back at 12pt bold over the body's 9pt — the widest thing in the
     * column is usually the total, set in the largest type on the sheet.
     */
    private const WIDTH_NUMERIC_MIN = 10;

    private const NUMERIC_PADDING = 3;

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

        $this->sizeColumns($sheet, $headerStart, $headerEnd, $firstDataRow, $lastDataRow, $lastRow, $lastColumnIndex);

        // The header stays put while the buyer scrolls a few hundred lines.
        $sheet->freezePane('A'.$firstDataRow);

        $this->applyPrintSetup($sheet, $headerStart, $headerEnd, $lastColumn, $lastRow);

        $sheet->getSheetView()->setZoomScale(85);
    }

    /**
     * Page setup, so File > Print is usable straight away rather than spilling
     * 21 columns across a dozen sheets of paper.
     *
     * Paper size is the one that actually mattered. PhpSpreadsheet's default is
     * US Letter (PageSetup::$paperSizeDefault), which nobody here prints on: a
     * sheet scaled to fit Letter's wider landscape page comes out of an A4
     * printer either clipped down the right edge or shrunk by the driver to
     * something unreadable. Every other print setting below was already right
     * and still looked wrong because of this one.
     *
     * Nothing here touches the data, the column order or the headings — only
     * how the sheet is laid onto paper.
     */
    private function applyPrintSetup(Worksheet $sheet, int $headerStart, int $headerEnd, string $lastColumn, int $lastRow): void
    {
        $setup = $sheet->getPageSetup();

        $setup->setPaperSize(PageSetup::PAPERSIZE_A4);
        $setup->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);

        // One page wide, as many pages long as the data needs. setFitToWidth
        // turns fitToPage on by itself, so the scaling is genuinely applied.
        $setup->setFitToWidth(1);
        $setup->setFitToHeight(0);

        // The table's heading rows print again at the top of every page, so
        // page four is still readable on its own. Deliberately the header block
        // ONLY, not the letterhead above it: repeating a 28pt company name on
        // each page would eat a third of the printable height and read as a
        // duplicate rather than a running head.
        $setup->setRowsToRepeatAtTopByStartAndEnd($headerStart, $headerEnd);

        // Pin the range so a stray cell outside the report cannot drag a blank
        // extra page along with it.
        $setup->setPrintArea('A1:'.$lastColumn.$lastRow);

        // Centred across the page: with fit-to-width the table rarely uses the
        // full width exactly, and the leftover margin looks deliberate on the
        // left and right rather than dumped on one side.
        $sheet->setPrintGridlines(false);
        $setup->setHorizontalCentered(true);

        // 0.3in ≈ 7.6mm side margins. The previous 0.25in sat inside the
        // non-printable edge of some office lasers, which is its own way of
        // losing the first column; this clears it and still wastes no width.
        $sheet->getPageMargins()->setTop(0.4)->setBottom(0.4)->setLeft(0.3)->setRight(0.3);
        $sheet->getPageMargins()->setHeader(0.2)->setFooter(0.2);
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

        // No "SL" column. The older store reports all have one, so they never
        // reach this and their formatting is byte-for-byte what it always was —
        // this is only the way in for the registers that number nothing, such as
        // Bulk Issuing and the buyer/style Store Reports.
        return $this->locateHeaderByShape($sheet, $limit, $lastColumnIndex);
    }

    /**
     * The header row of a table that has no "SL" column, found by its shape.
     *
     * A heading row is wide and entirely textual, and has a row of data under
     * it. The letterhead lines above the table look nothing like that: company
     * name, title and filter summary are each one long cell spanning the sheet
     * (the Blade writes them with colspan, which arrives here as one filled cell
     * followed by empty ones), so the minimum width is what separates them.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function locateHeaderByShape(Worksheet $sheet, int $limit, int $lastColumnIndex): array
    {
        for ($row = 1; $row <= $limit; $row++) {
            $filled = 0;
            $numeric = false;

            for ($col = 1; $col <= $lastColumnIndex; $col++) {
                $value = trim((string) $sheet->getCellByColumnAndRow($col, $row)->getValue());

                if ($value === '') {
                    continue;
                }

                if ($this->isNumeric($value)) {
                    $numeric = true;
                    break;
                }

                $filled++;
            }

            // A heading carries no figures, and spans more than the two or
            // three cells a stray label above the table would.
            if ($numeric || $filled < 3) {
                continue;
            }

            // Something has to be listed under it, or this is a caption rather
            // than a header and the rows below are not a table.
            if ($this->filledCount($sheet, $row + 1, $lastColumnIndex) < 2) {
                continue;
            }

            return [$row, $row];
        }

        return [null, null];
    }

    /** How many cells in the row hold anything. */
    private function filledCount(Worksheet $sheet, int $row, int $lastColumnIndex): int
    {
        $filled = 0;

        for ($col = 1; $col <= $lastColumnIndex; $col++) {
            if (trim((string) $sheet->getCellByColumnAndRow($col, $row)->getValue()) !== '') {
                $filled++;
            }
        }

        return $filled;
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
    private function sizeColumns(
        Worksheet $sheet,
        int $headerStart,
        int $headerEnd,
        int $firstDataRow,
        int $lastDataRow,
        int $lastRow,
        int $lastColumnIndex
    ): void {
        for ($col = 1; $col <= $lastColumnIndex; $col++) {
            $letter = Coordinate::stringFromColumnIndex($col);

            // A figures column is measured on what Excel will DRAW, not on what
            // is stored in the cell. The sheet holds a bare 6710; the number
            // format styleBody applies renders it as "6,710.00". Measuring the
            // stored value asked for 4 characters and the cell needed 8, which
            // is exactly how a value column ends up showing ######.
            if ($this->columnIsNumeric($sheet, $col, $firstDataRow, $lastDataRow)) {
                $heading = $this->headingFor($sheet, $col, $headerStart, $headerEnd);
                $longest = $this->longestFormattedIn(
                    $sheet,
                    $col,
                    $headerStart + 1,
                    $lastRow,
                    $this->isMoneyHeading($heading)
                );

                $width = max(self::WIDTH_NUMERIC_MIN, min(self::WIDTH_MAX, $longest + self::NUMERIC_PADDING));
            } else {
                // Headings are excluded from the measurement: they wrap, so a
                // long one must not widen a column of short values.
                $longest = $this->longestIn($sheet, $col, $headerStart + 1, $lastRow);

                $width = max(self::WIDTH_MIN, min(self::WIDTH_MAX, $longest + 2));
            }

            $sheet->getColumnDimension($letter)->setAutoSize(false);
            $sheet->getColumnDimension($letter)->setWidth($width);
        }
    }

    /**
     * Longest value in a figures column, measured as it will be displayed.
     *
     * Mirrors the number formats styleBody sets, so the two can only agree:
     * money to two decimals, quantities to at most four with trailing zeros
     * dropped, both with thousands separators.
     *
     * Non-numeric cells in the range are measured as they are — that is the
     * "Total-" label and the "—" placeholders, which share the column.
     */
    private function longestFormattedIn(Worksheet $sheet, int $col, int $from, int $to, bool $money): int
    {
        $longest = 0;

        for ($row = $from; $row <= $to; $row++) {
            $raw = trim((string) $sheet->getCellByColumnAndRow($col, $row)->getValue());

            if ($raw === '') {
                continue;
            }

            if (! $this->isNumeric($raw)) {
                $longest = max($longest, mb_strlen($raw));

                continue;
            }

            $value = (float) str_replace(',', '', $raw);

            $shown = $money
                ? number_format($value, 2)
                : rtrim(rtrim(number_format($value, 4), '0'), '.');

            $longest = max($longest, mb_strlen($shown));
        }

        return $longest;
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
