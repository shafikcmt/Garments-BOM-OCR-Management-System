<?php

namespace App\Http\Controllers\Store;

use App\Exports\MonthlyRequisitionReportExport;
use App\Http\Controllers\Controller;
use App\Models\PurchaseRequisition;
use App\Models\RequisitionSignatureBlock;
use App\Services\MonthlyRequisitionReportService;
use App\Services\RequisitionSignatureResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * General Stock (module A) — the monthly Purchase Requisition report.
 *
 * Read-only, and entirely separate from the single-requisition screens: this
 * compiles what those screens recorded, it never writes.
 *
 * Replaces the "Month_Of_<Month>.xlsx" workbook — an "ALL" sheet plus one sheet
 * per category — so the Excel download is a multi-sheet workbook of exactly
 * that shape.
 */
class MonthlyRequisitionReportController extends Controller
{
    public function __construct(private readonly MonthlyRequisitionReportService $report)
    {
    }

    public function index(Request $request)
    {
        $data = $this->build($request);

        return view('store.stock.requisitions.report', $data);
    }

    public function pdf(Request $request)
    {
        $data = $this->build($request);

        // Landscape A4: the sheet is 21 columns wide and will not fit portrait.
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('store.stock.requisitions.report-pdf', $data)
            ->setPaper('a4', 'landscape');

        return $pdf->download($this->filename($data['month']).'.pdf');
    }

    public function excel(Request $request)
    {
        $data = $this->build($request);

        return Excel::download(
            new MonthlyRequisitionReportExport($data),
            $this->filename($data['month'])
                .($data['view'] === MonthlyRequisitionReportService::VIEW_SECTION ? '-section-wise' : '').'.xlsx',
        );
    }

    /**
     * Shared payload for screen, PDF and Excel, so the three can never drift
     * apart — the same rows and the same filter summary feed all of them.
     *
     * @return array<string, mixed>
     */
    private function build(Request $request): array
    {
        // The month is the WHOLE company's counted requisitions, unscoped. A
        // department user reaches this module on store.requisition.view for
        // their own list; this compiled view is the Store's — the same rule
        // as the Requested queue (worksStoreDesk).
        abort_unless(
            PurchaseRequisition::worksStoreDesk($request->user()),
            403,
            'The monthly requisition report is available to the Store team only.'
        );

        $filters = $request->validate([
            'month' => ['nullable', 'string'],
            'view' => ['nullable', 'string', 'in:'.implode(',', array_keys(MonthlyRequisitionReportService::viewLabels()))],
        ]);

        // A sheet belongs to the month of its requisition date; resolveMonth()
        // is the month-end-safe parse.
        $monthStart = $this->report->resolveMonth($filters['month'] ?? null);
        $view = $filters['view'] ?? MonthlyRequisitionReportService::VIEW_ALL;
        $filters = ['month' => $monthStart->format('Y-m'), 'view' => $view];

        // Always one row per item (THE RULE — see the service). The ALL rows
        // drive the totals whatever the view; the category and section views
        // add up to them.
        $rows = $this->report->rows($monthStart);
        $byCategory = $this->report->byCategory($rows);

        if ($view === MonthlyRequisitionReportService::VIEW_SECTION) {
            $tabs = $this->report->bySection($this->report->rows($monthStart, [], MonthlyRequisitionReportService::VIEW_SECTION));
            // Excel: one tab per section.
            $sheets = $tabs;
        } else {
            $tabs = $view === MonthlyRequisitionReportService::VIEW_CATEGORY ? $byCategory : collect(['ALL' => $rows]);
            // Excel: ALL, then one tab per category, as the reference workbook.
            $sheets = collect(['ALL' => $rows])->union($byCategory);
        }

        return [
            'rows' => $rows,
            'byCategory' => $byCategory,
            'tabs' => $tabs,
            'sheets' => $sheets,
            'view' => $view,
            'viewLabels' => MonthlyRequisitionReportService::viewLabels(),
            'summary' => $this->report->summary($rows),
            'filters' => $filters,
            // The printed header: the sheets included, and the section(s) —
            // never the category.
            'headerRequisitionNos' => $this->report->requisitionNosLabel($rows),
            'headerSection' => $view === MonthlyRequisitionReportService::VIEW_SECTION && $tabs->isNotEmpty()
                ? $tabs->keys()->implode(', ')
                : 'ALL',
            'month' => $monthStart->format('Y-m'),
            'monthLabel' => $monthStart->format('F Y'),
            'title' => 'Purchase Requisition',
            // The footer of the merged month is the FINAL sheet's signature
            // boxes from Admin > Settings — configured names only, since a
            // month is not one requisition and has no recorded stamps.
            'signatures' => app(RequisitionSignatureResolver::class)->forLevel(RequisitionSignatureBlock::LEVEL_FINAL),
        ];
    }

    private function filename(string $month): string
    {
        return 'Purchase-Requisition-'.Str::slug($month);
    }
}
