<?php

namespace App\Exports;

use App\Exports\Concerns\FormatsStoreSheet;
use App\Services\SupplyChainReportService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * Excel export for the Supply Chain report. Renders the same blade the screen
 * uses, so preview and download can never drift apart.
 *
 * Presentation comes from FormatsStoreSheet — the same trait the Store and
 * Merchandising reports use — so this prints A4 landscape with the navy header,
 * frozen pane and number formats the rest of the system already produces. No
 * new export style is introduced here.
 *
 * Unlike the PDF, this carries every column the report may show — a spreadsheet
 * scrolls sideways, a sheet of A4 does not.
 */
class SupplyChainReportExport implements FromView, WithEvents, WithTitle
{
    use FormatsStoreSheet;

    /**
     * @param  array<string, string|null>  $filters
     * @param  Collection<int, \App\Models\ExcelHeader>  $columns
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    public function __construct(
        private readonly array $filters,
        private readonly Collection $columns,
        private readonly Collection $rows,
        private readonly SupplyChainReportService $reports,
    ) {
    }

    public function view(): \Illuminate\Contracts\View\View
    {
        return view('supply-chain.reports.export', [
            'filters' => $this->filters,
            'columns' => $this->columns,
            'rows' => $this->rows,
            'reports' => $this->reports,
            'title' => 'Supply Chain Report',
        ]);
    }

    public function title(): string
    {
        return 'Supply Chain';
    }

    /**
     * @return array<string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => fn (AfterSheet $event) => $this->formatStoreSheet($event->sheet->getDelegate()),
        ];
    }
}
