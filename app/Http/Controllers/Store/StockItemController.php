<?php

namespace App\Http\Controllers\Store;

use App\Exports\ItemMasterExport;
use App\Exports\ItemMasterTemplateExport;
use App\Exports\UnitPriceTemplateExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesStoreCorrections;
use App\Http\Controllers\Concerns\ResolvesIssueSetupMasters;
use App\Imports\ItemMasterImport;
use App\Imports\UnitPriceImport;
use App\Models\ItemCategory;
use App\Models\PurchaseRequisitionItem;
use App\Models\StockItem;
use App\Services\GeneralStockReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * General Stock (module A) — item master CRUD. General stock is fully
 * independent of BOM/PO. Store role only (gated in routes/store.php).
 */
class StockItemController extends Controller
{
    use AuthorizesStoreCorrections, ResolvesIssueSetupMasters;

    /** Section this controller belongs to, for the section-scoped correction
     *  permissions. The flat store.edit / store.delete still apply too. */
    protected string $storeSection = 'store.items';

    public function index(Request $request)
    {
        // All three are optional. With none of them supplied the query below is
        // the one this screen has always run.
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:160'],
            'status' => ['nullable', 'string', 'in:attention,out,place_order,low,ok'],
            // Approval queue: only items waiting for, or refused, approval.
            'approval' => ['nullable', 'string', 'in:pending,rejected'],
        ]);

        $query = StockItem::query()
            ->withSum('purchases as purchased_qty', 'qty')
            ->withSum('issues as issued_qty', 'qty');

        if ($search = $filters['search'] ?? null) {
            // Same three fields the Stock Report searches, so a term that finds
            // an item there finds it here too — including the case-insensitivity.
            // whereLike compiles to ILIKE on PostgreSQL, where a plain LIKE is
            // case-sensitive and quietly returns less than it should.
            $query->where(function ($q) use ($search) {
                // SKU too (2026-09-29): the code the Store reads off a label.
                foreach (['sku', 'name', 'brand', 'category'] as $column) {
                    $q->orWhereLike($column, '%'.$search.'%');
                }
            });
        }

        if ($category = $filters['category'] ?? null) {
            $query->where('category', $category);
        }

        if ($approval = $filters['approval'] ?? null) {
            $query->where('approval_status', $approval);
        }

        // Stock on hand is a sum across two relations, so status cannot be a
        // WHERE clause. Resolve the matching ids first, then narrow the
        // paginated query to them — that keeps the page count honest, which
        // filtering the fetched page afterwards would not.
        $statuses = $this->stockStatuses();

        if ($status = $filters['status'] ?? null) {
            $wanted = $statuses->filter(fn (string $s) => $status === 'attention'
                ? $s !== GeneralStockReportService::STATUS_OK
                : $s === $status);

            $query->whereIn('id', $wanted->keys());
        }

        // `partial` is a transport flag, not a filter — kept out of the pager
        // links so a "next page" href stays a real, shareable URL.
        $items = $query->orderBy('name')->paginate(25)
            ->appends(Arr::except($request->query(), ['partial', 'page']));

        ['edit' => $canEdit, 'delete' => $canDelete] = $this->storeCorrectionAbilities();
        // The Store admin maintains the Item Master as well.
        $canEdit = $canEdit || $this->isStoreAdmin();

        $categories = ItemCategory::selectable()->get(['id', 'name']);

        $canApprove = $this->canApproveItems();
        $pendingCount = StockItem::where('approval_status', StockItem::PENDING)->count();
        $uomCounts = $canApprove
            ? StockItem::query()->whereNotNull('uom')->where('uom', '<>', '')
                ->selectRaw('uom, COUNT(*) as items')->groupBy('uom')->orderBy('uom')->pluck('items', 'uom')
            : collect();

        // Counts for the toolbar chips. Whole master, not the current page —
        // "3 out of stock" has to mean three items, not three on this page.
        $statusCounts = $statuses->countBy();

        // Live search / filter change: the same action, returning only the part
        // of the page the filters affect. Sharing the action rather than adding
        // a second endpoint is what guarantees the AJAX result and the full page
        // come off the identical query and the identical paging.
        if ($request->boolean('partial')) {
            // `categories` too: the Edit modals ride inside this fragment and
            // their _item-fields include needs the category list.
            return view('store.stock._items-list', compact(
                'items', 'categories', 'canEdit', 'canDelete', 'filters', 'statusCounts', 'canApprove', 'pendingCount'
            ));
        }

        // Arrived from a requisition line the master has never carried: the
        // Add modal opens ready-filled with what the department typed, and
        // carries the line id so the save can link it. The line must still
        // be unlinked and must belong to the requisition named — a stale or
        // hand-typed link seeds nothing.
        $addFrom = null;
        if ($request->filled('requisition_item')) {
            $line = PurchaseRequisitionItem::query()->notInItemMaster()
                ->find($request->integer('requisition_item'));

            if ($line) {
                $addFrom = [
                    'requisition_item' => $line->id,
                    'requisition_no' => $line->requisition?->requisition_no,
                    'prefill' => ['name' => $line->custom_item_name, 'brand' => $line->specification],
                ];
            }
        }

        return view('store.stock.items', compact(
            'items', 'categories', 'canEdit', 'canDelete', 'filters', 'statusCounts', 'addFrom', 'canApprove', 'pendingCount', 'uomCounts'
        ));
    }

    /**
     * Current stock status of every item, keyed by id.
     *
     * Deliberately not a duplicate of the Stock Report's threshold logic — it
     * calls the same GeneralStockReportService rule, so "Low Stock" can only
     * ever mean one thing across the two screens.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    protected function stockStatuses(): \Illuminate\Support\Collection
    {
        return StockItem::query()
            ->select(['id', 'opening_qty', 'safety_stock_qty', 'reorder_level'])
            ->withSum('purchases as purchased_qty', 'qty')
            ->withSum('issues as issued_qty', 'qty')
            ->get()
            ->mapWithKeys(fn (StockItem $item) => [
                $item->id => GeneralStockReportService::statusFor(
                    (float) $item->opening_qty + (float) $item->purchased_qty - (float) $item->issued_qty,
                    $item->safety_stock_qty !== null ? (float) $item->safety_stock_qty : null,
                    $item->reorder_level !== null ? (float) $item->reorder_level : null,
                ),
            ]);
    }

    /**
     * Blank upload template. Headings come from ItemMasterImport::COLUMNS, so
     * the file a user downloads always matches what the importer reads.
     */
    public function template()
    {
        return Excel::download(new ItemMasterTemplateExport, 'Item-Master-Bulk-Upload-Template.xlsx');
    }

    /**
     * The item master as it stands, for reading and for building a price file.
     *
     * Read-only, so it rides the section's own view guard rather than a create
     * or edit right. NOT a round trip: re-uploading it through import() writes
     * nothing, because that importer only creates and every row already exists.
     */
    public function export()
    {
        return Excel::download(new ItemMasterExport, 'Item-Master-'.now()->format('Y-m-d').'.xlsx');
    }

    /** The blank three-column workbook for the Unit Price import. */
    public function priceTemplate()
    {
        return Excel::download(new UnitPriceTemplateExport, 'Unit-Price-Upload-Template.xlsx');
    }

    /**
     * Set Unit Price across many items at once.
     *
     * Writes ONE FIELD and creates nothing. That is the whole safety argument:
     * a stale file cannot reach Uom, Category, Opening Stock or Safety Stock,
     * because this never writes them, and a row matching no item is reported
     * rather than turned into a phantom item carrying a price and nothing else.
     *
     * All-or-nothing on errors, like the item import it sits beside: an
     * unmatched name is usually a column out of step or a wrong brand, and
     * applying the half of the file that happened to match would leave the user
     * unable to tell what had landed.
     */
    public function importPrices(Request $request)
    {
        $this->authorizeStoreEdit('unit price');

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:8192'],
        ], [], ['file' => 'upload file']);

        try {
            $sheets = Excel::toArray(new UnitPriceImport, $request->file('file'));
        } catch (\Throwable $e) {
            return back()->with('warning', 'That file could not be read. Please upload a CSV or Excel file based on the sample template.');
        }

        ['updates' => $updates, 'errors' => $errors, 'skipped' => $skipped] = UnitPriceImport::parse($sheets[0] ?? []);

        if ($errors) {
            return back()
                ->with('warning', 'Nothing was updated. Please correct '.count($errors).' row(s) and upload the file again.')
                ->with('import_errors', $errors)
                ->with('import_skipped', $skipped);
        }

        if (empty($updates)) {
            return back()
                ->with('warning', $skipped
                    ? 'No prices were updated. See the skipped rows below.'
                    : 'No priced rows were found in that file. Please use the sample template.')
                ->with('import_skipped', $skipped);
        }

        DB::transaction(function () use ($updates) {
            foreach ($updates as $update) {
                // Only the one column. Written with the query builder rather
                // than a model save so nothing else on the row can be carried
                // along by accident.
                StockItem::whereKey($update['id'])->update(['unit_price' => $update['unit_price']]);
            }
        });

        $count = count($updates);

        return back()
            ->with('success', $count.' item'.($count === 1 ? '' : 's').' updated with a unit price.'
                .($skipped ? ' '.count($skipped).' row(s) were skipped.' : ''))
            ->with('import_skipped', $skipped);
    }

    /**
     * Bulk item upload.
     *
     * All-or-nothing: the whole file is validated first and written inside a
     * transaction, so a file with a bad row leaves the item master exactly as
     * it was rather than half-loaded. Row numbers in the messages are the ones
     * the user sees in Excel.
     *
     * Adding items is routine store work, so this needs no permission beyond
     * reaching the screen — the same as the single Add Item form.
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:4096'],
        ], [], ['file' => 'upload file']);

        try {
            $sheets = Excel::toArray(new ItemMasterImport, $request->file('file'));
        } catch (\Throwable $e) {
            return back()->with('warning', 'That file could not be read. Please upload a CSV or Excel file based on the sample template.');
        }

        ['items' => $items, 'errors' => $errors, 'skipped' => $skipped] = ItemMasterImport::parse($sheets[0] ?? []);

        // Nothing is written while any row is invalid — the user fixes the file
        // and re-uploads it whole.
        if ($errors) {
            return back()
                ->with('warning', 'Nothing was imported. Please correct '.count($errors).' row(s) and upload the file again.')
                ->with('import_errors', $errors)
                ->with('import_skipped', $skipped);
        }

        if (empty($items)) {
            return back()
                // Do not guess WHY every row was skipped — a row can be a
                // duplicate, listed twice, or the template's example row. The
                // skipped list is shown right below this, so point at it.
                ->with('warning', $skipped
                    ? 'No new items were imported. See the skipped rows below.'
                    : 'No item rows were found in that file. Please use the sample template.')
                ->with('import_skipped', $skipped);
        }

        $userId = auth()->id();

        DB::transaction(function () use ($items, $userId) {
            // Category names in the file are resolved against the Category
            // master and created when new, so an imported item is linked the
            // same way one added through the form is. Cached per run so a
            // 500-row file with 8 categories does 8 lookups, not 500.
            $categories = [];

            foreach ($items as $item) {
                $name = $item['category'];
                $key = mb_strtolower($name);

                $categories[$key] ??= $this->resolveMasterValue(ItemCategory::class, self::NEW_VALUE_PREFIX.$name);

                $item['item_category_id'] = $categories[$key];
                // Store the master's canonical spelling, so two rows written
                // "needle" and "Needle" end up reading identically.
                $item['category'] = ItemCategory::find($categories[$key])?->name ?? $name;

                StockItem::create($item + ['created_by' => $userId] + $this->approvalFor());
            }
        });

        $pending = $this->canApproveItems() ? '' : ' They are waiting for approval before they can be used.';

        if (! $this->canApproveItems()) {
            $this->notifyApprovers(count($items).' new item(s) imported by '.auth()->user()->name.' are waiting for your approval.');
        }

        return back()
            ->with('success', count($items).' item(s) imported.'.$pending
                .($skipped ? ' '.count($skipped).' row(s) were skipped.' : ''))
            ->with('import_skipped', $skipped);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['created_by'] = auth()->id();

        // A user who cannot approve submits the item for approval: it is kept
        // inactive, so it stays out of every picker and report, until approved.
        $data = array_merge($data, $this->approvalFor());

        $item = StockItem::create($data);
        $awaiting = ! $item->isApproved();

        if ($awaiting) {
            $this->notifyApprovers('New item "'.$item->name.'" added by '.auth()->user()->name.' is waiting for your approval.');
        }

        // Opened from a requisition line that named an item the master did
        // not have ("Not in Item Master"). Now that it does, the line is
        // pointed at the new record and the Store is taken back to the sheet
        // they were reading. Only a line that is STILL unlinked is touched —
        // a second click on a stale page cannot re-point a line somebody
        // else already resolved.
        $line = $request->filled('requisition_item')
            ? PurchaseRequisitionItem::query()->notInItemMaster()->find($request->integer('requisition_item'))
            : null;

        if ($line) {
            $line->linkToStockItem($item);

            return redirect()
                ->route('store.stock.requisitions.show', $line->purchase_requisition_id)
                ->with('success', 'Stock item "'.$data['name'].'" added to the Item Master and linked to this requisition.'.($awaiting ? ' It is waiting for approval before it can be issued or received.' : ''));
        }

        return back()->with('success', $awaiting
            ? 'Stock item "'.$data['name'].'" submitted for approval. It will be usable once the Store admin approves it.'
            : 'Stock item "'.$data['name'].'" added.');
    }

    public function update(Request $request, StockItem $stockItem)
    {
        // The Store admin maintains the Item Master; everyone else needs the
        // usual correction permission.
        if (! $this->isStoreAdmin()) {
            $this->authorizeStoreEdit('stock item');
        }

        $data = $this->validated($request);

        // An unapproved item cannot be switched on by editing it.
        if (! $stockItem->isApproved()) {
            $data['is_active'] = false;
        }

        $stockItem->update($data);

        return back()->with('success', 'Stock item updated.');
    }

    /** Approve a pending (or previously rejected) item: it becomes usable. */
    public function approve(StockItem $stockItem)
    {
        $this->authorizeApproval();

        $stockItem->update([
            'approval_status' => StockItem::APPROVED,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'is_active' => true,
        ]);

        return back()->with('success', 'Stock item "'.$stockItem->name.'" approved and added to the Item Master.');
    }

    /** Refuse a pending item. It stays on file, inactive, and can be approved later. */
    public function reject(StockItem $stockItem)
    {
        $this->authorizeApproval();

        if ($stockItem->isApproved()) {
            return back()->with('warning', 'This item is already approved.');
        }

        $stockItem->update([
            'approval_status' => StockItem::REJECTED,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'is_active' => false,
        ]);

        return back()->with('success', 'Stock item "'.$stockItem->name.'" rejected.');
    }

    /**
     * Merge a duplicate UOM into the one to keep. Every item carrying the
     * duplicate is moved across, which is what removes the duplicate from the
     * list: there is no separate UOM table, the list is what items use.
     */
    public function mergeUom(Request $request)
    {
        $this->authorizeApproval();

        $data = $request->validate([
            'from_uom' => ['required', 'string', 'max:50'],
            'to_uom' => ['required', 'string', 'max:50', 'different:from_uom'],
        ], [], ['from_uom' => 'UOM to remove', 'to_uom' => 'UOM to keep']);

        $moved = StockItem::where('uom', $data['from_uom'])->update(['uom' => $data['to_uom']]);

        return back()->with('success', '"'.$data['from_uom'].'" merged into "'.$data['to_uom'].'" — '.$moved.' item(s) updated.');
    }

    /**
     * Who approves new items: the super admin, the Store admin (a Store user
     * marked Department Admin), or anyone granted store.items.approve.
     */
    protected function canApproveItems(): bool
    {
        $user = auth()->user();

        return (bool) $user && ($user->hasRole('admin') || $this->isStoreAdmin() || $user->can('store.items.approve'));
    }

    /** Tell the item approvers (the Store admins) that items wait for them. */
    protected function notifyApprovers(string $message): void
    {
        foreach (StockItem::approvers() as $approver) {
            \App\Models\AppNotification::create([
                'user_id' => $approver->id,
                'actor_id' => auth()->id(),
                'type' => 'stock_item_approval',
                'title' => 'Item Approval Request',
                'message' => $message,
                'url' => route('store.stock.items.index', ['approval' => 'pending']),
            ]);
        }
    }

    /** A Store user marked Department Admin on Users & Roles. */
    protected function isStoreAdmin(): bool
    {
        return StockItem::isStoreAdmin(auth()->user());
    }

    protected function authorizeApproval(): void
    {
        abort_unless($this->canApproveItems(), 403, 'You do not have permission to approve items.');
    }

    /** @return array<string, mixed> approval fields for a newly created item */
    protected function approvalFor(): array
    {
        if ($this->canApproveItems()) {
            return ['approval_status' => StockItem::APPROVED, 'approved_by' => auth()->id(), 'approved_at' => now()];
        }

        return ['approval_status' => StockItem::PENDING, 'is_active' => false];
    }

    public function destroy(StockItem $stockItem)
    {
        $this->authorizeStoreDelete('stock item');

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
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // The single "Brand/Specification" field. `size` and `specification`
            // are no longer written from anywhere — they stay on the table
            // carrying their old values and are simply not accepted here.
            'brand' => ['nullable', 'string', 'max:255'],
            'uom' => ['nullable', 'string', 'max:50'],
            // Accepts an existing master id or "new:<name>", like the four
            // dropdowns on the Issue form.
            'item_category_id' => ['nullable', 'string', 'max:160'],
            'opening_qty' => ['nullable', 'numeric'],
            // No longer required_with:opening_qty. A quantity still needs a date
            // to be meaningful — the Stock Report uses it to decide which months
            // the counted figure appears in — but refusing the form was the
            // wrong way to get one, because the answer is almost always "today"
            // and the form now prefills exactly that. Left blank anyway, today
            // is filled in below, which is what the bulk import has always done.
            'opening_as_on' => ['nullable', 'date'],
            // Optional, like every other number on this form: plenty of items
            // are set up before a price is settled, and requiring one would
            // block a bulk import of items priced later.
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            // Optional overrides — left blank, the Stock Report calculates
            // these from last month's consumption the way the Excel does.
            'safety_stock_qty' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'lead_time_days' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ], [], ['item_category_id' => 'category', 'brand' => 'brand/specification']);

        // A counted quantity with no date would sit outside every month the
        // report can place it, so today stands in — the same fallback
        // ItemMasterImport applies to a blank Counted On column.
        if (filled($data['opening_qty'] ?? null) && blank($data['opening_as_on'] ?? null)) {
            $data['opening_as_on'] = now()->toDateString();
        }

        $data['item_category_id'] = $this->resolveMasterValue(ItemCategory::class, $data['item_category_id'] ?? null);

        // Keep the free-text column in step: it is what the Stock Report, its
        // exports, the search and the category filter actually read.
        $data['category'] = $data['item_category_id']
            ? ItemCategory::find($data['item_category_id'])?->name
            : null;

        return $data;
    }
}
