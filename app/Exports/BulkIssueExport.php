<?php

namespace App\Exports;

use App\Exports\Concerns\FormatsStoreSheet;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * Excel export for a selection of Bulk Issue rows. Renders the same blade the
 * PDF/preview reuse so download and on-screen output can never drift apart
 * (same approach as StoreReportExport).
 *
 * Presentation comes from FormatsStoreSheet, the same trait the other Store
 * reports use, so this register prints A4 landscape and reads like the rest of
 * them. ShouldAutoSize is gone with it: the trait measures and sets the widths
 * itself, and the two fight over the column dimensions when both are on.
 */
class BulkIssueExport implements FromView, WithEvents, WithTitle
{
    use FormatsStoreSheet;

    /**
     * @param  Collection<int, \App\Models\MaterialBulkIssue>  $issues
     */
    public function __construct(private readonly Collection $issues)
    {
    }

    public function view(): \Illuminate\Contracts\View\View
    {
        return view('store.material-stock.bulk-issues-export', [
            'issues' => $this->issues,
        ]);
    }

    public function title(): string
    {
        return 'Bulk Issuing';
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
