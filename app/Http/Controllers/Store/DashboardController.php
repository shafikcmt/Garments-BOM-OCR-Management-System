<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\PurchaseRequisition;
use App\Models\StockIssue;
use App\Models\StockPurchase;
use App\Services\GeneralStockReportService;

/**
 * General Stock dashboard — consumables, and nothing else.
 *
 * It used to be "the Store dashboard" and carried both modules plus the BOM
 * workspace. Seven of its nine cards read Buyer / Style Stock tables, which a
 * user on Store — General Stock has no permission to open: they were shown
 * closing quantities, requisition counts and receiving trends for a module
 * whose every screen refuses them. Splitting the screen is what fixes that;
 * the two modules now have a dashboard each, and each reads only its own
 * tables.
 *
 * Every figure here comes from the General Stock side: the consumable stock
 * report, its purchases and issues, and the purchase requisitions raised
 * against it.
 */
class DashboardController extends Controller
{
    public function index()
    {
        // Same service the Consumable Stock Report screen uses, so the
        // dashboard can never disagree with the report it links to.
        $report = app(GeneralStockReportService::class);
        $reportRows = $report->rows(now()->startOfMonth());
        $reportSummary = $report->summary($reportRows);

        $stockLevels = $report->actionList($reportRows)->map(fn (array $row) => [
            'name' => $row['item']->name,
            'category' => $row['item']->category,
            'uom' => $row['item']->uom,
            'current' => $row['stock_as_on'],
            'threshold' => $row['safety'],
            'status' => $row['status'],
        ]);

        $stats = [
            'stock_items' => $reportSummary['items'],
            // Anything not "Ok": out of stock, below safety, or below re-order.
            'reorder_count' => $stockLevels->count(),
            'out_of_stock' => $reportSummary['out'],
            // NEW on this screen. The old "Pending requisitions" card counted
            // MaterialRequisition, which belongs to Buyer / Style Stock and has
            // moved there with the rest of that module. General Stock raises
            // PurchaseRequisition instead, and had no requisition figure at all
            // until now. Pending means anything the flow has not finished with —
            // every status except the terminal one.
            'pending_requisitions' => PurchaseRequisition::where(
                'status', '!=', PurchaseRequisition::STATUS_PURCHASE_ACTION_TAKEN
            )->count(),
        ];

        return view('store.dashboard', compact('stats', 'stockLevels') + [
            'recentActivity' => $this->recentActivity(),
        ]);
    }

    /**
     * Latest consumable movements, in and out, as one read-only feed.
     *
     * General Stock tables only. The combined feed this replaced also merged
     * MaterialReceiving and MaterialBulkIssue rows, which is how Buyer / Style
     * PO numbers ended up on a General Stock screen.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function recentActivity()
    {
        $purchases = StockPurchase::with('stockItem')->latest('id')->take(10)->get()->map(fn ($p) => [
            'direction' => 'in',
            'label' => optional($p->stockItem)->name ?: 'Purchase',
            'qty' => (float) $p->qty,
            'uom' => optional($p->stockItem)->uom,
            'date' => $p->purchase_date ?? $p->created_at,
        ]);

        $issues = StockIssue::with('stockItem')->latest('id')->take(10)->get()->map(fn ($s) => [
            'direction' => 'out',
            'label' => optional($s->stockItem)->name ?: 'Issue',
            'qty' => (float) $s->qty,
            'uom' => optional($s->stockItem)->uom,
            'date' => $s->issue_date ?? $s->created_at,
        ]);

        return $purchases
            ->concat($issues)
            ->sortByDesc(fn ($row) => optional($row['date'])->timestamp ?? 0)
            ->take(10)
            ->values();
    }
}
