<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Services\VendorMaster;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    public function index()
    {
        $suppliers = Supplier::latest()->paginate(15);

        return view('admin.suppliers.index', compact('suppliers'));
    }

    public function create()
    {
        return view('admin.suppliers.create');
    }

    public function store(Request $request)
    {
        $supplier = app(VendorMaster::class)->create($this->validatedData($request));

        return redirect()
            ->route('vendors.show', $supplier)
            ->with('success', 'Vendor created as a draft. Add contacts, addresses and bank details, then submit it for approval.');
    }

    public function edit(Supplier $supplier)
    {
        return view('admin.suppliers.edit', compact('supplier'));
    }

    public function update(Request $request, Supplier $supplier)
    {
        $held = app(VendorMaster::class)->update($supplier, $this->validatedData($request, $supplier));

        return redirect()
            ->route('vendors.show', $supplier)
            ->with('success', 'Vendor updated.' . ($held ? ' Some changes wait for approval.' : ''));
    }

    public function destroy(Supplier $supplier)
    {
        $supplier->delete();

        return redirect()
            ->route('admin.suppliers.index')
            ->with('success', 'Supplier deleted successfully.');
    }

    /*
     * Excel export / upload template / bulk import. Same file format and rules as
     * Merchant > Vendors, so both screens delegate to one implementation; an
     * import only adds new vendors and never overwrites an existing one.
     */
    public function export(Request $request)
    {
        return app(\App\Http\Controllers\Merchant\VendorEntryController::class)->export($request);
    }

    public function template()
    {
        return app(\App\Http\Controllers\Merchant\VendorEntryController::class)->template();
    }

    public function import(Request $request)
    {
        return app(\App\Http\Controllers\Merchant\VendorEntryController::class)->import($request);
    }

    public function validatedData(Request $request, ?Supplier $supplier = null): array
    {
        $data = $request->validate([
            'supplier_code' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('suppliers', 'supplier_code')->ignore($supplier?->id),
            ],
            'supplier_name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'vendor_type' => ['nullable', 'in:local,foreign'],
            'vendor_category' => ['nullable', 'string', 'max:100'],

            'tin' => ['nullable', 'string', 'max:100'],
            'vat_bin_number' => ['nullable', 'string', 'max:100'],
            'business_reg_number' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],

            'default_currency' => ['nullable', 'string', 'max:10'],
            'default_payment_terms' => ['nullable', 'string', 'max:150'],
            'item_type' => ['nullable', 'string', 'max:255'],
            'item_types' => ['nullable', 'array'],
            'item_types.*' => ['nullable', 'string', 'max:100'],
            'incoterm' => ['nullable', 'string', 'max:50'],
            'ship_mode' => ['nullable', 'string', 'max:50'],
            'tolerance_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:999'],
            'moq' => ['nullable', 'numeric', 'min:0'],

            'effective_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:effective_date'],
            'external_erp_code' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        // Contacts, addresses, item categories and bank accounts are kept on the vendor
        // profile (own tables, bank changes need approval); status changes go through the
        // approval workflow, so none of those are accepted from this form.
        $data['vendor_type'] = $data['vendor_type'] ?? 'local';

        // The form sends Item Type as several tick boxes (a vendor can supply both
        // fabric and accessories); the column stays one text value, "Fabric, Accessories".
        if ($request->has('item_types')) {
            $data['item_type'] = implode(', ', array_values(array_unique(
                array_filter(array_map('trim', $data['item_types'] ?? []), fn ($v) => $v !== '')
            ))) ?: null;
        }
        unset($data['item_types']);

        // An empty code is no code, and has to be stored as null rather than "".
        // The unique index counts empty strings as equal to each other, so a
        // second vendor saved without a code would fail on a duplicate key;
        // MySQL treats NULLs as distinct, which is the behaviour wanted here.
        //
        // On a normal request ConvertEmptyStringsToNull has already done this.
        // Stated here anyway so the rule holds for any caller that does not run
        // the HTTP middleware - a console command, a test, a future API route.
        //
        // Compared against "" rather than using ?:, which would also null a
        // supplier legitimately coded "0".
        $code = trim((string) ($data['supplier_code'] ?? ''));
        $data['supplier_code'] = $code === '' ? null : $code;

        return $data;
    }
}