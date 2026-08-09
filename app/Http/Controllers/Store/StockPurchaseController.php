<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesStoreCorrections;
use App\Models\GeneralStockSupplier;
use App\Models\StockItem;
use App\Models\StockPurchase;
use App\Services\RvNumberGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * General Stock (module A) — challan-level receive (Excel "Purchase" sheet).
 *
 * Field order on the form matches the reference sheet: Challan Date, RCV Date,
 * Month, RV No, Challan/Invoice No, Supplier, Item, Uom, Category, Purchased
 * Qty, Unit Price, Total Value, Remarks. Month, Uom, Category and Total Value
 * are all derived and never posted — they are shown read-only so the form reads
 * like the sheet without inviting a value that contradicts its source.
 *
 * Challan Date (`purchase_date`) is the supplier's document date and is what
 * the Consumable Stock Report keys on. RCV Date is when the goods physically
 * reached the store; the two routinely differ, so both are recorded.
 *
 * Suppliers come from General Stock's own list (GeneralStockSupplier), not the
 * Buyer/Style module's nominated supplier table.
 */
class StockPurchaseController extends Controller
{
    use AuthorizesStoreCorrections;

    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        // One goods-receiving event = one RV No, one challan and one challan
        // date, shared by all its lines. Legacy rows predate the auto-generated
        // RV, and the same hand-typed number was reused months apart, so the
        // date is part of the key — otherwise two unrelated old deliveries
        // would merge into one group.
        $keyExpr = "CONCAT(COALESCE(rv_no,''),'|',COALESCE(challan_no,''),'|',COALESCE(purchase_date,''))";

        $applyFilters = function ($q) use ($filters) {
            return $q
                ->when($filters['search'] ?? null, function ($q, $search) {
                    $like = '%'.$search.'%';
                    $q->where(fn ($w) => $w->where('challan_no', 'like', $like)
                        ->orWhere('rv_no', 'like', $like)
                        ->orWhere('supplier_name', 'like', $like)
                        ->orWhereHas('stockItem', fn ($i) => $i->where('name', 'like', $like)));
                })
                ->when($filters['month'] ?? null, function ($q, $month) {
                    [$year, $m] = explode('-', $month);
                    $q->whereYear('purchase_date', $year)->whereMonth('purchase_date', $m);
                });
        };

        // A group is shown whole when ANY of its lines matches, so the filter
        // picks the keys first and the group is then rebuilt from all its rows.
        // Filtering inside the grouped query instead would make the line count
        // and totals describe only the matching lines, which would be wrong.
        $matchedKeys = $applyFilters(StockPurchase::query())
            ->distinct()
            ->pluck(DB::raw($keyExpr.' as group_key'));

        $groups = StockPurchase::query()
            ->selectRaw($keyExpr.' as group_key')
            ->selectRaw('rv_no, challan_no, purchase_date')
            ->selectRaw('MAX(rcv_date) as rcv_date, MAX(supplier_name) as supplier_name')
            ->selectRaw('COUNT(*) as line_count, SUM(qty) as group_total_qty')
            // Deliberately NOT aliased "total_value": StockPurchase has a
            // getTotalValueAttribute() accessor, and an accessor wins over a
            // selected column — it would recompute qty x unit_price from
            // attributes this grouped row does not carry and return 0.
            ->selectRaw('SUM(qty * COALESCE(unit_price, 0)) as group_total_value, MAX(id) as latest_id')
            ->whereIn(DB::raw($keyExpr), $matchedKeys->isEmpty() ? [''] : $matchedKeys->all())
            ->groupBy('rv_no', 'challan_no', 'purchase_date')
            ->orderByDesc('purchase_date')
            ->orderByDesc(DB::raw('MAX(id)'))
            ->paginate(25)
            ->withQueryString();

        // Every line of the groups on THIS page, so a group is never split
        // across a page boundary.
        $lines = StockPurchase::with(['stockItem', 'generalStockSupplier', 'createdBy'])
            ->selectRaw('*, '.$keyExpr.' as group_key')
            ->whereIn(DB::raw($keyExpr), $groups->getCollection()->pluck('group_key')->all() ?: [''])
            ->orderBy('id')
            ->get()
            ->groupBy('group_key');

        $items = StockItem::where('is_active', true)->orderBy('name')
            ->get(['id', 'name', 'uom', 'category']);
        $suppliers = GeneralStockSupplier::selectable()->get(['id', 'name']);

        ['edit' => $canEdit, 'delete' => $canDelete] = $this->storeCorrectionAbilities();

