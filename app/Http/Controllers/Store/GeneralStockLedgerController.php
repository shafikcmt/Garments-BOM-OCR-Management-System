<?php

namespace App\Http\Controllers\Store;

use App\Exports\GeneralStockCombinedExport;
use App\Exports\GeneralStockReportExport;
use App\Exports\IssueReportExport;
use App\Exports\ReceivingReportExport;
use App\Http\Controllers\Concerns\AuthorizesStoreCorrections;
use App\Http\Controllers\Controller;
use App\Models\ItemCategory;
use App\Services\GeneralStockReportService;
use App\Services\IssueReportService;
use App\Services\ReceivingReportService;
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
     * Only to decide whether the reference-data Import control is shown.
     * The import itself lives in StockReferenceImportController and does
     * its own check — hiding a button is presentation, never a guard.
     */
    use AuthorizesStoreCorrections;

    /** Section-scoped correction permission for this screen. */
    protected string $storeSection = 'store.stock_report';

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

    /**
     * The two extra services are for the combined workbook only — every other
     * action on this controller uses $report alone. They are injected rather
     * than resolved inside excelAll() so the dependency is visible and the
     * action stays testable.
     */
    public function __construct(
        private readonly GeneralStockReportService $report,
        private readonly ReceivingReportService $receiving,
        private readonly IssueReportService $issues,
    ) {
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
            'canImport' => $this->storeCorrectionAbilities()['edit'],
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

    /*
    |--------------------------------------------------------------------------
    | The combined workbook: Stock + Receiving + Issues in one file
    |--------------------------------------------------------------------------
    */

    /**
     * One .xlsx carrying all three Store reports as tabs, under the filters
     * currently on this screen.
     *
     * Each tab is the SAME export class its own screen downloads, so a tab and
     * the standalone file cannot disagree. What this action owns is the two
     * things composition cannot answer by itself: which sheets this user is
     * allowed to receive, and how a filter written for the Stock Report should
     * be read against receipts and issues.
     */
    public function excelAll(Request $request)
    {
        $stock = $this->build($request);
        $filters = $stock['filters'];

        $sheets = [];
        $omitted = [];

        // Sheet 1 is always present: the route already sits behind this
        // screen's own guard, so anyone reaching it may have the Stock report.
        $sheets[] = new GeneralStockReportExport($stock);

        // The three reports sit behind three DIFFERENT section guards. A user
        // holding only store.stock_report.view can open this screen but not the
        // Receiving or Issue report, and a combined download must not be the
        // way around that. So each further sheet is built only if that
        // section's own rule is satisfied, and anything withheld is named on
        // the Stock sheet rather than silently missing — a workbook that is
        // quietly short of a tab gets read as "there were no receipts".
        if ($this->satisfiesSection('store.receiving.view')) {
            $sheets[] = new ReceivingReportExport(
                $this->receiving->reportPayload($this->receivingFilters($filters))
            );
        } else {
            $omitted[] = 'Receiving';
        }

        if ($this->satisfiesSection('store.issues.view')) {
            $sheets[] = new IssueReportExport(
                $this->issues->reportPayload($this->issueFilters($filters))
            );
        } else {
            $omitted[] = 'Issues';
        }

        // Re-rendered with the note attached, so the first tab says what the
        // workbook does not contain.
        if ($omitted !== []) {
            $sheets[0] = new GeneralStockReportExport($stock + [
                'omittedSheets' => $omitted,
            ]);
        }

        return Excel::download(
            new GeneralStockCombinedExport($sheets),
            $this->filename($stock).'-All.xlsx',
        );
    }

    /**
     * Mirrors the route middleware's own rule, `role_or_perm:store|admin|
     * management|<section>.view` — Spatie checks canAny() OR hasAnyRole() over
     * the whole pipe-separated list, so this checks the same list the same way.
     * Written out rather than inferred so a change to the route guard is an
     * obvious change here too.
     */
    private function satisfiesSection(string $permission): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        $tokens = ['store', 'admin', 'management', $permission];

        return $user->canAny($tokens) || $user->hasAnyRole($tokens);
    }

    /**
     * The Stock Report's filters, read against RECEIPTS.
     *
     * month / from / to / search carry over as they are — the Receiving service
     * applies them to purchase_date and to challan, RV, supplier and item name.
     *
     * supplier_id becomes a NAME, because that is the only supplier filter the
     * Receiving report has: it matches `supplier_name LIKE %name%` on the
     * denormalised column. That is a looser test than the Stock sheet's exact
     * general_stock_supplier_id, so a vendor whose name is a prefix of another
     * ("ABC" inside "ABC Traders") would pull both. Named here rather than
     * hidden: the Receiving tab prints its own filter line, so the workbook
     * says which supplier string it matched on.
     *
     * category, status and include_inactive are dropped. Receiving has no
     * category filter at all; status and include_inactive describe an ITEM's
     * stock position, which a delivery does not have.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function receivingFilters(array $filters): array
    {
        return array_filter([
            'month' => $filters['month'] ?? null,
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
            'search' => $filters['search'] ?? null,
            'supplier' => $this->report->supplierName($filters['supplier_id'] ?? null),
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * The Stock Report's filters, read against ISSUES.
     *
     * month / from / to / search carry over — applied to issue_date, and to
     * requisition_no and item name.
     *
     * category has to be TRANSLATED. The Stock Report filters the string column
     * stock_items.category; the Issue report filters stock_issues
     * .item_category_id, a foreign key into item_categories. They are the same
     * vocabulary stored two ways — every distinct stock_items.category value
     * exists in item_categories.name, names there are unique, and no item's
     * string disagrees with its own foreign key — so the name resolves to
     * exactly one id.
     *
     * supplier is dropped, and cannot be otherwise: stock_issues carries no
     * supplier, so consumption cannot be attributed to a vendor. With a
     * supplier chosen the Issues tab therefore covers more than the Stock tab,
     * which is why it prints its own filter line. status and include_inactive
     * are dropped for the same reason as on Receiving.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function issueFilters(array $filters): array
    {
        $category = $filters['category'] ?? null;

        return array_filter([
            'month' => $filters['month'] ?? null,
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
            'search' => $filters['search'] ?? null,
            // Exact match, not whereLike: this is an id lookup on a value that
            // came out of the same database, so there is nothing to be
            // case-insensitive about and ILIKE would only risk a second hit.
            'category' => $category
                ? ItemCategory::where('name', $category)->value('id')
                : null,
        ], fn ($value) => $value !== null && $value !== '');
    }
}
