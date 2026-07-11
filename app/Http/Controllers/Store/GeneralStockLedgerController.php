<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\StockItem;
use App\Models\StockIssue;
use App\Models\StockPurchase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * General Stock (module A) — monthly ledger (Excel "Stock <Month>" sheet).
 * Opening (prior-month closing) + Addition − Consumption = Closing. Built as a
 * live query: the general-stock event volume is light, so no cached table.
 */
class GeneralStockLedgerController extends Controller
{
    public function index(Request $request)
    {
        // Month selector — defaults to the current month.
        $month = $request->input('month', now()->format('Y-m'));
        try {
            $monthStart = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        } catch (\Throwable $e) {
            $monthStart = now()->startOfMonth();
        }
        $monthEnd = (clone $monthStart)->endOfMonth();

        $items = StockItem::orderBy('name')->get();

        // Purchases/issues up to end of month, and within month, grouped by item.
        $purchaseToDate = StockPurchase::whereDate('purchase_date', '<=', $monthEnd)
            ->selectRaw('stock_item_id, SUM(qty) as qty')->groupBy('stock_item_id')->pluck('qty', 'stock_item_id');
        $purchaseBefore = StockPurchase::whereDate('purchase_date', '<', $monthStart)
            ->selectRaw('stock_item_id, SUM(qty) as qty')->groupBy('stock_item_id')->pluck('qty', 'stock_item_id');
        $purchaseInMonth = StockPurchase::whereBetween('purchase_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->selectRaw('stock_item_id, SUM(qty) as qty')->groupBy('stock_item_id')->pluck('qty', 'stock_item_id');

        $issueBefore = StockIssue::whereNotNull('stock_item_id')->whereDate('issue_date', '<', $monthStart)
            ->selectRaw('stock_item_id, SUM(qty) as qty')->groupBy('stock_item_id')->pluck('qty', 'stock_item_id');
        $issueInMonth = StockIssue::whereNotNull('stock_item_id')
            ->whereBetween('issue_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->selectRaw('stock_item_id, SUM(qty) as qty')->groupBy('stock_item_id')->pluck('qty', 'stock_item_id');

        $rows = $items->map(function (StockItem $item) use ($purchaseBefore, $purchaseInMonth, $issueBefore, $issueInMonth) {
            $opening = (float) ($purchaseBefore[$item->id] ?? 0) - (float) ($issueBefore[$item->id] ?? 0);
            $addition = (float) ($purchaseInMonth[$item->id] ?? 0);
            $consumption = (float) ($issueInMonth[$item->id] ?? 0);
            $closing = $opening + $addition - $consumption;

            $reorderLevel = $item->reorder_level !== null ? (float) $item->reorder_level : null;
            $safety = $item->safety_stock_qty !== null ? (float) $item->safety_stock_qty : null;

            // Re-order flag: closing at or below re-order level (or safety stock).
            $threshold = $reorderLevel ?? $safety;
            $needsReorder = $threshold !== null && $closing <= $threshold;

            return [
                'item' => $item,
                'opening' => $opening,
                'addition' => $addition,
                'consumption' => $consumption,
                'closing' => $closing,
                'reorder_level' => $reorderLevel,
                'safety' => $safety,
                'needs_reorder' => $needsReorder,
            ];
        });

        $reorderCount = $rows->where('needs_reorder', true)->count();

        return view('store.stock.ledger', [
            'rows' => $rows,
            'month' => $monthStart->format('Y-m'),
            'monthLabel' => $monthStart->format('F Y'),
            'reorderCount' => $reorderCount,
        ]);
    }
}
