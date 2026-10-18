<?php

namespace App\Exports;

use App\Exports\Concerns\FormatsStoreSheet;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * One sheet of the monthly Purchase Requisition workbook — either "ALL" or a
 * single category.
 *
 * Renders the same blade the PDF uses, so a downloaded sheet can never
 * disagree with the printed page or the screen.
 *
 * FromView carries the data but none of the presentation, so the sheet is
 * styled afterwards by FormatsStoreSheet — the same styling the Consumable
 * Stock Report gets, so the two downloads match.
 *
 * ShouldAutoSize is deliberately NOT used: it measures the merged "Stock
 * Details" / "Last Purchase Details" headings badly, widening the first column
 * of each group and leaving the rest at default, which is most of why this
 * workbook looked misaligned. FormatsStoreSheet sets widths from the content.
 */
class MonthlyRequisitionReportSheet implements FromView, WithEvents, WithTitle
{
    use FormatsStoreSheet;

    /**
     * @param  array<string, mixed>  $data  payload from MonthlyRequisitionReportController
     * @param  array{type: string, rows: Collection, header?: array}  $tab  the tab this sheet prints
     */
    public function __construct(
        private readonly array $data,
        private readonly string $sheet,
        private readonly array $tab,
    ) {
    }

    public function view(): \Illuminate\Contracts\View\View
    {
        return view('store.stock.requisitions.report-pdf', array_merge($this->data, [
            'forExcel' => true,
            // One sheet prints one section, so the blade is handed just that
            // section rather than deciding for itself what to draw.
            'tabs' => [$this->sheet => $this->tab],
        ]));
    }

    /**
     * Excel sheet names cannot exceed 31 characters or contain : \ / ? * [ ].
     * Category names come from a master people type into, so both are possible.
     */
    public function title(): string
    {
        $clean = str_replace([':', '\\', '/', '?', '*', '[', ']'], '-', $this->sheet);

        return Str::limit(trim($clean) ?: 'Sheet', 31, '');
    }

    /** @return array<string, callable> */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $this->formatStoreSheet($sheet);
                $this->writeFormulas($sheet);
            },
        ];
    }

    /**
     * To Be Procured Qty and Amount as live formulas, with the Total summing
     * the Amounts, so a figure changed in Excel carries through (the reference
     * workbook works this way). Only the ALL and category tabs: a section tab's
     * stock is allocated across sections in the system, which a plain
     * Requested − Stock formula would not reproduce. Rate and Safety Stock
     * stay values — they are not derived from other cells here.
     *
     *   N  To Be Procured Qty = MAX(0, G − H)   (never negative, as in the app)
     *   P  Amount             = N × O
     */
    private function writeFormulas(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
    {
        if (($this->tab['type'] ?? null) === \App\Services\MonthlyRequisitionReportService::TAB_SECTION) {
            return;
        }

        $last = $sheet->getHighestRow();
        $first = null;

        for ($r = 1; $r <= $last; $r++) {
            if (trim((string) $sheet->getCell('A'.$r)->getValue()) === 'SL') {
                $first = $r + 2; // the heading is two rows tall

                break;
            }
        }

        if ($first === null) {
            return;
        }

        $row = $first;

        while ($row <= $last && is_numeric($sheet->getCell('A'.$row)->getValue())) {
            $sheet->setCellValue('N'.$row, '=MAX(0,G'.$row.'-H'.$row.')');
            $sheet->setCellValue('P'.$row, '=N'.$row.'*O'.$row);
            $row++;
        }

        if ($row === $first) {
            return;
        }

        for ($r = $row; $r <= min($last, $row + 2); $r++) {
            if (str_starts_with(trim((string) $sheet->getCell('A'.$r)->getValue()), 'Total')) {
                $sheet->setCellValue('P'.$r, '=SUM(P'.$first.':P'.($row - 1).')');

                break;
            }
        }
    }
}
