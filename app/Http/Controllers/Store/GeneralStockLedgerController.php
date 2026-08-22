<?php

namespace App\Http\Controllers\Store;

use App\Exports\GeneralStockReportExport;
use App\Http\Controllers\Controller;
use App\Services\GeneralStockReportService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
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
            'suppliers' => $this->report->suppliers(),
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

        return $pdf->download($this->filename($data).'.pdf');
    }

    public function excel(Request $request)
    {
        $data = $this->build($request);

        return Excel::download(
            new GeneralStockReportExport($data),
            $this->filename($data).'.xlsx',
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

            // Custom period. Either half may be left blank; the service
            // completes the other from the month of the date that WAS typed,
            // so the two cannot be driven into a backwards range.
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],

            // Narrows which items are listed, never the figures on them.
            'supplier_id' => ['nullable', 'integer', 'exists:general_stock_suppliers,id'],
        ], [], [
            'from' => 'from date',
            'to' => 'to date',
            'supplier_id' => 'supplier',
        ]);

        $monthStart = $this->report->resolveMonth($filters['month'] ?? null);

        $period = $this->report->resolvePeriod(
            $monthStart,
            $filters['from'] ?? null,
            $filters['to'] ?? null,
        );

        $rows = $this->report->rows($monthStart, [
            'search' => $filters['search'] ?? null,
            'category' => $filters['category'] ?? null,
            'status' => $filters['status'] ?? null,
            'only_active' => ! ($filters['include_inactive'] ?? false),
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
            'supplier_id' => $filters['supplier_id'] ?? null,
        ]);

        return [
            'rows' => $rows,
            'summary' => $this->report->summary($rows),
            'filters' => $filters,
            'month' => $monthStart->format('Y-m'),
            // The heading, the export filename and the AJAX meta all read this
            // one key, so naming the period here is what makes a date range
            // show up on the screen, in the PDF and in the workbook without
            // three separate places deciding what to call it.
            'monthLabel' => $this->periodLabel($period),
            'period' => $period,
            // Resolved here rather than in the views: the PDF and the workbook
            // both name the applied filters, and neither should be doing a
            // model lookup to turn an id into something a reader recognises.
            // Looked up by id rather than picked out of suppliers(), which
            // lists active ones only: a report filtered to a supplier that has
            // since been deactivated still filters by it, and a heading that
            // quietly dropped the name would describe the wrong report.
            'supplierName' => $this->report->supplierName($filters['supplier_id'] ?? null),
            // Display name only. The screen sits under the General Stock menu,
            // which already says "consumable", so the label does not repeat it.
            // Route, controller and table names are unchanged.
            'title' => 'Stock Report',
        ];
    }

    /**
     * What the period is called on screen, in the PDF heading and in the
     * export filename.
     *
     * A whole month keeps its "August 2026" name exactly as before — the label
     * is not "1 Aug 2026 to 31 Aug 2026" just because a range could describe
     * it, because every export filename and every printed heading this report
     * has produced says the month.
     *
     * @param  array{start: Carbon, end: Carbon, is_range: bool}  $period
     */
    private function periodLabel(array $period): string
    {
        if (! $period['is_range']) {
            return $period['start']->format('F Y');
        }

        return $period['start']->format('d M Y').' to '.$period['end']->format('d M Y');
    }

    /**
     * A month export keeps the filename it has always had. Only a custom range
     * changes it, and it has to: two different periods downloading as the same
     * "Stock-Report-2026-08.xlsx" is how the wrong file gets emailed on.
     *
     * @param  array<string, mixed>  $data
     */
    private function filename(array $data): string
    {
        return 'Stock-Report-'.Str::slug(
            $data['period']['is_range'] ? $data['monthLabel'] : $data['month']
        );
    }
}
