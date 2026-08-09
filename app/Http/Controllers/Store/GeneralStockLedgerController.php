<?php

namespace App\Http\Controllers\Store;

use App\Exports\GeneralStockReportExport;
use App\Http\Controllers\Controller;
use App\Services\GeneralStockReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * General Stock (module A) — the Consumable Stock Report (Excel "Stock <Month>"
 * sheet). Opening + Addition − Consumption = Stock as on Date, with the safety
 * stock / re-order levels and the Place Order flag.
 *
 * Every month is recomputed from the underlying Purchase and Consumption
 * records, so picking an older month gives that month's report and a backdated
 * entry corrects history rather than leaving a stale copy behind. All the
 * arithmetic lives in GeneralStockReportService.
 */
class GeneralStockLedgerController extends Controller
{
    public function __construct(private readonly GeneralStockReportService $report)
    {
    }

    public function index(Request $request)
    {
        $data = $this->build($request);

        return view('store.stock.ledger', $data + [
            'categories' => $this->report->categories(),
            'statusLabels' => GeneralStockReportService::statusLabels(),
            'actionList' => $this->report->actionList($data['rows']),
        ]);
    }

    public function pdf(Request $request)
    {
        $data = $this->build($request);

        // Landscape A4: the sheet is 19 columns wide and will not fit portrait.
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('store.stock.ledger-pdf', $data)
            ->setPaper('a4', 'landscape');

        return $pdf->download($this->filename($data['month']).'.pdf');
    }

    public function excel(Request $request)
    {
        $data = $this->build($request);

        return Excel::download(
            new GeneralStockReportExport($data),
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
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:attention,out,place_order,low,ok'],
            'include_inactive' => ['nullable', 'boolean'],
        ]);

        $monthStart = $this->report->resolveMonth($filters['month'] ?? null);

        $rows = $this->report->rows($monthStart, [
            'search' => $filters['search'] ?? null,
            'category' => $filters['category'] ?? null,
            'status' => $filters['status'] ?? null,
            'only_active' => ! ($filters['include_inactive'] ?? false),
        ]);

        return [
            'rows' => $rows,
            'summary' => $this->report->summary($rows),
            'filters' => $filters,
            'month' => $monthStart->format('Y-m'),
            'monthLabel' => $monthStart->format('F Y'),
            // Display name only. The screen sits under the General Stock menu,
            // which already says "consumable", so the label does not repeat it.
            // Route, controller and table names are unchanged.
            'title' => 'Stock Report',
        ];
    }

    private function filename(string $month): string
    {
        return 'Stock-Report-'.Str::slug($month);
    }
}
