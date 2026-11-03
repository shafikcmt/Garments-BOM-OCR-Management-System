<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;

/**
 * Merchant > Vendors. Lets Merchandising see the vendor list and add a vendor,
 * bank details included. It reads and writes the same `suppliers` list Admin >
 * Vendors manages and applies the same rules (SupplierController::validatedData);
 * editing and deleting a vendor stays with Admin.
 *
 * The list shows whether bank details are on file and the bank name, never the
 * account number: that stays on the Admin screen.
 */
class VendorEntryController extends Controller
{
    public function index(Request $request)
    {
        $term = trim((string) $request->input('q'));

        $vendors = Supplier::query()
            ->when($term !== '', function ($query) use ($term) {
                // whereLike compiles to ILIKE on PostgreSQL, LIKE on MySQL.
                $query->where(function ($match) use ($term) {
                    foreach (['supplier_name', 'supplier_code', 'legal_name', 'contact_person', 'city', 'country', 'item_type'] as $column) {
                        $match->orWhereLike($column, '%' . $term . '%');
                    }
                });
            })
            ->orderBy('supplier_name')
            ->paginate(25)
            ->withQueryString();

        $withBank = fn ($query) => $query->where(function ($q) {
            $q->whereNotNull('bank_name')->orWhereNotNull('bank_account_number');
        });

        $totals = [
            'all' => Supplier::count(),
            'active' => Supplier::active()->count(),
            'with_bank' => $withBank(Supplier::query())->count(),
        ];

        return view('merchant.vendors.index', compact('vendors', 'totals', 'term'));
    }

    public function create()
    {
        return view('merchant.vendors.create');
    }

    public function store(Request $request)
    {
        $supplier = Supplier::create(app(SupplierController::class)->validatedData($request));

        return redirect()
            ->route('merchant.vendors.index')
            ->with('success', 'Vendor "' . e($supplier->supplier_name) . '" added.');
    }
}
