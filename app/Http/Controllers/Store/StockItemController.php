<?php

namespace App\Http\Controllers\Store;

use App\Exports\ItemMasterTemplateExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesStoreCorrections;
use App\Http\Controllers\Concerns\ResolvesIssueSetupMasters;
use App\Imports\ItemMasterImport;
use App\Models\ItemCategory;
use App\Models\StockItem;
use App\Services\GeneralStockReportService;
use Illuminate\Http\Request;
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
        ]);

        $query = StockItem::query()
            ->withSum('purchases as purchased_qty', 'qty')
            ->withSum('issues as issued_qty', 'qty');

        if ($search = $filters['search'] ?? null) {
            // Same three fields the Stock Report searches, so a term that finds
            // an item there finds it here too.
            $query->where(function ($q) use ($search) {
                foreach (['name', 'brand', 'category'] as $column) {
                    $q->orWhere($column, 'like', '%'.$search.'%');
                }
            });
        }

        if ($category = $filters['category'] ?? null) {
            $query->where('category', $category);
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

        $items = $query->orderBy('name')->paginate(25)->withQueryString();

        ['edit' => $canEdit, 'delete' => $canDelete] = $this->storeCorrectionAbilities();

        $categories = ItemCategory::selectable()->get(['id', 'name']);

        // Counts for the toolbar chips. Whole master, not the current page —
        // "3 out of stock" has to mean three items, not three on this page.
        $statusCounts = $statuses->countBy();

        return view('store.stock.items', compact(
            'items', 'categories', 'canEdit', 'canDelete', 'filters', 'statusCounts'
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

                StockItem::create($item + ['created_by' => $userId]);
            }
        });

        return back()
            ->with('success', count($items).' item(s) imported.'
                .($skipped ? ' '.count($skipped).' row(s) were skipped.' : ''))
            ->with('import_skipped', $skipped);
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
        $this->authorizeStoreEdit('stock item');

        $stockItem->update($this->validated($request));

        return back()->with('success', 'Stock item updated.');
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
            'opening_as_on' => ['nullable', 'date', 'required_with:opening_qty'],
            // Optional overrides — left blank, the Stock Report calculates
            // these from last month's consumption the way the Excel does.
            'safety_stock_qty' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'lead_time_days' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ], [], ['item_category_id' => 'category', 'brand' => 'brand/specification']);

        $data['item_category_id'] = $this->resolveMasterValue(ItemCategory::class, $data['item_category_id'] ?? null);

        // Keep the free-text column in step: it is what the Stock Report, its
        // exports, the search and the category filter actually read.
        $data['category'] = $data['item_category_id']
            ? ItemCategory::find($data['item_category_id'])?->name
            : null;

        return $data;
    }
}
