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
            'section' => ['nullable', 'integer'],
            'category' => ['nullable', 'string', 'max:120'],
            'group' => ['nullable', 'in:category,requisition'],
        ]);

        // Grouped by category, as before, unless asked for by requisition. Individual
        // requisitions default to by requisition: each is one department's one-off need.
        $group = $filters['group'] ?? (($filters['type'] ?? null) === 'individual' ? 'requisition' : 'category');
        $queueFilters = array_diff_key($filters, ['group' => 1]);

        // Categories are listed from everything waiting, so picking one never hides the others from the list.
        $categories = $this->queue->lines(array_diff_key($queueFilters, ['category' => 1]))
            ->groupBy('category_name')->map->count()->sortKeys();

        // The requisitions and sections to pick from: what is waiting, before those two filters.
        $waiting = $this->queue->lines(array_diff_key($queueFilters, ['requisition' => 1, 'section' => 1]));
        $requisitions = $waiting->pluck('requisition')->unique('id')->sortBy('requisition_no')->values();
        $sections = $waiting->pluck('requisition.indentSection')->filter()->unique('id')->sortBy('name')->values();

        $lines = $this->queue->lines($queueFilters);

        $sorted = $lines->sortBy(fn ($l) => ($group === 'requisition'
            ? $l->requisition?->requisition_no.'|'.$l->sort_order
            : $l->category_name.'|'.$l->purchase_requisition_id.'|'.$l->sort_order));

        return view('store.purchasing.queue', [
            'groups' => $group === 'requisition'
                ? $sorted->groupBy('purchase_requisition_id')
                : $sorted->groupBy('category_name'),
            'group' => $group,
            'categories' => $categories,
            'requisitions' => $requisitions,
            'sections' => $sections,
            'total' => $lines->count(),
            'filters' => $filters,
            'canCreate' => $request->user()->can('store.purchase_cs.create'),
        ]);
    }
}
