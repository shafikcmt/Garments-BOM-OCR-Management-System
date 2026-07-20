<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\MaterialBulkIssue;
use App\Models\MaterialReceiving;
use App\Models\MaterialRequisition;
use App\Models\MaterialRequisitionItem;
use App\Models\MaterialStockLedger;
use App\Models\StockItem;
use App\Models\StockIssue;
use App\Models\StockPurchase;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        // General Stock: live current stock per item = purchases - issues.
        $items = StockItem::withSum('purchases as purchased_qty', 'qty')
            ->withSum('issues as issued_qty', 'qty')
            ->get();

        // Reusable helper: current stock and re-order threshold per item.
        $stockLevels = $items->map(function (StockItem $item) {
            $current = (float) $item->purchased_qty - (float) $item->issued_qty;
            $threshold = $item->reorder_level ?? $item->safety_stock_qty;

            return [
                'name' => $item->name,
                'code' => $item->code,
                'uom' => $item->uom,
                'current' => $current,
                'threshold' => $threshold !== null ? (float) $threshold : null,
                'low' => $threshold !== null && $current <= (float) $threshold,
            ];
        })
            // Low stock first, then lowest quantity, so attention items lead.
            ->sortBy([['low', 'desc'], ['current', 'asc']])
            ->values();

        $reorderCount = $stockLevels->where('low', true)->count();

        // Requisition flow (display-only): shortfalls from the line items. These
        // never mutate stock — actual IN/OUT stays in the existing screens.
        $pendingReqLines = MaterialRequisitionItem::whereColumn('issued_qty', '<', 'required_qty')->count();
        $pendingReqQty = (float) MaterialRequisitionItem::whereColumn('issued_qty', '<', 'required_qty')
            ->sum(DB::raw('required_qty - issued_qty'));

        $pendingRecvLines = MaterialRequisitionItem::whereColumn('received_qty', '<', 'issued_qty')->count();
        $pendingRecvQty = (float) MaterialRequisitionItem::whereColumn('received_qty', '<', 'issued_qty')
            ->sum(DB::raw('issued_qty - received_qty'));

        $stats = [
            'stock_items' => $items->count(),
            'reorder_count' => $reorderCount,
            'material_lines' => MaterialStockLedger::count(),
            'running_qty' => (float) MaterialStockLedger::sum('running_closing_qty'),
            'liability_qty' => (float) MaterialStockLedger::sum('liability_closing_qty'),
            'dead_qty' => (float) MaterialStockLedger::sum('dead_closing_qty'),
            'pending_requisitions' => MaterialRequisition::where('status', MaterialRequisition::STATUS_PENDING)->count(),
            'pending_req_lines' => $pendingReqLines,
            'pending_req_qty' => $pendingReqQty,
            'pending_recv_lines' => $pendingRecvLines,
            'pending_recv_qty' => $pendingRecvQty,
        ];

        $recentActivity = $this->recentActivity();

        $metrics = app(\App\Services\DashboardMetricsService::class);
        $trend = $metrics->monthlyTrend(MaterialReceiving::query(), 6, 'receive_date');
        $delta = $metrics->deltaFor($trend);

        return view('store.dashboard', compact(
            'stats',
            'stockLevels',
            'recentActivity',
            'trend',
            'delta'
        ));
    }

    /**
     * Merge the latest rows from the four real stock-movement tables into one
     * normalized, read-only feed (module A: StockIssue/StockPurchase, module B:
     * MaterialBulkIssue/MaterialReceiving). Never writes anything.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function recentActivity()
    {
        $receivings = MaterialReceiving::latest('id')->take(8)->get()->map(fn ($r) => [
            'direction' => 'in',
            'module' => 'Buyer/Style',
            'label' => trim(collect([$r->po_no, $r->material_description])->filter()->implode(' · ')) ?: 'Receiving',
            'qty' => (float) $r->qty,
            'uom' => $r->uom,
            'date' => $r->receive_date ?? $r->created_at,
        ]);

        $bulkIssues = MaterialBulkIssue::latest('id')->take(8)->get()->map(fn ($i) => [
            'direction' => 'out',
            'module' => 'Buyer/Style',
            'label' => trim(collect([$i->po_no, $i->material_description])->filter()->implode(' · ')) ?: 'Bulk Issue',
            'qty' => (float) $i->bulk_qty + (float) $i->sample_qty + (float) $i->liability_qty + (float) $i->dead_qty,
            'uom' => $i->uom,
            'date' => $i->issue_date ?? $i->created_at,
        ]);

        $purchases = StockPurchase::with('stockItem')->latest('id')->take(8)->get()->map(fn ($p) => [
            'direction' => 'in',
            'module' => 'General',
            'label' => optional($p->stockItem)->name ?: 'Purchase',
            'qty' => (float) $p->qty,
            'uom' => optional($p->stockItem)->uom,
            'date' => $p->purchase_date ?? $p->created_at,
        ]);

        $issues = StockIssue::with('stockItem')->latest('id')->take(8)->get()->map(fn ($s) => [
            'direction' => 'out',
            'module' => 'General',
            'label' => optional($s->stockItem)->name ?: ($s->item_description ?: 'Issue'),
            'qty' => (float) $s->qty,
            'uom' => optional($s->stockItem)->uom,
            'date' => $s->issue_date ?? $s->created_at,
        ]);

        return $receivings
            ->concat($bulkIssues)
            ->concat($purchases)
            ->concat($issues)
            ->sortByDesc(fn ($row) => optional($row['date'])->timestamp ?? 0)
            ->take(10)
            ->values();
    }
}
