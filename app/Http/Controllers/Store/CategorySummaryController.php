<?php

namespace App\Http\Controllers\Store;

use App\Exports\CategorySummaryExport;
use App\Http\Controllers\Controller;
use App\Models\PurchaseRequisition;
use App\Services\CategorySummary;
use App\Services\MonthlyRequisitionReportService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Category-wise requisition summary: one screen for management, with PDF and
 * Excel downloads of the same figures. Read-only; open to whoever may open the
 * Monthly Report (the Store's side, Admin and Management).
 */
class CategorySummaryController extends Controller
{
    public function __construct(
        private readonly CategorySummary $summary,
        private readonly MonthlyRequisitionReportService $report,
    ) {
    }

    public function index(Request $request)
    {
        return view('store.stock.requisitions.category-summary', $this->data($request));
    }

    public function pdf(Request $request)
    {
        $data = $this->data($request);

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('store.stock.requisitions.category-summary-pdf', $data + ['forExcel' => false])
            ->setPaper('a4', 'portrait')
            ->download('Category-Summary-'.$data['month'].'.pdf');
    }

    public function excel(Request $request)
    {
        $data = $this->data($request);

        return Excel::download(new CategorySummaryExport($data), 'Category-Summary-'.$data['month'].'.xlsx');
    }

    /** @return array<string, mixed> */
    private function data(Request $request): array
    {
        abort_unless(
            PurchaseRequisition::canViewCategorySummary($request->user()),
            403,
            'The category summary is available to the Store team, management and anyone given the Category Summary permission.'
        );

        $monthStart = $this->report->resolveMonth($request->validate(['month' => ['nullable', 'string']])['month'] ?? null);

        return $this->summary->build($monthStart) + [
            'month' => $monthStart->format('Y-m'),
            'monthLabel' => $monthStart->format('F Y'),
            'previousLabel' => $monthStart->copy()->subMonthNoOverflow()->format('F Y'),
        ];
    }
}
