<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\MaterialRequisition;
use App\Models\MaterialStockLedger;
use App\Models\StockItem;

class DashboardController extends Controller
{
    public function index()
    {
        // General Stock: count items at/below re-order level (live per item).
        $items = StockItem::withSum('purchases as purchased_qty', 'qty')
            ->withSum('issues as issued_qty', 'qty')
            ->get();

        $reorderCount = $items->filter(function (StockItem $item) {
            $current = (float) $item->purchased_qty - (float) $item->issued_qty;
            $threshold = $item->reorder_level ?? $item->safety_stock_qty;

            return $threshold !== null && $current <= (float) $threshold;
        })->count();

        $stats = [
            'stock_items' => $items->count(),
            'reorder_count' => $reorderCount,
            'material_lines' => MaterialStockLedger::count(),
            'running_qty' => (float) MaterialStockLedger::sum('running_closing_qty'),
            'liability_qty' => (float) MaterialStockLedger::sum('liability_closing_qty'),
            'dead_qty' => (float) MaterialStockLedger::sum('dead_closing_qty'),
            'pending_requisitions' => MaterialRequisition::where('status', MaterialRequisition::STATUS_PENDING)->count(),
        ];

        return view('store.dashboard', compact('stats'));
    }
}
