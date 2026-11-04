<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\GsPurchaseOrder;
use App\Services\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Record Receiving: pick the Internal PO the goods are for. Read-only JSON for
 * the receiving form. Sits behind the receiving section's own guard, so Store
 * needs no purchasing permission to receive against a PO.
 */
class ReceivingPoLookupController extends Controller
{
    public function __construct(private readonly PurchaseOrderService $service)
    {
    }

    /** Issued POs that still have something to receive, newest first. */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));

        $orders = GsPurchaseOrder::query()
            ->receivable()
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->whereLike('po_no', "%{$q}%")->orWhereLike('supplier_name', "%{$q}%")))
            ->latest('id')
            ->limit(20)
            ->get(['id', 'po_no', 'po_date', 'supplier_name', 'status', 'total_amount']);

        return response()->json($orders->map(fn (GsPurchaseOrder $o) => [
            'id' => $o->id,
            'po_no' => $o->po_no,
            'supplier' => $o->supplier_name,
            'date' => $o->po_date?->format('d-M-Y'),
            'status' => $o->statusLabel(),
        ]));
    }

    /** One PO's supplier and lines with what is still to come. */
    public function lines(GsPurchaseOrder $order): JsonResponse
    {
        abort_unless($order->isReceivable(), 404, 'This purchase order is not open for receiving.');

        $order->load('items');
        $received = $this->service->receivedByLine($order);

        return response()->json([
            'id' => $order->id,
            'po_no' => $order->po_no,
            'general_stock_supplier_id' => $order->general_stock_supplier_id,
            'supplier' => $order->supplier_name,
            'lines' => $order->items->map(function ($i) use ($received) {
                $done = $received[$i->id] ?? 0.0;

                return [
                    'po_item_id' => $i->id,
                    'stock_item_id' => $i->stock_item_id,
                    'item' => $i->item_name,
                    'uom' => $i->uom,
                    'ordered' => (float) $i->qty,
                    'received' => $done,
                    'remaining' => max(0.0, (float) $i->qty - $done),
                    'rate' => (float) $i->rate,
                ];
            })->values(),
        ]);
    }
}
