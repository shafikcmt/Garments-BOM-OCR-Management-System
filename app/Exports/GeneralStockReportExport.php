<?php

namespace App\Exports;

use App\Exports\Concerns\FormatsStoreSheet;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel export for the Consumable Stock Report. Renders the same blade the PDF
 * uses, so the download can never disagree with the preview on screen.
 *
 * FromView carries the data but none of the presentation, so the sheet is
 * styled afterwards by FormatsStoreSheet — the same styling the monthly
 * Purchase Requisition workbook gets, so the two downloads match.
 *
 * ShouldAutoSize is deliberately NOT used: it measures merged heading cells
 * badly, which is what left these columns misaligned. FormatsStoreSheet sets
 * widths from the content instead.
 */
class GeneralStockReportExport implements FromView, WithEvents, WithTitle
{
    use FormatsStoreSheet;

    /** @param array<string, mixed> $data payload from GeneralStockLedgerController */
    public function __construct(private readonly array $data)
    {
    }

    public function view(): \Illuminate\Contracts\View\View
    {
        return view('store.stock.ledger-pdf', $this->data + ['forExcel' => true]);
    }

    /** Sheet name mirrors the reference file's "Stock Jul-26" convention. */
    public function title(): string
    {
        return 'Stock '.\Illuminate\Support\Carbon::createFromFormat('!Y-m', $this->data['month'])->format('M-y');
    }

