<?php

namespace App\Http\Controllers\Store;

use App\Exports\ReceivingTemplateExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesStoreCorrections;
use App\Http\Controllers\Concerns\ManagesFormDrafts;
use App\Imports\ReceivingImport;
use App\Models\GeneralStockSupplier;
use App\Models\StockItem;
use App\Models\StockPurchase;
use App\Models\StoreFormDraft;
use App\Services\GeneralStockReportService;
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
    use AuthorizesStoreCorrections, ManagesFormDrafts;

    /** Section this controller belongs to, for the section-scoped correction
     *  permissions. The flat store.edit / store.delete still apply too. */
    protected string $storeSection = 'store.receiving';

    /** Which form ManagesFormDrafts is saving for. */
    protected string $draftForm = StoreFormDraft::FORM_RECEIVING;

    /** Where resuming a draft lands. */
    protected function draftReturnUrl(): string
    {
        return route('store.stock.purchases.index');
    }

    /** A draft is recording a receiving, half-done, so it carries that right. */
    protected function authorizeDraftAction(): void
    {
        abort_unless(auth()->user()?->can('store.receiving.create') ?? false, 403,
            'You do not have permission to record receivings.');
    }

    /**
     * How a saved receiving draft is described in the list.
     *
     * The GRN No is deliberately NOT used, even though it is the most prominent
     * thing on the form: it is a preview of the number the next save would
     * take, not a number this draft owns. Whoever records a receiving first
     * takes it, so labelling a draft with one would name a GRN that ends up
     * belonging to somebody else's delivery. The challan identifies it instead,
     * which is what the supplier's paperwork actually says.
     */
    protected function draftLabel(array $payload): string
    {
        $lines = count($payload['items'] ?? []);

        $supplier = ($payload['general_stock_supplier_id'] ?? null)
            ? GeneralStockSupplier::find($payload['general_stock_supplier_id'])?->name
            : null;

        $parts = array_filter([
            ($payload['challan_no'] ?? null) ? 'Challan '.$payload['challan_no'] : null,
            $payload['purchase_date'] ?? null,
            $supplier,
            $lines ? $lines.' item'.($lines === 1 ? '' : 's') : 'no items yet',
        ]);

        return mb_substr(implode(' · ', $parts) ?: 'Untitled draft', 0, 255);
    }

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
            // brand feeds the read-only Brand/Specification column on the line
            // rows, so the operator can see which brand's stock they picked.
            ->get(['id', 'name', 'uom', 'category', 'brand']);
        $suppliers = GeneralStockSupplier::selectable()->get(['id', 'name']);

        ['edit' => $canEdit, 'delete' => $canDelete] = $this->storeCorrectionAbilities();

        // The RV No the next save will take. A preview, not a reservation:
        // whoever saves first gets it, so the form labels it as such.
        $nextRv = app(RvNumberGenerator::class)->preview();

        // This user's own half-finished forms. Nothing here touches stock —
        // see the store_form_drafts migration.
        $drafts = $this->myDrafts();

        return view('store.stock.purchases', compact(
            'groups', 'lines', 'items', 'suppliers', 'filters', 'canEdit', 'canDelete', 'nextRv', 'drafts'
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

        // The real record is written, so the draft it came from has served its
        // purpose. After the transaction, never before — a rejected submission
        // must leave the draft where it was.
        $this->discardDraftAfterSubmit($request);

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

    /**
     * Correct one line of a recorded receiving.
     *
     * THE DELIVERY'S IDENTITY IS LOCKED: RV No, Challan No and Challan Date are
     * not editable, and are not accepted from the request either. Those three
     * are StockPurchase::groupKeyExpr() — what makes a set of rows one delivery.
     * Changing any of them on ONE line would silently lift that line out of its
     * receiving, or merge it into an unrelated one, and both Purchase History
     * and the Receiving Report group on exactly that key. A genuinely wrong
     * challan number is a different delivery, so it is a delete and a re-entry.
     *
     * TWO SCOPES, deliberately, because this table stores a header on every row:
     *
     *   - Qty, Unit Price and Remarks belong to the LINE and change only here.
     *   - RCV Date and Supplier describe the DELIVERY and are written to every
     *     line of it. They were entered once and copied down by store(); letting
     *     one line disagree would leave the grouped Purchase History row showing
     *     MAX(rcv_date) and MAX(supplier_name) — a figure matching no line. The
     *     modal says which fields do this.
     *
     * Stock needs no recalculation — nothing stores a balance; the Consumable
     * Stock Report sums this table and stock_issues at read time. But a RECEIPT
     * can be corrected downward, and if the goods have since been issued that
     * takes the item below zero, so the same rule Issues enforces applies here
     * in reverse. See assertReceiptCovers().
     */
    public function update(Request $request, StockPurchase $stockPurchase)
    {
        $this->authorizeStoreEdit('stock purchase');

        $data = $request->validate([
            // Compared against the LOCKED challan date on the record, not one
            // from the request — the request cannot carry it.
            'rcv_date' => ['required', 'date', 'after_or_equal:'.$stockPurchase->purchase_date->toDateString()],
            'qty' => ['required', 'numeric', 'min:0.0001'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'general_stock_supplier_id' => ['nullable', 'exists:general_stock_suppliers,id'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ], [
            'rcv_date.after_or_equal' => 'The RCV date cannot be earlier than the challan date ('
                .$stockPurchase->purchase_date->format('d-M-Y').').',
        ], [
            'rcv_date' => 'RCV date',
            'qty' => 'purchased qty',
            'unit_price' => 'unit price',
            'general_stock_supplier_id' => 'supplier',
        ]);

        $supplierId = $data['general_stock_supplier_id'] ?? null;

        DB::transaction(function () use ($data, $supplierId, $stockPurchase) {
            $this->assertReceiptCovers($stockPurchase, (float) $data['qty']);

            $stockPurchase->update([
                'qty' => $data['qty'],
                'unit_price' => $data['unit_price'] ?? null,
                'remarks' => $data['remarks'] ?? null,
            ]);

            // The delivery-wide fields, written to every line of this receiving
            // including the one above. supplier_name is copied rather than
            // joined, exactly as store() does it, so the challan still reads
            // correctly if the supplier is later renamed or deactivated.
            $this->deliveryLines($stockPurchase)->update([
                'rcv_date' => $data['rcv_date'],
                'general_stock_supplier_id' => $supplierId,
                'supplier_name' => $supplierId
                    ? GeneralStockSupplier::find($supplierId)?->name
                    : null,
            ]);
        });

        return back()->with('success', 'Receiving line updated.');
    }

    /**
     * Every line of the delivery a given line belongs to, itself included.
     *
     * Matched on the same three columns groupKeyExpr() groups by, so "the same
     * delivery" means here what it means on the screen. Written as three
     * wheres rather than through that expression because this has to be an
     * updatable query, and the NULL-safe comparison a raw key would need is
     * spelled differently on each database engine.
     */
    private function deliveryLines(StockPurchase $line): \Illuminate\Database\Eloquent\Builder
    {
        return StockPurchase::query()
            ->where(fn ($q) => $line->rv_no === null
                ? $q->whereNull('rv_no')
                : $q->where('rv_no', $line->rv_no))
            ->where(fn ($q) => $line->challan_no === null
                ? $q->whereNull('challan_no')
                : $q->where('challan_no', $line->challan_no))
            ->whereDate('purchase_date', $line->purchase_date);
    }

    /**
     * Refuse a correction that would leave this item's balance below zero.
     *
     * The mirror of the rule the Issue screen enforces, and the same
     * self-exclusion: the balance already counts this receipt's CURRENT
     * quantity as received, so the question is what the figure becomes once the
     * old quantity is undone and the new one applied —
     *
     *     stock_as_on - old_qty + new_qty >= 0
     *
     * Cutting a receipt of 100 to 10 when 60 have already been issued is the
     * case this stops: the goods are gone, and a book balance of -50 is
     * something no report can present honestly.
     *
     * A receipt dated beyond this month's end is outside the window
     * stock_as_on sums, so it cannot be part of the balance being protected and
     * is left alone. purchase_date is locked, so that cannot change underneath.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function assertReceiptCovers(StockPurchase $purchase, float $newQty): void
    {
        if (! $purchase->purchase_date || $purchase->purchase_date->gt(now()->endOfMonth())) {
            return;
        }

        $row = app(GeneralStockReportService::class)
            ->rows(now()->startOfMonth(), [
                'item_ids' => [(int) $purchase->stock_item_id],
                'only_active' => false,
            ])
            ->first();

        $stock = $row ? (float) $row['stock_as_on'] : 0.0;
        $after = $stock - (float) $purchase->qty + $newQty;

        // A whisker of tolerance, matching the import's own stock check, so a
        // stored decimal cannot refuse an exactly-balancing correction.
        if ($after >= -0.00001) {
            return;
        }

        $format = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');

        $name = $row ? $row['item']->name : 'This item';
        $uom = $row && $row['item']->uom ? ' '.$row['item']->uom : '';
        $lowest = (float) $purchase->qty - $stock;

        throw \Illuminate\Validation\ValidationException::withMessages([
            'qty' => $name.' — this receipt cannot be reduced to '.$format($newQty).$uom
                .'. '.$format($stock).$uom.' is in stock, so the lowest it can go is '
                .$format(max($lowest, 0)).$uom.'; the rest has already been issued.',
        ]);
    }

    public function destroy(StockPurchase $stockPurchase)
    {
        $this->authorizeStoreDelete('stock purchase');

        $stockPurchase->delete();

        return back()->with('success', 'Purchase entry removed.');
    }
}
