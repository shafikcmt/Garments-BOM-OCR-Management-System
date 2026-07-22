<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Excel export for a selection of Bulk Issue rows. Renders the same blade the
 * PDF/preview reuse so download and on-screen output can never drift apart
 * (same approach as StoreReportExport).
 */
class BulkIssueExport implements FromView, ShouldAutoSize, WithTitle
{
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
}
