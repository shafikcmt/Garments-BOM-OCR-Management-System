<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Controller;
use App\Exports\VendorWorkbookExport;
use App\Imports\VendorListImport;
use App\Imports\VendorWorkbookImport;
use App\Models\Supplier;
use App\Services\VendorMaster;
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

        $totals = [
            'all' => Supplier::count(),
            'active' => Supplier::active()->count(),
            'pending' => Supplier::whereIn('approval_status', ['submitted', 'under_review'])->count(),
        ];

        return view('merchant.vendors.index', compact('vendors', 'totals', 'term'));
    }

    public function create()
    {
        return view('merchant.vendors.create');
    }

    public function store(Request $request)
    {
        $supplier = app(VendorMaster::class)->create(app(SupplierController::class)->validatedData($request));

        return redirect()
            ->route('vendors.show', $supplier)
            ->with('success', 'Vendor "' . e($supplier->supplier_name) . '" added as a draft. Add contacts and addresses, then submit it for approval.');
    }

    public function edit(Supplier $supplier)
    {
        return view('merchant.vendors.edit', compact('supplier'));
    }

    public function update(Request $request, Supplier $supplier)
    {
        $held = app(VendorMaster::class)->update($supplier, app(SupplierController::class)->validatedData($request, $supplier));

        return redirect()
            ->route('vendors.show', $supplier)
            ->with('success', 'Vendor "' . e($supplier->supplier_name) . '" updated.' . ($held ? ' Some changes wait for approval.' : ''));
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

    /** The vendor workbook (vendors, contacts, addresses, categories; banks only for those allowed): what the search box shows, or everything. */
    public function export(Request $request)
    {
        $withBanks = (bool) $request->user()?->can('vendors.bank.view');
        $vendors = $this->searched(trim((string) $request->input('q')))->orderBy('supplier_name')
            ->with(['contacts', 'addresses', 'itemCategories'] + ($withBanks ? ['bankAccounts'] : []))->get();

        return Excel::download(new VendorWorkbookExport($vendors, $withBanks), 'Vendors-' . now()->format('Y-m-d') . '.xlsx');
    }

    /** Blank upload workbook: one sheet per kind of record, with example rows and instructions. */
    public function template()
    {
        return Excel::download(new VendorWorkbookExport, 'Vendor-Upload-Template.xlsx');
    }

    /**
     * Bulk vendor upload (Vendors / Contacts / Addresses / Item Categories / Bank Accounts sheets,
     * or the old single-sheet file). All-or-nothing in one transaction; only inserts: a vendor
     * already on the list is reported as skipped, never overwritten. New vendors are drafts and
     * bank rows become pending change requests.
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

        ['vendors' => $vendors, 'errors' => $errors, 'skipped' => $skipped] = VendorWorkbookImport::parse($sheets, (bool) $request->user()?->can('vendors.bank.manage'));

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

        $n = app(VendorMaster::class)->import($vendors);

        return back()
            ->with('success', sprintf(
                'Import summary: %d vendor(s) created as drafts, %d contact(s), %d address(es), %d item category(ies), %d bank request(s) waiting for approval.%s',
                $n['vendors'], $n['contacts'], $n['addresses'], $n['categories'], $n['bank_requests'], $skipped ? ' ' . count($skipped) . ' row(s) were skipped.' : ''
            ))
            ->with('import_skipped', $skipped);
    }
}
