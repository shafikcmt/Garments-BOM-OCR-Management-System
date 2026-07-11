<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\StockItem;
use App\Models\StockPurchase;
use Illuminate\Http\Request;

/**
 * General Stock (module A) — challan-level receive (Excel "Purchase" sheet).
 */
class StockPurchaseController extends Controller
{
    public function index(Request $request)
    {
        $purchases = StockPurchase::with(['stockItem', 'createdBy'])
            ->latest('purchase_date')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $items = StockItem::where('is_active', true)->orderBy('name')->get();

        return view('store.stock.purchases', compact('purchases', 'items'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'stock_item_id' => ['required', 'exists:stock_items,id'],
            'challan_no' => ['nullable', 'string', 'max:100'],
            'purchase_date' => ['required', 'date'],
            'qty' => ['required', 'numeric', 'min:0.0001'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);
        $data['created_by'] = auth()->id();

        StockPurchase::create($data);

        return back()->with('success', 'Purchase recorded.');
    }

    public function destroy(StockPurchase $stockPurchase)
    {
        $stockPurchase->delete();

        return back()->with('success', 'Purchase entry removed.');
    }
}
