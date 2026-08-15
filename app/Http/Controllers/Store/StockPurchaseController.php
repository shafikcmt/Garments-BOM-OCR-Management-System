<?php

namespace App\Http\Controllers\Store;

use App\Exports\ReceivingTemplateExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesStoreCorrections;
use App\Imports\ReceivingImport;
use App\Models\GeneralStockSupplier;
use App\Models\StockItem;
use App\Models\StockPurchase;
use App\Services\RvNumberGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

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

    /** Section this controller belongs to, for the section-scoped correction
     *  permissions. The flat store.edit / store.delete still apply too. */
    protected string $storeSection = 'store.receiving';

    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        // How lines are grouped into deliveries. Defined once on the model, so
        // Purchase History and the Receiving Report can never disagree about
        // what counts as one delivery.
        $keyExpr = StockPurchase::groupKeyExpr();

        $applyFilters = function ($q) use ($filters) {
            return $q
                ->when($filters['search'] ?? null, function ($q, $search) {
                    $like = '%'.$search.'%';
                    // whereLike, not where(..., 'like', ...): PostgreSQL's LIKE
                    // is case-sensitive, so "ch158" would not find "CH158" —
                    // the search silently returned less than it should. The
                    // framework's whereLike compiles to ILIKE on Postgres and
                    // stays LIKE on MySQL/MariaDB and SQLite, where LIKE is
                    // already case-insensitive.
                    $q->where(fn ($w) => $w->whereLike('challan_no', $like)
                        ->orWhereLike('rv_no', $like)
                        ->orWhereLike('supplier_name', $like)
                        ->orWhereHas('stockItem', fn ($i) => $i->whereLike('name', $like)));
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
            ? 'Purchase recorded under GRN No '.$rvNo.'.'
            : $count.' item(s) received under GRN No '.$rvNo.'.');
    }

    /** The blank sample workbook for the bulk receiving upload. */
    public function template()
    {
        return Excel::download(new ReceivingTemplateExport, 'Receiving-Bulk-Upload-Template.xlsx');
    }

    /**
     * Bulk goods-receiving upload — many deliveries, many item lines each, in
     * one file.
     *
     * The DELIVERY is the unit of success, not the file: a challan with a bad
     * line is reported and left out, and every other challan in the same file
     * still goes in. That matters because the files this reads are years of
     * historical purchases, where a single unknown item name would otherwise
     * block thousands of good rows.
     *
     * Re-uploading a corrected file is safe: a challan already in Purchase
     * History is recognised and skipped rather than recorded a second time.
     *
     * Everything is written inside ONE transaction so the RV numbers stay
     * unique — RvNumberGenerator holds its lock only until the transaction ends.
     */
    public function import(Request $request, RvNumberGenerator $rv)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:8192'],
        ], [], ['file' => 'upload file']);

        try {
            $sheets = Excel::toArray(new ReceivingImport, $request->file('file'));
        } catch (\Throwable $e) {
            return back()->with('warning', 'That file could not be read. Please upload a CSV or Excel file based on the sample template.');
        }

        [
            'challans' => $challans,
            'errors' => $errors,
            'skipped' => $skipped,
            'notes' => $notes,
        ] = ReceivingImport::parse($sheets[0] ?? []);

        if (empty($challans)) {
            return back()
                ->with('warning', $errors
                    ? 'Nothing was imported. Please correct the rows listed below and upload the file again.'
                    : ($skipped
                        ? 'No new deliveries were imported. See the skipped rows below.'
                        : 'No receiving rows were found in that file. Please use the sample template.'))
                ->with('import_errors', $errors)
                ->with('import_skipped', $skipped)
                ->with('import_notes', $notes);
        }

        $userId = auth()->id();
        $lineCount = 0;

        DB::transaction(function () use ($challans, $rv, $userId, &$lineCount) {
            foreach ($challans as $challan) {
                // The same allocator the manual form uses, keyed to the same
                // date, so imported and typed deliveries share one continuous
                // series with no gaps and no reused numbers. The legacy RV No in
                // the file was only ever used to group the rows.
                $rvNo = $rv->next(Carbon::parse($challan['rcv_date']));

                foreach ($challan['lines'] as $line) {
                    StockPurchase::create([
                        'rv_no' => $rvNo,
                        'purchase_date' => $challan['purchase_date'],
                        'rcv_date' => $challan['rcv_date'],
                        'challan_no' => $challan['challan_no'],
                        'supplier_name' => $challan['supplier_name'],
                        'general_stock_supplier_id' => $challan['general_stock_supplier_id'],
                        'stock_item_id' => $line['stock_item_id'],
                        'qty' => $line['qty'],
                        'unit_price' => $line['unit_price'],
                        'remarks' => $line['remarks'],
                        'created_by' => $userId,
                    ]);

                    $lineCount++;
                }
            }
        });

        $count = count($challans);

        return back()
            ->with('success', $count.' '.($count === 1 ? 'delivery' : 'deliveries')
                .' imported under '.$count.' new GRN '.($count === 1 ? 'number' : 'numbers')
                .', covering '.$lineCount.' item line(s).')
            // Errors are not a failure here — they name the challans that were
            // left out while the rest went in, so they stay on screen.
            ->with('import_errors', $errors)
            ->with('import_skipped', $skipped)
            ->with('import_notes', $notes);
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
