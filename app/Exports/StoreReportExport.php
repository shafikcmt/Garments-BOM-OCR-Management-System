<?php

namespace App\Exports;

use App\Services\StoreReportService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Excel export for the store reports. Renders the same blade the screen and PDF
 * use, so preview and download can never drift apart.
 */
class StoreReportExport implements FromView, ShouldAutoSize, WithTitle
{
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
}
