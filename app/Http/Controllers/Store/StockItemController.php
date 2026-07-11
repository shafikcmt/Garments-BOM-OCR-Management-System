<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\StockItem;
use Illuminate\Http\Request;

/**
 * General Stock (module A) — item master CRUD. General stock is fully
 * independent of BOM/PO. Store role only (gated in routes/store.php).
 */
class StockItemController extends Controller
{
    public function index(Request $request)
    {
        $items = StockItem::query()
            ->withSum('purchases as purchased_qty', 'qty')
            ->withSum('issues as issued_qty', 'qty')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('store.stock.items', compact('items'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['created_by'] = auth()->id();

        StockItem::create($data);

        return back()->with('success', 'Stock item "' . $data['name'] . '" added.');
    }

    public function update(Request $request, StockItem $stockItem)
    {
        $stockItem->update($this->validated($request));

        return back()->with('success', 'Stock item updated.');
    }

    public function destroy(StockItem $stockItem)
    {
        if ($stockItem->purchases()->exists() || $stockItem->issues()->exists()) {
            return back()->with('warning', 'Cannot delete: this item already has purchase or issue records. Mark it inactive instead.');
        }

        $stockItem->delete();

        return back()->with('success', 'Stock item removed.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:100'],
            'uom' => ['nullable', 'string', 'max:50'],
            'category' => ['nullable', 'string', 'max:100'],
            'safety_stock_qty' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'lead_time_days' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
