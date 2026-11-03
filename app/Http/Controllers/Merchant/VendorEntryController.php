<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;

/**
 * Merchant > Add Vendor. Lets Merchandising add a vendor, bank details
 * included, from its own dashboard. It writes the same `suppliers` list Admin >
 * Vendors manages and applies the same rules (SupplierController::validatedData);
 * editing and deleting a vendor stays with Admin.
 */
class VendorEntryController extends Controller
{
    public function create()
    {
        return view('merchant.vendors.create');
    }

    public function store(Request $request)
    {
        $supplier = Supplier::create(app(SupplierController::class)->validatedData($request));

        return redirect()
            ->route('merchant.dashboard')
            ->with('success', 'Vendor "' . e($supplier->supplier_name) . '" added.');
    }
}