        // The RV No the next save will take. A preview, not a reservation:
        // whoever saves first gets it, so the form labels it as such.
        $nextRv = app(RvNumberGenerator::class)->preview();

        return view('store.stock.purchases', compact(
            'groups', 'lines', 'items', 'suppliers', 'filters', 'canEdit', 'canDelete', 'nextRv'
        ));
    }

    /**
     * Record one goods-receiving event — a shared header plus one or more item
     * lines, mirroring how Record Issue handles a requisition.
     *
     * Every line is still saved as its own StockPurchase row carrying a copy of
     * the header, so the Consumable Stock Report, which aggregates by item and
     * challan date and never reads rv_no, is completely unaffected by how the
     * lines were grouped when they were entered.
     */
    public function store(Request $request, RvNumberGenerator $rv)
    {
        $data = $request->validate([
            'purchase_date' => ['required', 'date'],
            // Goods cannot reach the store before the challan is written, so an
            // RCV date earlier than the challan date is a typo, not a case.
            'rcv_date' => ['required', 'date', 'after_or_equal:purchase_date'],
            // rv_no is no longer accepted from the form — it is allocated below.
            'challan_no' => ['nullable', 'string', 'max:100'],
            'general_stock_supplier_id' => ['nullable', 'exists:general_stock_suppliers,id'],
            'supplier_name' => ['nullable', 'string', 'max:255'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.stock_item_id' => ['required', 'exists:stock_items,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.remarks' => ['nullable', 'string', 'max:1000'],
        ], [
            'rcv_date.after_or_equal' => 'The RCV date cannot be earlier than the challan date.',
            'items.required' => 'Add at least one item to receive.',
            'items.min' => 'Add at least one item to receive.',
        ], [
            'purchase_date' => 'challan date',
            'rcv_date' => 'RCV date',
            'general_stock_supplier_id' => 'supplier',
        ] + $this->lineAttributes($request));

        // Header, resolved once rather than once per line.
        $header = [
            'purchase_date' => $data['purchase_date'],
            'rcv_date' => $data['rcv_date'],
            'challan_no' => $data['challan_no'] ?? null,
            'general_stock_supplier_id' => $data['general_stock_supplier_id'] ?? null,
            'supplier_name' => $data['supplier_name'] ?? null,
            'created_by' => auth()->id(),
        ];

        // Keep supplier_name as the displayed/exported value. When a supplier is
        // picked its name is copied in, so the challan still reads correctly if
        // that supplier is later renamed or deactivated.
        if (! empty($header['general_stock_supplier_id'])) {
            $header['supplier_name'] = GeneralStockSupplier::find($header['general_stock_supplier_id'])?->name
                ?? $header['supplier_name'];
        }

        $rvNo = null;

        DB::transaction(function () use ($data, $header, $rv, &$rvNo) {
            // Allocated inside the transaction: the counter row stays locked
            // until commit, so two people saving at once cannot take the same
            // number. Keyed to the RCV date's month, which is when the goods
            // actually arrived.
            $rvNo = $rv->next(Carbon::parse($data['rcv_date']));

            foreach ($data['items'] as $line) {
                StockPurchase::create($header + [
                    'rv_no' => $rvNo,
                    'stock_item_id' => $line['stock_item_id'],
                    'qty' => $line['qty'],
                    'unit_price' => $line['unit_price'] ?? null,
                    // Remarks follow the line, not the header — one delivery can
                    // carry a note about one item only.
                    'remarks' => $line['remarks'] ?? null,
                ]);
            }
        });

        $count = count($data['items']);

        return back()->with('success', $count === 1
            ? 'Purchase recorded under RV No '.$rvNo.'.'
            : $count.' item(s) received under RV No '.$rvNo.'.');
    }

    /**
     * Readable names for the line fields, so a validation message says
     * "Item 2: purchased qty is required" rather than naming items.1.qty.
     *
     * @return array<string, string>
     */
    private function lineAttributes(Request $request): array
    {
        $attributes = [];

        foreach (array_keys((array) $request->input('items', [])) as $index) {
            $n = (int) $index + 1;
            $attributes['items.'.$index.'.stock_item_id'] = 'item '.$n.' name';
            $attributes['items.'.$index.'.qty'] = 'item '.$n.' purchased qty';
            $attributes['items.'.$index.'.unit_price'] = 'item '.$n.' unit price';
        }

        return $attributes;
    }

    public function destroy(StockPurchase $stockPurchase)
    {
        $this->authorizeStoreDelete('stock purchase');

        $stockPurchase->delete();

        return back()->with('success', 'Purchase entry removed.');
    }
}
