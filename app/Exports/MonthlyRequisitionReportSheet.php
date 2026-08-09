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
     * @param  Collection<int, array<string, mixed>>  $rows  the lines this sheet prints
     */
    public function __construct(
        private readonly array $data,
        private readonly string $sheet,
        private readonly Collection $rows,
    ) {
    }

    public function view(): \Illuminate\Contracts\View\View
    {
        return view('store.stock.requisitions.report-pdf', array_merge($this->data, [
            'forExcel' => true,
            // One sheet prints one section, so the blade is handed just that
            // section rather than deciding for itself what to draw.
            'sections' => [$this->sheet => $this->rows],
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
            AfterSheet::class => fn (AfterSheet $event) => $this->formatStoreSheet($event->sheet->getDelegate()),
        ];
    }
}
