<?php

namespace App\Http\Controllers\Store;

use App\Exports\ReceivingTemplateExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesStoreCorrections;
use App\Http\Controllers\Concerns\ManagesFormDrafts;
use App\Imports\ReceivingImport;
use App\Models\GeneralStockSupplier;
use App\Models\GsPurchaseOrder;
use App\Models\StockItem;
use App\Models\StockPurchase;
use App\Models\StoreFormDraft;
use App\Services\GeneralStockReportService;
use App\Services\PurchaseOrderService;
use App\Services\RvNumberGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

    /**
     * This month's receiving at a glance, for people who check it but do not enter it.
     *
     * @return array<string, mixed>
     */
    private function receivingSummary(): array
    {
        $month = StockPurchase::query()
            ->whereYear('purchase_date', now()->year)->whereMonth('purchase_date', now()->month);

        $rows = (clone $month)->get(['rv_no', 'challan_no', 'stock_item_id', 'supplier_name', 'qty', 'unit_price', 'purchase_date']);

        $bySupplier = $rows->groupBy(fn ($r) => trim((string) $r->supplier_name) ?: 'Not named')
            ->map(fn ($g) => round((float) $g->sum(fn ($r) => (float) $r->qty * (float) $r->unit_price), 2))
            ->sortDesc()->take(5);

        return [
            'month' => now()->format('F Y'),
            'deliveries' => $rows->map(fn ($r) => $r->rv_no ?: $r->challan_no)->filter()->unique()->count(),
            'items' => $rows->pluck('stock_item_id')->unique()->count(),
            'suppliers' => $rows->pluck('supplier_name')->map(fn ($s) => trim((string) $s))->filter()->unique()->count(),
            'value' => round((float) $rows->sum(fn ($r) => (float) $r->qty * (float) $r->unit_price), 2),
            'last_date' => StockPurchase::query()->max('purchase_date'),
            'top_suppliers' => $bySupplier,
            'open_pos' => \App\Models\GsPurchaseOrder::query()->receivable()->count(),
        ];
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
                        ->orWhereHas('stockItem', fn ($i) => $i->where(fn ($n) => $n->whereLike('name', $like)->orWhereLike('sku', $like))));
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
            // The filters are carried onto the page links by hand rather than
            // with withQueryString(), because `partial` is a transport flag and
            // not a filter. withQueryString() copies the WHOLE query string and
            // appends() merges into it rather than replacing it, so the two
            // together still leave partial=1 on every href — and a plain click
            // on page 2 with JS off would then answer with a bare fragment
            // instead of a page.
            ->appends(Arr::except($request->query(), ['partial', 'page']));

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
            ->get(['id', 'sku', 'name', 'uom', 'category', 'brand']);
        $suppliers = GeneralStockSupplier::selectable()->get(['id', 'name']);

        // Issued Internal POs with something still to come, for "Against PO".
        $openPos = GsPurchaseOrder::receivable()->latest('id')->limit(200)
            ->get(['id', 'po_no', 'supplier_name']);

        ['edit' => $canEdit, 'delete' => $canDelete] = $this->storeCorrectionAbilities();

        // The RV No the next save will take. A preview, not a reservation:
        // whoever saves first gets it, so the form labels it as such.
        $nextRv = app(RvNumberGenerator::class)->preview();

        // Live search / paging: the same action, returning only the part of the
        // page the filters affect. Sharing the action rather than adding a
        // second endpoint is what guarantees the AJAX result and the full page
        // come off the identical query and the identical paging.
        //
        // `suppliers` is in here too: the per-line Edit dialogs ride inside the
        // swapped fragment — they are keyed by line id and must be replaced
        // with the rows — and their supplier dropdown needs the list.
        if ($request->boolean('partial')) {
            return view('store.stock._purchases-list', compact(
                'groups', 'lines', 'suppliers', 'filters', 'canEdit', 'canDelete'
            ));
        }

        // This user's own half-finished forms. Nothing here touches stock —
        // see the store_form_drafts migration.
        $drafts = $this->myDrafts();

        $user = auth()->user();
        // Same rule as the route guard ($act in routes/store.php): the Store, Admin and
        // Management roles, or the create permission.
        $canCreate = ($user?->hasAnyRole(['store', 'admin', 'management']) ?? false) || ($user?->can('store.receiving.create') ?? false);

        return view('store.stock.purchases', compact(
            'groups', 'lines', 'items', 'suppliers', 'filters', 'canEdit', 'canDelete', 'nextRv', 'drafts', 'openPos', 'canCreate'
        ) + [
            // A user who only checks Receiving gets a summary where the entry form would be.
            'summary' => $canCreate ? null : $this->receivingSummary(),
            // The Month filter's list ("All months" + recent months).
            'monthOptions' => \App\Support\MonthOptions::recent($filters['month'] ?? null),
        ]);
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
            // Challan No, Supplier and each line's Unit Price are required on
            // a recorded receiving (owner, 2026-09-29); Save Draft does not
            // come through here and may leave them empty.
            'challan_no' => ['required', 'string', 'max:100'],
            'general_stock_supplier_id' => ['required', 'exists:general_stock_suppliers,id'],
            'supplier_name' => ['nullable', 'string', 'max:255'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.stock_item_id' => ['required', 'exists:stock_items,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
            // 0 is allowed (a free sample); the reports treat it as no price.
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.remarks' => ['nullable', 'string', 'max:1000'],
            // Receiving against an Internal PO (optional).
            'gs_purchase_order_id' => ['nullable', 'integer', 'exists:gs_purchase_orders,id'],
            'items.*.gs_purchase_order_item_id' => ['nullable', 'integer'],
        ], self::requiredMessages() + [
            'rcv_date.after_or_equal' => 'The RCV date cannot be earlier than the challan date.',
            'items.required' => 'Add at least one item to receive.',
            'items.min' => 'Add at least one item to receive.',
            'items.*.unit_price.required' => 'Enter the unit price (0 if free of charge).',
        ], [
            'purchase_date' => 'challan date',
            'rcv_date' => 'RCV date',
            'general_stock_supplier_id' => 'supplier',
        ] + $this->lineAttributes($request));

        // The same item on one challan from one supplier only with a
        // different remark — within this form and against receipts recorded.
        $this->assertNoRepeatedLines($data['items'], $data['challan_no'], (int) $data['general_stock_supplier_id']);

        // The Internal PO these goods are for, if any: checked before anything is written.
        $purchaseOrder = $this->assertAgainstPurchaseOrder($data);

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

        DB::transaction(function () use ($data, $header, $rv, &$rvNo, $purchaseOrder) {
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
                    'gs_purchase_order_id' => $purchaseOrder?->id,
                    'gs_purchase_order_item_id' => $purchaseOrder ? ($line['gs_purchase_order_item_id'] ?? null) : null,
                ]);
            }
        });

        // Move the PO to Partly Received / Received to match what Store has now recorded.
        if ($purchaseOrder) {
            app(PurchaseOrderService::class)->syncReceiving($purchaseOrder->fresh());
        }

        // The real record is written, so the draft it came from has served its
        // purpose. After the transaction, never before — a rejected submission
        // must leave the draft where it was.
        $this->discardDraftAfterSubmit($request);

        $count = count($data['items']);

        $against = $purchaseOrder ? ' against '.$purchaseOrder->po_no : '';

        return back()->with('success', $count === 1
            ? 'Purchase recorded under GRN No '.$rvNo.$against.'.'
            : $count.' item(s) received under GRN No '.$rvNo.$against.'.');
    }

    /**
     * Deliveries already recorded under a challan number.
     *
     * Read-only, and called by the Record Receiving form as the challan number
     * is typed. It exists because nothing else catches a challan entered twice:
     * every submission takes a fresh GRN by design — the same challan can
     * legitimately arrive in two batches — so two identical entries become two
     * deliveries and two additions to stock, with the screen saying nothing.
     *
     * WARNS, NEVER BLOCKS. A repeated challan number is ordinary in a partial
     * receipt, so the answer is to show what is already recorded and let the
     * operator decide, not to refuse the entry.
     *
     * whereLike, not where(..., 'like', ...): PostgreSQL's LIKE is
     * case-sensitive, so "ch158" would not find "CH158" and the check would
     * quietly pass on live while working locally. No wildcards — this is an
     * exact match done case-insensitively.
     */
    public function challanStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challan_no' => ['nullable', 'string', 'max:100'],
            'general_stock_supplier_id' => ['nullable', 'integer'],
        ]);

        $challan = trim((string) ($data['challan_no'] ?? ''));

        if ($challan === '') {
            return response()->json(['matches' => []]);
        }

        $matches = StockPurchase::query()
            ->whereLike('challan_no', $challan)
            // Scoped to the supplier when one is chosen: the same challan
            // number from two different suppliers is a coincidence, not a
            // repeat, and warning about it would train people to ignore this.
            ->when($data['general_stock_supplier_id'] ?? null,
                fn ($q, $id) => $q->where('general_stock_supplier_id', $id))
            ->with('stockItem:id,name')
            ->orderByDesc('purchase_date')
            ->orderByDesc('id')
            ->get(['id', 'rv_no', 'challan_no', 'purchase_date', 'rcv_date', 'supplier_name', 'stock_item_id', 'qty'])
            // One entry per delivery, not per line — the operator is being told
            // about receivings, and a three-item challan is one of them.
            ->groupBy('rv_no')
            ->map(fn ($lines) => [
                'rv_no' => $lines->first()->rv_no,
                'purchase_date' => $lines->first()->purchase_date?->format('d-M-Y'),
                'supplier_name' => $lines->first()->supplier_name,
                'lines' => $lines->count(),
                'qty' => (float) $lines->sum('qty'),
                'items' => $lines->take(3)->map(fn ($l) => $l->stockItem?->name)->filter()->values(),
            ])
            ->values();

        return response()->json(['matches' => $matches]);
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

        $canEditChallan = self::canEditChallan();

        $data = $request->validate([
            // Compared against the LOCKED challan date on the record, not one
            // from the request — the request cannot carry it.
            'rcv_date' => ['required', 'date', 'after_or_equal:'.$stockPurchase->purchase_date->toDateString()],
            'qty' => ['required', 'numeric', 'min:0.0001'],
            // Required on an edit as on a new receiving (owner, 2026-09-29):
            // an old line with them empty is filled in when next edited. The
            // Challan No cannot be edited here, so it is not asked for.
            'unit_price' => ['required', 'numeric', 'min:0'],
            'general_stock_supplier_id' => ['required', 'exists:general_stock_suppliers,id'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ] + ($canEditChallan ? [
            // Only for Admin and the users Admin has given
            // store.receiving.edit_challan; for everyone else the field is
            // locked on the form and anything posted is ignored.
            'challan_no' => ['required', 'string', 'max:100'],
        ] : []), self::requiredMessages() + [
            'unit_price.required' => 'Enter the unit price (0 if free of charge).',
            'rcv_date.after_or_equal' => 'The RCV date cannot be earlier than the challan date ('
                .$stockPurchase->purchase_date->format('d-M-Y').').',
        ], [
            'rcv_date' => 'RCV date',
            'qty' => 'purchased qty',
            'unit_price' => 'unit price',
            'general_stock_supplier_id' => 'supplier',
        ]);

        $supplierId = $data['general_stock_supplier_id'] ?? null;

        $newChallan = $canEditChallan ? trim((string) $data['challan_no']) : null;
        $challanChanged = $newChallan !== null && $newChallan !== (string) $stockPurchase->challan_no;

        if ($challanChanged) {
            // The challan belongs to the whole delivery, so every line of it
            // moves to the new number. None may then collide with a receipt
            // already recorded under that number (same item, supplier, remark).
            $delivery = $this->deliveryLines($stockPurchase)->get(['id', 'stock_item_id', 'remarks']);
            $challan = self::normalized($newChallan);
            $clash = StockPurchase::query()
                ->whereIn('stock_item_id', $delivery->pluck('stock_item_id')->unique()->all())
                ->whereRaw('LOWER(TRIM(challan_no)) = ?', [$challan])
                ->where('general_stock_supplier_id', (int) $supplierId)
                ->whereNotIn('id', $delivery->pluck('id')->all())
                ->get(['stock_item_id', 'remarks'])
                ->toBase()
                ->map(fn ($line) => $line->stock_item_id.'|'.self::normalized($line->remarks));

            $mine = $delivery->toBase()->map(fn ($line) => $line->stock_item_id.'|'.self::normalized(
                $line->id === $stockPurchase->id ? ($data['remarks'] ?? null) : $line->remarks
            ));

            if ($clash->intersect($mine)->isNotEmpty()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'challan_no' => 'Challan '.$newChallan.' from this supplier already has the same item with the same remark. Use a different challan no.',
                ]);
            }
        } else {
            // The same item + challan + supplier + remark as another recorded
            // receipt is refused; this line is not its own twin.
            $this->assertNoRepeatedLines(
                [['stock_item_id' => $stockPurchase->stock_item_id, 'remarks' => $data['remarks'] ?? null]],
                $stockPurchase->challan_no,
                (int) $supplierId,
                $stockPurchase,
                fn () => 'remarks'
            );
        }

        DB::transaction(function () use ($data, $supplierId, $stockPurchase, $challanChanged, $newChallan) {
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
                ...($challanChanged ? ['challan_no' => $newChallan] : []),
                'general_stock_supplier_id' => $supplierId,
                'supplier_name' => $supplierId
                    ? GeneralStockSupplier::find($supplierId)?->name
                    : null,
            ]);
        });

        $this->syncPurchaseOrderOf($stockPurchase);

        return back()->with('success', 'Receiving line updated.');
    }

    /**
     * Whether the current user may correct the Challan No of a recorded
     * receiving. Admin always may (Gate::before); anyone else only when Admin
     * has given them store.receiving.edit_challan, directly or through a role.
     */
    public static function canEditChallan(): bool
    {
        return auth()->user()?->can('store.receiving.edit_challan') ?? false;
    }

    /** @return array<string, string> the header messages a required field shows, create and edit alike */
    private static function requiredMessages(): array
    {
        return [
            'challan_no.required' => 'Enter the Challan / Invoice No.',
            'general_stock_supplier_id.required' => 'Select the supplier.',
        ];
    }

    /** Compared trimmed, spaces collapsed, case-insensitive. */
    private static function normalized(?string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $value)));
    }

    /**
     * The same item may appear more than once on one challan from one
     * supplier only when the remarks differ — a further delivery against the
     * same challan says so ("2nd delivery"). Same remarks, or both empty, are
     * refused on the line, whether the twin is another line of this form or a
     * receipt already recorded under that challan and supplier. $excluding is
     * the line being edited, which is never its own twin.
     *
     * Old records are not touched; the rule applies when one is edited.
     *
     * @param  array<int|string, array<string, mixed>>  $lines
     * @param  callable(int|string): string|null  $errorKey
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function assertNoRepeatedLines(array $lines, ?string $challanNo, int $supplierId, ?StockPurchase $excluding = null, ?callable $errorKey = null): void
    {
        $challan = self::normalized($challanNo);

        if ($challan === '' || $supplierId <= 0) {
            return;
        }

        $supplier = GeneralStockSupplier::find($supplierId)?->name ?? 'this supplier';
        $errorKey ??= fn ($index) => 'items.'.$index.'.remarks';
        $message = 'This item is already received on challan '.trim((string) $challanNo).' from '.$supplier
            .' with the same remark. For a further delivery, add a remark such as \'2nd delivery\'.';

        // Recorded already under this challan, from this supplier. LOWER/TRIM
        // read the same on MySQL, PostgreSQL and SQLite.
        $itemIds = array_values(array_unique(array_map(fn ($line) => (int) $line['stock_item_id'], $lines)));
        $taken = StockPurchase::query()
            ->whereIn('stock_item_id', $itemIds)
            ->whereRaw('LOWER(TRIM(challan_no)) = ?', [$challan])
            ->where('general_stock_supplier_id', $supplierId)
            ->when($excluding, fn ($q) => $q->whereKeyNot($excluding->getKey()))
            ->get(['stock_item_id', 'remarks'])
            ->mapWithKeys(fn ($line) => [$line->stock_item_id.'|'.self::normalized($line->remarks) => true])
            ->all();

        $errors = [];

        foreach ($lines as $index => $line) {
            $key = (int) $line['stock_item_id'].'|'.self::normalized($line['remarks'] ?? null);

            if (isset($taken[$key])) {
                $errors[$errorKey($index)] = $message;
            }

            $taken[$key] = true;
        }

        if ($errors) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
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
        $short = $this->receiptShortfall($purchase, $newQty);

        if (! $short) {
            return;
        }

        throw \Illuminate\Validation\ValidationException::withMessages([
            'qty' => $short['item'].' — this receipt cannot be reduced to '.$short['asked'].$short['uom']
                .'. '.$short['stock'].$short['uom'].' is in stock, so the lowest it can go is '
                .$short['lowest'].$short['uom'].'; the rest has already been issued.',
        ]);
    }

    /**
     * What is wrong with taking a receipt down to $newQty, or null if nothing.
     *
     * The arithmetic behind both the edit check and the delete check, in one
     * place so the two cannot disagree about what "would go negative" means:
     *
     *     stock_as_on - old_qty + new_qty >= 0
     *
     * Deleting is simply the case where the new quantity is zero, so removing a
     * receipt of 100 against which 60 has been issued is refused exactly as
     * cutting it to 10 would be.
     *
     * A receipt dated beyond this month's end sits outside the window
     * stock_as_on sums, so it is not part of the balance being protected and is
     * left alone.
     *
     * @return array{item: string, uom: string, stock: string, asked: string, lowest: string}|null
     */
    private function receiptShortfall(StockPurchase $purchase, float $newQty): ?array
    {
        if (! $purchase->purchase_date || $purchase->purchase_date->gt(now()->endOfMonth())) {
            return null;
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
            return null;
        }

        $format = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ','), '0'), '.');

        return [
            'item' => $row ? $row['item']->name : 'This item',
            'uom' => $row && $row['item']->uom ? ' '.$row['item']->uom : '',
            'stock' => $format($stock),
            'asked' => $format($newQty),
            'lowest' => $format(max((float) $purchase->qty - $stock, 0)),
        ];
    }

    /**
     * Remove one line of a receiving.
     *
     * REFUSED WHEN IT WOULD TAKE THE ITEM BELOW ZERO — the same rule Edit
     * enforces, and the same arithmetic, because deleting a receipt is
     * reducing it to nothing. Without this, removing a receipt of 100 against
     * which 60 had already been issued left the balance at -60, and the screen
     * offered no hint that it would: a correction the system refuses through
     * one door should not be waved through the next one along.
     *
     * A warning rather than a validation error, because there is no field on a
     * form to attach it to — the user pressed Remove on a table row.
     */
    public function destroy(StockPurchase $stockPurchase)
    {
        $this->authorizeStoreDelete('stock purchase');

        $short = $this->receiptShortfall($stockPurchase, 0.0);

        if ($short) {
            return back()->with('warning',
                $short['item'].' — this receiving cannot be removed. '.$short['stock'].$short['uom']
                .' is in stock and this receipt brought in '.$this->fmtQty($stockPurchase->qty).$short['uom']
                .', so removing it would leave the balance below zero. Reduce it to '
                .$short['lowest'].$short['uom'].' instead, or remove what was issued against it first.');
        }

        $stockPurchase->delete();

        $this->syncPurchaseOrderOf($stockPurchase);

        return back()->with('success', 'Purchase entry removed.');
    }

    /** A corrected or removed receiving line changes how much of its PO has arrived. */
    private function syncPurchaseOrderOf(StockPurchase $stockPurchase): void
    {
        if ($stockPurchase->gs_purchase_order_id && ($po = GsPurchaseOrder::find($stockPurchase->gs_purchase_order_id))) {
            app(PurchaseOrderService::class)->syncReceiving($po);
        }
    }

    /**
     * Record Receiving "Against PO": the PO must be open, from the same supplier,
     * and every line must be one of its lines, for no more than it still expects.
     * Returns the PO, or null when the goods are not against a PO.
     *
     * @param  array<string, mixed>  $data  validated request
     */
    private function assertAgainstPurchaseOrder(array $data): ?GsPurchaseOrder
    {
        $poId = $data['gs_purchase_order_id'] ?? null;

        if (! $poId) {
            return null;
        }

        $po = GsPurchaseOrder::with('items')->find($poId);
        $errors = [];

        if (! $po || ! $po->isReceivable()) {
            throw ValidationException::withMessages(['gs_purchase_order_id' => 'This purchase order is not open for receiving.']);
        }

        if ($po->general_stock_supplier_id && (int) $po->general_stock_supplier_id !== (int) ($data['general_stock_supplier_id'] ?? 0)) {
            $errors['general_stock_supplier_id'] = $po->po_no.' is for '.$po->supplier_name.'. Choose that supplier, or clear the PO.';
        }

        $received = app(PurchaseOrderService::class)->receivedByLine($po);
        $asked = [];

        foreach ($data['items'] as $i => $line) {
            $poItem = $po->items->firstWhere('id', (int) ($line['gs_purchase_order_item_id'] ?? 0));

            if (! $poItem) {
                $errors["items.$i.stock_item_id"] = 'This item is not on '.$po->po_no.'.';

                continue;
            }

            if ($poItem->stock_item_id && (int) $poItem->stock_item_id !== (int) $line['stock_item_id']) {
                $errors["items.$i.stock_item_id"] = 'This line is for '.$poItem->item_name.' on '.$po->po_no.'.';

                continue;
            }

            $asked[$poItem->id] = ($asked[$poItem->id] ?? 0.0) + (float) $line['qty'];
            $remaining = (float) $poItem->qty - ($received[$poItem->id] ?? 0.0);

            if ($asked[$poItem->id] > $remaining + 0.00005) {
                $errors["items.$i.qty"] = $po->po_no.' still expects only '.$this->fmtQty(max(0, $remaining)).' of '.$poItem->item_name.'.';
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $po;
    }

    /** A quantity as the screens write it: trimmed of trailing zeros. */
    private function fmtQty(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 4, '.', ','), '0'), '.');
    }
}
