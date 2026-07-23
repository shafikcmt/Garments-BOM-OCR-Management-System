<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesStoreCorrections;
use App\Models\StockItem;
use App\Models\StockIssue;
use Illuminate\Http\Request;

/**
 * General Stock (module A) — requisition-style issue (Excel "Consumption" and
 * "Non Stock" sheets). A "Non Stock" issue has is_stock_item = false and a free
 * text description instead of a stock_item_id.
 */
class StockIssueController extends Controller
{
    use AuthorizesStoreCorrections;

    public function index(Request $request)
    {
        $issues = StockIssue::with(['stockItem', 'createdBy'])
            ->latest('issue_date')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $items = StockItem::where('is_active', true)->orderBy('name')->get();

        ['edit' => $canEdit, 'delete' => $canDelete] = $this->storeCorrectionAbilities();

        return view('store.stock.issues', compact('issues', 'items', 'canEdit', 'canDelete'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'is_stock_item' => ['required', 'boolean'],
            'stock_item_id' => ['nullable', 'required_if:is_stock_item,1', 'exists:stock_items,id'],
            'item_description' => ['nullable', 'required_if:is_stock_item,0', 'string', 'max:255'],
            'requisition_no' => ['nullable', 'string', 'max:100'],
            'issue_date' => ['required', 'date'],
            'qty' => ['required', 'numeric', 'min:0.0001'],
            'issued_to' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        // Non Stock issues never carry a stock_item_id.
        if (! $data['is_stock_item']) {
            $data['stock_item_id'] = null;
        } else {
            $data['item_description'] = null;
        }
        $data['created_by'] = auth()->id();

        StockIssue::create($data);

        return back()->with('success', 'Issue recorded.');
    }

    public function destroy(StockIssue $stockIssue)
    {
        $this->authorizeStoreDelete('stock issue');

        $stockIssue->delete();

        return back()->with('success', 'Issue entry removed.');
    }
}
