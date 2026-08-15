<?php

namespace App\Http\Controllers\Store;

use App\Exports\SupplierTemplateExport;
use App\Http\Controllers\Concerns\AuthorizesStoreCorrections;
use App\Http\Controllers\Controller;
use App\Imports\SupplierListImport;
use App\Models\GeneralStockSupplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

/**
 * General Stock (module A) — "Purchase Setup": the supplier list behind the
 * Record Purchase screen's Supplier Name dropdown.
 *
 * This is General Stock's own vendor list and has nothing to do with the
 * Buyer/Style module's nominated `suppliers` table, which is never read here.
 *
 * Access follows the rest of the store module: adding a supplier is routine
 * store work, while editing or removing one is an Admin / Management right.
 */
class PurchaseSetupController extends Controller
{
    use AuthorizesStoreCorrections;

    /** Section this controller belongs to, for the section-scoped correction
     *  permissions. The flat store.edit / store.delete still apply too. */
    protected string $storeSection = 'store.setup';

    public function index()
    {
        ['edit' => $canEdit, 'delete' => $canDelete] = $this->storeCorrectionAbilities();

        $suppliers = GeneralStockSupplier::withCount('purchases')->orderBy('name')
            ->get(['id', 'name', 'is_active', 'created_at']);

        return view('store.stock.purchase-setup', compact('suppliers', 'canEdit', 'canDelete'));
    }

    /**
     * One supplier: its contact details, and every General Stock purchase
     * recorded against it.
     *
     * Read-only reporting over rows that already exist. Purchases are linked by
     * `general_stock_supplier_id`; the ones recorded before that link existed
     * carry only a `supplier_name` string and are deliberately NOT matched by
     * name here — a guessed match would put someone else's purchase on this
     * vendor's page.
     */
    public function show(GeneralStockSupplier $supplier)
    {
        ['edit' => $canEdit] = $this->storeCorrectionAbilities();

        $purchases = $supplier->purchases()
            ->with('stockItem:id,name,uom')
            ->orderByDesc('purchase_date')
            ->orderByDesc('id')
            ->get();

        // qty * unit_price rather than a stored total: stock_purchases has no
        // amount column, and the line total is shown the same way.
        $summary = [
            'lines' => $purchases->count(),
            'deliveries' => $purchases->pluck('rv_no')->filter()->unique()->count(),
            'total' => $purchases->sum(fn ($p) => (float) $p->qty * (float) $p->unit_price),
            'last_date' => optional($purchases->first())->purchase_date,
        ];

        return view('store.stock.supplier-detail', compact('supplier', 'purchases', 'summary', 'canEdit'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['created_by'] = auth()->id();

        GeneralStockSupplier::create($data);

        return back()->with('success', 'Supplier "'.$data['name'].'" added.');
    }

    public function update(Request $request, GeneralStockSupplier $supplier)
    {
        $this->authorizeStoreEdit('supplier');

        $supplier->update($this->validated($request, $supplier->id));

        return back()->with('success', 'Supplier updated.');
    }

    public function destroy(GeneralStockSupplier $supplier)
    {
        $this->authorizeStoreDelete('supplier');

        // Soft delete keeps the name resolvable on purchases that already used
        // it, so history never shows a blank supplier.
        $supplier->delete();

        return back()->with('success', 'Supplier removed. Existing purchase records keep the name.');
    }

    /**
     * Remove several suppliers in one go.
     *
     * The list arrives by bulk upload and lands with typo duplicates — the same
     * workshop spelled two ways — so clearing it one row at a time is not
     * realistic on a list this long.
     *
     * Unlike the Issue Setup masters, a supplier in use is NOT refused here.
     * That matches this screen's single-row delete, which has always
     * soft-deleted regardless of use: purchases store a copy of the supplier
     * name, so history keeps reading correctly either way.
     */
    public function bulkDestroy(Request $request)
    {
        $this->authorizeStoreDelete('supplier');

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ], [
            'ids.required' => 'Select at least one supplier to delete.',
        ]);

        $suppliers = GeneralStockSupplier::whereIn('id', $data['ids'])->get();

        if ($suppliers->isEmpty()) {
            return back()->with('warning', 'Nothing was deleted — those suppliers no longer exist.');
        }

        GeneralStockSupplier::whereIn('id', $suppliers->pluck('id'))->delete();

        return back()->with('success', $suppliers->count().' supplier(s) removed. '
            .'Existing purchase records keep the name.');
    }

    /** Blank upload template, built from SupplierListImport::COLUMNS. */
    public function template()
    {
        return Excel::download(new SupplierTemplateExport, 'General-Stock-Supplier-Template.xlsx');
    }

    /**
     * Bulk supplier upload. All-or-nothing inside a transaction, and only ever
     * inserts — an existing name is reported as skipped, never overwritten.
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:4096'],
        ], [], ['file' => 'upload file']);

        try {
            $sheets = Excel::toArray(new SupplierListImport, $request->file('file'));
        } catch (\Throwable $e) {
            return back()->with('warning', 'That file could not be read. Please upload a CSV or Excel file based on the sample template.');
        }

        ['suppliers' => $suppliers, 'errors' => $errors, 'skipped' => $skipped] =
            SupplierListImport::parse($sheets[0] ?? []);

        if ($errors) {
            return back()
                ->with('warning', 'Nothing was imported. Please correct '.count($errors).' row(s) and upload the file again.')
                ->with('import_errors', $errors)
                ->with('import_skipped', $skipped);
        }

        if (empty($suppliers)) {
            return back()
                // Do not guess WHY every row was skipped — a row can be a
                // duplicate, listed twice, or the template's example row. The
                // skipped list is shown right below this, so point at it.
                ->with('warning', $skipped
                    ? 'No new suppliers were imported. See the skipped rows below.'
                    : 'No supplier rows were found in that file. Please use the sample template.')
                ->with('import_skipped', $skipped);
        }

        $userId = auth()->id();

        DB::transaction(function () use ($suppliers, $userId) {
            foreach ($suppliers as $supplier) {
                GeneralStockSupplier::create($supplier + ['created_by' => $userId]);
            }
        });

        return back()
            ->with('success', count($suppliers).' supplier(s) imported.'
                .($skipped ? ' '.count($skipped).' row(s) were skipped.' : ''))
            ->with('import_skipped', $skipped);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                // Unique among live rows only — a soft-deleted name must not
                // block re-adding it, which is why this is not a DB index.
                Rule::unique('general_stock_suppliers', 'name')->whereNull('deleted_at')->ignore($ignoreId),
            ],
            // Contact details, all optional. A supplier the store has only ever
            // been given a name for must still be addable.
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            // is_active is an edit-time toggle only — it decides whether the
            // supplier still appears in the Record Purchase dropdown.
            'is_active' => ['nullable', 'boolean'],
        ], [
            'name.unique' => 'This supplier is already in the list.',
        ], ['name' => 'supplier name']);
    }
}
