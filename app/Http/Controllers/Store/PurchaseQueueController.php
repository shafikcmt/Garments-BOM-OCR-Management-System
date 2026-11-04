<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\PurchaseQueueService;
use Illuminate\Http\Request;

/**
 * General Stock purchasing: the "To Purchase" queue. Approved requisition lines
 * nobody has put on a Comparative Statement yet. The Supply Chain General desk
 * ticks lines here and starts a CS from them. Read-only.
 */
class PurchaseQueueController extends Controller
{
    public function __construct(private readonly PurchaseQueueService $queue)
    {
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'type' => ['nullable', 'in:final,individual'],
            'q' => ['nullable', 'string', 'max:100'],
            'requisition' => ['nullable', 'integer'],
        ]);

        $lines = $this->queue->lines($filters);

        return view('store.purchasing.queue', [
            'lines' => $lines->groupBy('purchase_requisition_id'),
            'total' => $lines->count(),
            'filters' => $filters,
            'canCreate' => $request->user()->can('store.purchase_cs.create'),
        ]);
    }
}
