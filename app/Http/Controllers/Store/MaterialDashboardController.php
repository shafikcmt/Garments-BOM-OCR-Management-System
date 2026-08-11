<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\MaterialBulkIssue;
use App\Models\MaterialReceiving;
use App\Models\MaterialRequisition;
use App\Models\MaterialRequisitionItem;
use App\Models\MaterialStockLedger;
use App\Services\DashboardMetricsService;
use Illuminate\Support\Facades\DB;

/**
 * Buyer / Style Stock dashboard.
 *
 * These figures all lived on the old combined Store dashboard, where they were
 * the majority of the screen and were shown to General Stock users who cannot
 * open a single Buyer / Style screen. Nothing here is recalculated — every
 * query is the one that produced the same card before, moved to the module it
 * reads from.
 */
class MaterialDashboardController extends Controller
{
    public function index()
    {
        // Requisition flow, display only: these never mutate stock. A line is
        // outstanding while less has been issued than was required, and
        // separately while less has been received than was issued.
        $pendingReqLines = MaterialRequisitionItem::whereColumn('issued_qty', '<', 'required_qty')->count();
        $pendingReqQty = (float) MaterialRequisitionItem::whereColumn('issued_qty', '<', 'required_qty')
            ->sum(DB::raw('required_qty - issued_qty'));

        $pendingRecvLines = MaterialRequisitionItem::whereColumn('received_qty', '<', 'issued_qty')->count();
        $pendingRecvQty = (float) MaterialRequisitionItem::whereColumn('received_qty', '<', 'issued_qty')
            ->sum(DB::raw('issued_qty - received_qty'));

        $stats = [
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

        $metrics = app(DashboardMetricsService::class);
        $trend = $metrics->monthlyTrend(MaterialReceiving::query(), 6, 'receive_date');
        $delta = $metrics->deltaFor($trend);

        return view('store.material-stock.dashboard', compact('stats', 'trend', 'delta') + [
            'recentActivity' => $this->recentActivity(),
        ]);
    }

    /**
     * Latest Buyer / Style movements, in and out.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function recentActivity()
    {
        $receivings = MaterialReceiving::latest('id')->take(10)->get()->map(fn ($r) => [
            'direction' => 'in',
            'label' => trim(collect([$r->po_no, $r->material_description])->filter()->implode(' · ')) ?: 'Receiving',
            'qty' => (float) $r->qty,
            'uom' => $r->uom,
            'date' => $r->receive_date ?? $r->created_at,
        ]);

        $bulkIssues = MaterialBulkIssue::latest('id')->take(10)->get()->map(fn ($i) => [
            'direction' => 'out',
            'label' => trim(collect([$i->po_no, $i->material_description])->filter()->implode(' · ')) ?: 'Bulk Issue',
            'qty' => (float) $i->bulk_qty + (float) $i->sample_qty + (float) $i->liability_qty + (float) $i->dead_qty,
            'uom' => $i->uom,
            'date' => $i->issue_date ?? $i->created_at,
        ]);

        return $receivings
            ->concat($bulkIssues)
            ->sortByDesc(fn ($row) => optional($row['date'])->timestamp ?? 0)
            ->take(10)
            ->values();
    }
}