    /** @return array<string, callable> */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $this->formatStoreSheet($sheet);
                // AFTER the shared formatter, never before: it styles the block
                // above the header as a title block, which would otherwise
                // repaint this strip in the letterhead's type.
                $this->addVisibleTotals($sheet);
                // Last: it only replaces cell values with formulas and leaves
                // every format the steps above gave them.
                $this->addRowFormulas($sheet);
            },
        ];
    }

    /**
     * Make every counted row show HOW its figures were worked out: click a cell
     * and the formula is there, using the cells beside it.
     *
     *   Stock as on Date   = Opening Stock + Addition - Consumption
     *   Closing Stock Value = Stock as on Date x Unit Price
     *   Safety Stock Level  = Last Month Cons. Pattern x safety days
     *   Re-order Level      = Safety + Cons. Pattern x (Lead Time + order days)
     *   Whether to place order or not = Out of Stock / Place Order / Low Stock / Ok,
     *                         by the same thresholds the screen uses
     *
     * These are the report's own rules (GeneralStockReportService::row() and
     * statusFor()), written as formulas, so the workbook agrees with the screen
     * and recalculates if somebody edits an input cell.
     *
     * A figure is only turned into a formula when it WAS computed that way. A
     * Safety or Re-order level typed on the item master is a pinned value, not a
     * calculation, so it stays a plain number. A row whose stock had not been
     * counted yet prints "-" and is left alone. Last Month Cons. Pattern needs
     * last month's issues, which are not on this sheet, so it stays a value.
     * Pattern and Unit Price are written at full precision here (the table
     * rounds them for display) so the formulas land on the same numbers.
     *
     * The data rows are the table rows in the same order; if the two ever
     * disagree in length nothing is converted, rather than risk a wrong row.
     */
    private function addRowFormulas(Worksheet $sheet): void
    {
        $rows = collect($this->data['rows'] ?? [])->values();
        $headerRow = $this->locateHeaderRow($sheet);

        if ($headerRow === null || $rows->isEmpty()) {
            return;
        }

        $subTotalRow = $this->locateSubTotalRow($sheet, $headerRow);
        $first = $headerRow + 1;

        if ($subTotalRow === null || $subTotalRow - $first !== $rows->count()) {
            return;
        }

        $col = $this->locateColumns($sheet, $headerRow, [
            'Whether to place order or not', 'Opening Stock', 'Last Month Cons. Pattern', 'Safety Stock Level',
            'Re-order Level', 'Lead Time', 'Addition', 'Consumption', 'Stock as on Date', 'Unit Price', 'Closing Stock Value',
        ]);

        if (count($col) < 11) {
            return;
        }

        $config = config('stock.general_stock');
        $safetyDays = (int) $config['safety_stock_days'];
        $orderDays = (int) $config['order_placing_days'];
        $labels = \App\Services\GeneralStockReportService::statusLabels();

        foreach ($rows as $i => $r) {
            if ($r['not_counted']) {
                continue;
            }

            $n = $first + $i;
            $at = fn (string $heading) => $col[$heading].$n;

            // Inputs at full precision; the table prints them rounded.
            $sheet->setCellValue($at('Last Month Cons. Pattern'), (float) $r['consumption_per_day']);

            if ($r['unit_price'] !== null) {
                $sheet->setCellValue($at('Unit Price'), (float) $r['unit_price']);
            }

            if (! $r['safety_is_manual']) {
                $sheet->setCellValue($at('Safety Stock Level'), '='.$at('Last Month Cons. Pattern').'*'.$safetyDays);
            }

            if (! $r['reorder_is_manual'] && (float) $r['consumption_per_day'] > 0) {
                $sheet->setCellValue(
                    $at('Re-order Level'),
                    '='.$at('Safety Stock Level').'+'.$at('Last Month Cons. Pattern')
                        .'*('.$at('Lead Time').'+'.$orderDays.')'
                );
            }

            $sheet->setCellValue(
                $at('Stock as on Date'),
                '='.$at('Opening Stock').'+'.$at('Addition').'-'.$at('Consumption')
            );

            if ($r['unit_price'] !== null) {
                $sheet->setCellValue($at('Closing Stock Value'), '='.$at('Stock as on Date').'*'.$at('Unit Price'));
            }

            // N() reads the "-" a missing Re-order Level prints as 0, so it is
            // never compared as text. Same order as GeneralStockReportService::statusFor().
            $stock = $at('Stock as on Date');
            $safety = 'N('.$at('Safety Stock Level').')';
            $reorder = 'N('.$at('Re-order Level').')';

            $sheet->setCellValue(
                $at('Whether to place order or not'),
                '=IF('.$stock.'<=0,"'.$labels['out'].'",'
                    .'IF(AND('.$safety.'>0,'.$stock.'<'.$safety.'),"'.$labels['place_order'].'",'
                    .'IF(AND('.$reorder.'>0,'.$stock.'<'.$reorder.'),"'.$labels['low'].'","'.$labels['ok'].'")))'
            );
        }
    }

    /**
     * The live totals strip, one row above the column headings — the reference
     * workbook's row 4, reproduced.
     *
     * SUBTOTAL(9, …) rather than SUM: 9 is "sum of VISIBLE cells", so once the
     * reader turns on Excel's filter and narrows to one Category, these five
     * figures follow the filter instead of continuing to describe the whole
     * store. SUM cannot do that, which is the entire reason the client's own
     * sheet uses SUBTOTAL.
     *
     * Every reference is computed from the sheet as actually written. Nothing
     * here may be hardcoded: the header row moves down when a Filters line or
     * the not-counted Note is present, and the last data row moves with the
     * number of items in the export.
     */
    private function addVisibleTotals(Worksheet $sheet): void
    {
        $headerRow = $this->locateHeaderRow($sheet);

        if ($headerRow === null || $headerRow < 2) {
            return;
        }

        // The Sub-Total row's label sits in column A, and the data ends on the
        // row above it. No Sub-Total means an empty report ("No items match
        // this filter"), which has nothing to total.
        $subTotalRow = $this->locateSubTotalRow($sheet, $headerRow);

        if ($subTotalRow === null || $subTotalRow - 1 < $headerRow + 1) {
            return;
        }

        $firstDataRow = $headerRow + 1;
        $lastDataRow = $subTotalRow - 1;

        // Columns are found by their heading text, not by letter. The letters
        // are only stable while the column list is — and that list changed twice
        // this month, most recently when the frozen Opening Stock column went.
        $columns = $this->locateColumns($sheet, $headerRow, [
            'Opening Stock',
            'Addition',
            'Consumption',
            'Stock as on Date',
            'Closing Stock Value',
        ]);

        $stripRow = $headerRow - 1;

        foreach ($columns as $letter) {
            $sheet->setCellValue(
                $letter.$stripRow,
                '=SUBTOTAL(9,'.$letter.$firstDataRow.':'.$letter.$lastDataRow.')'
            );
        }

        if ($columns === []) {
            return;
        }

        $this->liveTotalRow($sheet, $subTotalRow, $firstDataRow, $lastDataRow, $columns);

        $this->labelVisibleTotals($sheet, $stripRow, $columns);

        // SUBTOTAL is only worth anything next to a filter the reader can
        // actually apply. Header row plus the data — deliberately NOT the
        // Sub-Total row, which is the whole-report figure and must not be
        // filtered away or counted as a data row.
        $sheet->setAutoFilter('A'.$headerRow.':'.$sheet->getHighestDataColumn().$lastDataRow);
    }

    /**
     * Turn the Sub-Total row at the bottom into live formulas too, so the two
     * totals on the sheet answer the same question.
     *
     * The Blade writes that row as static figures, and it must keep doing so —
     * the same partial renders the PDF, which has no formula engine. So the
     * conversion happens HERE, on the workbook only: unfiltered it shows exactly
     * the numbers it always did, and filtered it follows the filter instead of
     * continuing to describe rows the reader can no longer see.
     *
     * Only cells that ALREADY carry a figure are converted. The Blade leaves
     * Opening Stock and Stock as on Date out of this row on purpose — summing a
     * brought-forward balance across unrelated items says nothing — and writing
     * a formula into those blanks would invent a total the report never had.
     *
     * @param  array<string, string>  $columns
     */
    private function liveTotalRow(Worksheet $sheet, int $subTotalRow, int $firstDataRow, int $lastDataRow, array $columns): void
    {
        foreach ($columns as $letter) {
            $cell = $sheet->getCell($letter.$subTotalRow);
            $value = $cell->getValue();

            if ($value === null || $value === '' || $cell->isFormula()) {
                continue;
            }

            $cell->setValue('=SUBTOTAL(9,'.$letter.$firstDataRow.':'.$letter.$lastDataRow.')');
        }
    }

    /**
     * Name the strip, and make it read as a number rather than as part of the
     * letterhead. Both totals now move with the filter, so the label says which
     * rows each covers rather than which one is live.
     *
     * @param  array<string, string>  $columns
     */
    private function labelVisibleTotals(Worksheet $sheet, int $stripRow, array $columns): void
    {
        $firstColumn = reset($columns);
        $labelColumn = Coordinate::stringFromColumnIndex(
            max(1, Coordinate::columnIndexFromString($firstColumn) - 1)
        );

        // "Total", not "Visible Total": the Sub-Total row is filter-aware now
        // too, so singling this one out as the live one would be wrong.
        $sheet->setCellValue($labelColumn.$stripRow, 'Total');
        $sheet->getStyle($labelColumn.$stripRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $range = 'A'.$stripRow.':'.$sheet->getHighestDataColumn().$stripRow;

        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '000B6F']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF0FB']],
        ]);

        // Quantities and money read as figures, matching the body columns the
        // strip totals.
        foreach ($columns as $heading => $letter) {
            // Money two decimals; a quantity total its own per-cell format, so
            // a whole total never shows a trailing dot.
            $sheet->getStyle($letter.$stripRow)
                ->getNumberFormat()
                ->setFormatCode($heading === 'Closing Stock Value'
                    ? \App\Support\Quantity::EXCEL_MONEY
                    : \App\Support\Quantity::excelFormat((float) $sheet->getCell($letter.$stripRow)->getValue()));
        }

        $sheet->getRowDimension($stripRow)->setRowHeight(16);
    }

    /** The row carrying the column headings, found by a heading only it has. */
    private function locateHeaderRow(Worksheet $sheet): ?int
    {
        $limit = min($sheet->getHighestDataRow(), 40);

        for ($row = 1; $row <= $limit; $row++) {
            // SKU sits before Item Name, so the heading is in C or D.
            foreach (['C', 'D'] as $letter) {
                if (trim((string) $sheet->getCell($letter.$row)->getValue()) === 'Item Name') {
                    return $row;
                }
            }
        }

        return null;
    }

    /** The Sub-Total row below the data; its label lands in column A. */
    private function locateSubTotalRow(Worksheet $sheet, int $headerRow): ?int
    {
        $last = $sheet->getHighestDataRow();

        for ($row = $headerRow + 1; $row <= $last; $row++) {
            if (strcasecmp(trim((string) $sheet->getCell('A'.$row)->getValue()), 'Sub-Total') === 0) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Heading text => column letter, for the headings that were found. An exact
     * match, so "Date of Last Addition" is never mistaken for "Addition".
     *
     * @param  list<string>  $headings
     * @return array<string, string>
     */
    private function locateColumns(Worksheet $sheet, int $headerRow, array $headings): array
    {
        $wanted = array_flip($headings);
        $found = [];

        $lastIndex = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($index = 1; $index <= $lastIndex; $index++) {
            $letter = Coordinate::stringFromColumnIndex($index);
            $text = trim((string) $sheet->getCell($letter.$headerRow)->getValue());

            if (isset($wanted[$text]) && ! isset($found[$text])) {
                $found[$text] = $letter;
            }
        }

        return $found;
    }
}
