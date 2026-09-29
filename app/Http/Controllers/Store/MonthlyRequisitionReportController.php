<?php

namespace App\Http\Controllers\Store;

use App\Exports\MonthlyRequisitionReportExport;
use App\Http\Controllers\Controller;
use App\Models\PurchaseRequisition;
use App\Services\MonthlyRequisitionReportService;
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
        // The PDF carries what the Excel does: the switch's download tabs,
        // each with its own header, rows and signature boxes.
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('store.stock.requisitions.report-pdf', ['tabs' => $data['sheets']] + $data)
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
            'tab' => ['nullable', 'string', 'max:160'],
        ]);

        // A sheet belongs to the month of its requisition date; resolveMonth()
        // is the month-end-safe parse.
        $monthStart = $this->report->resolveMonth($filters['month'] ?? null);
        $view = $filters['view'] ?? MonthlyRequisitionReportService::VIEW_ALL;

        // Always one row per item (THE RULE — see the service). The ALL rows
        // drive the totals whatever the switch; the category and section tabs
        // add up to them.
        $rows = $this->report->rows($monthStart);

        // The switch picks the screen's tabs; a download follows it (ALL +
        // categories, or ALL + one per section). Every tab carries its own printed
        // header and signature boxes.
        $tabs = $this->withHeaders($this->report->tabs($monthStart, $view), $monthStart);
        $sheets = $this->withHeaders($this->report->exportTabs($monthStart, $view), $monthStart);

        // The selected tab lives in the URL; an unknown one falls back to the first.
        $activeTab = $tabs->contains(fn ($tab) => $tab['slug'] === ($filters['tab'] ?? null))
            ? $filters['tab']
            : ($tabs->first()['slug'] ?? null);

        return [
            'rows' => $rows,
            'tabs' => $tabs,
            'sheets' => $sheets,
            'activeTab' => $activeTab,
            'view' => $view,
            'viewLabels' => MonthlyRequisitionReportService::viewLabels(),
            'summary' => $this->report->summary($rows),
            'filters' => ['month' => $monthStart->format('Y-m'), 'view' => $view],
            'month' => $monthStart->format('Y-m'),
            'monthLabel' => $monthStart->format('F Y'),
            'title' => 'Purchase Requisition',
        ];
    }

    /**
     * Each tab with its URL slug, its printed header and signature boxes
     * (tabHeader()), and — for the screen only — the sheets it came from.
     *
     * @param  \Illuminate\Support\Collection<string, array{type: string, rows: \Illuminate\Support\Collection}>  $tabs
     */
    private function withHeaders(\Illuminate\Support\Collection $tabs, \Illuminate\Support\Carbon $monthStart): \Illuminate\Support\Collection
    {
        return $tabs->map(fn (array $tab, string $name) => $tab + [
            'slug' => $tab['type'] === MonthlyRequisitionReportService::TAB_ALL ? 'all' : (Str::slug($name) ?: md5($name)),
            'header' => $this->report->tabHeader($tab['type'], $name, $tab['rows'], $monthStart),
            'sources' => $this->report->requisitionNosLabel($tab['rows']),
        ]);
    }

    private function filename(string $month): string
    {
        return 'Purchase-Requisition-'.Str::slug($month);
    }
}
