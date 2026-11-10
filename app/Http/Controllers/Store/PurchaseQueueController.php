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
            'category' => ['nullable', 'string', 'max:120'],
        ]);

        // Categories are listed from everything waiting, so picking one never hides the others from the list.
        $categories = $this->queue->lines(array_diff_key($filters, ['category' => 1]))
            ->groupBy('category_name')->map->count()->sortKeys();

        $lines = $this->queue->lines($filters);

        return view('store.purchasing.queue', [
            'groups' => $lines->sortBy(fn ($l) => $l->category_name.'|'.$l->purchase_requisition_id.'|'.$l->sort_order)->groupBy('category_name'),
            'categories' => $categories,
            'total' => $lines->count(),
            'filters' => $filters,
            'canCreate' => $request->user()->can('store.purchase_cs.create'),
        ]);
    }
}
