<?php

namespace App\Exports;

use App\Exports\Concerns\FormatsStoreSheet;
use App\Services\StoreReportService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * Excel export for the store reports. Renders the same blade the screen and PDF
 * use, so preview and download can never drift apart.
 *
 * Presentation comes from FormatsStoreSheet, so this prints A4 landscape and
 * matches the other Store reports. ShouldAutoSize is gone with it: the trait
 * sets the column widths itself, and the two contradict each other when both
 * are on.
 */
class StoreReportExport implements FromView, WithEvents, WithTitle
{
    use FormatsStoreSheet;

    /**
     * @param  array<string, string|null>  $filters
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, float>  $totals
     */
    public function __construct(
        private readonly string $type,
        private readonly array $filters,
        private readonly Collection $rows,
        private readonly array $totals,
    ) {
    }

    public function view(): \Illuminate\Contracts\View\View
    {
        return view('store.reports.export', [
            'type' => $this->type,
            'filters' => $this->filters,
            'rows' => $this->rows,
            'totals' => $this->totals,
            'groupHeading' => StoreReportService::groupHeading($this->type),
            'title' => StoreReportService::types()[$this->type] . ' Stock Report',
        ]);
    }

    public function title(): string
    {
        return StoreReportService::types()[$this->type];
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
