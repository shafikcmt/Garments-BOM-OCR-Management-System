<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupplierOption;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Admin screen to add or delete the Item Type, Incoterm and Ship Mode choices
 * used on the vendor form. Deleting an option never touches vendors that already
 * hold it: their stored text stays and still prints on the PO.
 */
class SupplierOptionController extends Controller
{
    public function index()
    {
        $options = SupplierOption::orderBy('sort_order')->orderBy('name')->get()->groupBy('type');

        return view('admin.supplier-options.index', ['options' => $options, 'types' => SupplierOption::TYPES]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(SupplierOption::TYPES))],
            'name' => ['required', 'string', 'max:100'],
        ]);
        $name = trim($data['name']);

        if (str_contains($name, ',') && $data['type'] === 'item_type') {
            throw ValidationException::withMessages(['name' => 'Item Type name cannot contain a comma.']);
        }

        $exists = SupplierOption::where('type', $data['type'])
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists();
        if ($exists) {
            throw ValidationException::withMessages(['name' => '"' . $name . '" is already in this list.']);
        }

        SupplierOption::create([
            'type' => $data['type'],
            'name' => $name,
            'sort_order' => (int) SupplierOption::where('type', $data['type'])->max('sort_order') + 1,
        ]);

        return redirect()->route('admin.supplier-options.index')
            ->with('success', SupplierOption::TYPES[$data['type']] . ': "' . $name . '" added.');
    }

    public function destroy(SupplierOption $supplierOption)
    {
        $supplierOption->delete();

        return redirect()->route('admin.supplier-options.index')
            ->with('success', '"' . $supplierOption->name . '" removed. Vendors that already use it keep their value.');
    }
}
