<?php

namespace App\Http\Controllers\Store;

use App\Exports\GeneralStockReportExport;
use App\Http\Controllers\Controller;
use App\Services\GeneralStockReportService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
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
    /**
     * Rows per screen page. The report is read top-to-bottom rather than
     * clicked through, so the page is set long enough that a normal month's
     * item list is a handful of pages — short pages would only add clicks.
     *
     * Measured against the live 1,240-item set: building the rows costs ~127ms
     * and does not vary with this number, while rendering the fragment is 2-10ms
     * whether the page holds 100, 150 or 200. The server is indifferent, so the
     * limit is how much DOM the browser has to swap on a live filter — 150 rows
     * of a twenty-column table is ~258KB, which stays comfortable on the office
     * laptops this runs on.
     */
    private const PER_PAGE = 150;

    public function __construct(private readonly GeneralStockReportService $report)
    {
    }

    public function index(Request $request)
    {
        $data = $this->build($request);

        $data += [
            'statusLabels' => GeneralStockReportService::statusLabels(),
            'pageRows' => $this->paginateRows($data['rows'], $request),
        ];

        // Live search / filter change: the same action, returning only the part
        // of the page the filters affect. Sharing the action rather than adding
        // a second endpoint is what guarantees the AJAX result and the full page
        // come off the identical rows() call and the identical paging.
        if ($request->boolean('partial')) {
            return view('store.stock._ledger-rows', $data);
        }

        return view('store.stock.ledger', $data + [
            'categories' => $this->report->categories(),
            'actionList' => $this->report->actionList($data['rows']),
        ]);
    }

    /**
     * Screen-only paging. The report is computed row by row in PHP, not by a
     * query, so the page is sliced off the finished collection. `rows` itself
     * stays whole on purpose: the summary tiles, the totals row, the PDF and
     * the Excel must keep covering every item in the month, not just the
     * hundred currently on screen.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginateRows(Collection $rows, Request $request): LengthAwarePaginator
    {
        $page = LengthAwarePaginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $rows->forPage($page, self::PER_PAGE)->values(),
            $rows->count(),
            self::PER_PAGE,
            $page,
            // `partial` is a transport flag, not a filter — kept out of the
            // pager links so a "next page" href stays a real, shareable URL.
            ['path' => $request->url(), 'query' => Arr::except($request->query(), ['partial'])],
        );
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
