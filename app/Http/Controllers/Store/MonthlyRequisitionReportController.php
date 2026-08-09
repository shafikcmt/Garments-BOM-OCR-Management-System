<?php

namespace App\Http\Controllers\Store;

use App\Exports\MonthlyRequisitionReportExport;
use App\Http\Controllers\Controller;
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
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('store.stock.requisitions.report-pdf', $data)
            ->setPaper('a4', 'landscape');

        return $pdf->download($this->filename($data['month']).'.pdf');
    }

    public function excel(Request $request)
    {
        $data = $this->build($request);

        return Excel::download(
            new MonthlyRequisitionReportExport($data),
            $this->filename($data['month']).'.xlsx',
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
        $filters = $request->validate([
            'month' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:160'],
            'search' => ['nullable', 'string', 'max:100'],
            'merge' => ['nullable', 'boolean'],
        ]);

        $monthStart = $this->report->resolveMonth($filters['month'] ?? null);

        // Off by default: the month reads as one line per requisition, with its
        // Requisition No visible, so a figure can always be traced back to the
        // document it came from. Turning it on collapses the month to one line
        // per item, which is the shape the reference workbook prints and the
        // shape someone actually places an order from.
        $merge = (bool) ($filters['merge'] ?? false);

        $rows = $this->report->rows($monthStart, [
            'category' => $filters['category'] ?? null,
            'search' => $filters['search'] ?? null,
        ], $merge);

        return [
            'rows' => $rows,
            'byCategory' => $this->report->byCategory($rows),
            'summary' => $this->report->summary($rows),
            'categories' => $this->report->categoriesInMonth($monthStart),
            'filters' => $filters,
            'merge' => $merge,
            'month' => $monthStart->format('Y-m'),
            'monthLabel' => $monthStart->format('F Y'),
            'title' => 'Purchase Requisition',
        ];
    }

    private function filename(string $month): string
    {
        return 'Purchase-Requisition-'.Str::slug($month);
    }
}
