<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
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
        Supplier::create($this->validatedData($request));

        return redirect()
            ->route('admin.suppliers.index')
            ->with('success', 'Supplier created successfully.');
    }

    public function edit(Supplier $supplier)
    {
        return view('admin.suppliers.edit', compact('supplier'));
    }

    public function update(Request $request, Supplier $supplier)
    {
        $supplier->update($this->validatedData($request, $supplier));

        return redirect()
            ->route('admin.suppliers.index')
            ->with('success', 'Supplier updated successfully.');
    }

    public function destroy(Supplier $supplier)
    {
        $supplier->delete();

        return redirect()
            ->route('admin.suppliers.index')
            ->with('success', 'Supplier deleted successfully.');
    }

    private function validatedData(Request $request, ?Supplier $supplier = null): array
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

            'contact_person' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],

            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],

            'item_type' => ['nullable', 'string', 'max:100'],
            'incoterm' => ['nullable', 'string', 'max:50'],
            'ship_mode' => ['nullable', 'string', 'max:50'],
            'tolerance_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active');

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