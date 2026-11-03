<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Controller;
use App\Exports\VendorListExport;
use App\Exports\VendorTemplateExport;
use App\Imports\VendorListImport;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Merchant > Vendors. Lets Merchandising see the vendor list, add a vendor and
 * edit one, bank details included. It reads and writes the same `suppliers` list
 * Admin > Vendors manages and applies the same rules
 * (SupplierController::validatedData); deleting a vendor stays with Admin.
 *
 * The list shows whether bank details are on file and the bank name, never the
 * account number; the edit form shows it, because the person editing needs to
 * correct it.
 */
class VendorEntryController extends Controller
{
    public function index(Request $request)
    {
        $term = trim((string) $request->input('q'));

        $vendors = $this->searched($term)
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

    public function edit(Supplier $supplier)
    {
        return view('merchant.vendors.edit', compact('supplier'));
    }

    public function update(Request $request, Supplier $supplier)
    {
        $supplier->update(app(SupplierController::class)->validatedData($request, $supplier));

        return redirect()
            ->route('merchant.vendors.index')
            ->with('success', 'Vendor "' . e($supplier->supplier_name) . '" updated.');
    }

    /** Vendors matching the search box (all of them when it is empty). */
    private function searched(string $term)
    {
        return Supplier::query()->when($term !== '', function ($query) use ($term) {
            // whereLike compiles to ILIKE on PostgreSQL, LIKE on MySQL.
            $query->where(function ($match) use ($term) {
                foreach (['supplier_name', 'supplier_code', 'legal_name', 'contact_person', 'city', 'country', 'item_type'] as $column) {
                    $match->orWhereLike($column, '%' . $term . '%');
                }
            });
        });
    }

    /** The vendor list as Excel: what the search box shows, or everything. */
    public function export(Request $request)
    {
        $vendors = $this->searched(trim((string) $request->input('q')))->orderBy('supplier_name')->get();

        return Excel::download(new VendorListExport($vendors), 'Vendors-' . now()->format('Y-m-d') . '.xlsx');
    }

    /** Blank upload template, built from VendorListImport::COLUMNS. */
    public function template()
    {
        return Excel::download(new VendorTemplateExport, 'Vendor-Upload-Template.xlsx');
    }

    /**
     * Bulk vendor upload. All-or-nothing inside a transaction, and only ever
     * inserts: a vendor already on the list is reported as skipped, never
     * overwritten. Same rules as the on-screen form (VendorListImport::parse).
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:4096'],
        ], [], ['file' => 'upload file']);

        try {
            $sheets = Excel::toArray(new VendorListImport, $request->file('file'));
        } catch (\Throwable $e) {
            return back()->with('warning', 'That file could not be read. Please upload a CSV or Excel file based on the upload template.');
        }

        ['vendors' => $vendors, 'errors' => $errors, 'skipped' => $skipped] = VendorListImport::parse($sheets[0] ?? []);

        if ($errors) {
            return back()
                ->with('warning', 'Nothing was imported. Please correct ' . count($errors) . ' problem(s) and upload the file again.')
                ->with('import_errors', array_slice($errors, 0, 50))
                ->with('import_skipped', $skipped);
        }

        if ($vendors === []) {
            return back()
                ->with('warning', $skipped ? 'No new vendors were imported. See the skipped rows below.' : 'No vendor rows were found in that file. Please use the upload template.')
                ->with('import_skipped', $skipped);
        }

        DB::transaction(function () use ($vendors) {
            foreach ($vendors as $vendor) {
                Supplier::create($vendor);
            }
        });

        return back()
            ->with('success', count($vendors) . ' vendor(s) imported.' . ($skipped ? ' ' . count($skipped) . ' row(s) were skipped.' : ''))
            ->with('import_skipped', $skipped);
    }
}
