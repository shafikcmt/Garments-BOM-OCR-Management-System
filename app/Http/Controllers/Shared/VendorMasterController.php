<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Controller;
use App\Models\BusinessUnit;
use App\Models\Supplier;
use App\Models\SupplierAddress;
use App\Models\SupplierAuditLog;
use App\Models\SupplierBankAccount;
use App\Models\SupplierBankChangeRequest;
use App\Models\SupplierBusinessUnit;
use App\Models\SupplierContact;
use App\Models\SupplierItem;
use App\Models\SupplierItemCategory;
use App\Services\VendorMaster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Vendor profile: the one screen where a vendor's workflow, contacts, addresses,
 * item categories, business units, bank accounts and audit trail are kept.
 *
 * Shared by Admin, Merchant, Supply Chain, Accounts ...: who may do what is decided
 * here by permission (see VendorMaster for the rules), not by which menu opened it.
 */
class VendorMasterController extends Controller
{
    public function __construct(private VendorMaster $master)
    {
    }

    /** Anyone who could already see the vendor list, plus holders of vendors.view. */
    private function mayView(): bool
    {
        $u = auth()->user();

        return $u && ($u->hasAnyRole(['admin', 'merchant']) || collect(['vendors.view', 'vendors.edit', 'vendors.create', 'vendors.approve', 'vendors.block', 'vendors.bank.view', 'vendors.bank.manage', 'vendors.bank.approve'])->contains(fn ($p) => $u->can($p)));
    }

    private function mayEdit(): bool
    {
        $u = auth()->user();

        return $u && ($u->hasAnyRole(['admin', 'merchant']) || $u->can('vendors.edit') || $u->can('vendors.create'));
    }

    private function guardView(): void
    {
        abort_unless($this->mayView(), 403);
    }

    private function guardEdit(): void
    {
        abort_unless($this->mayEdit(), 403);
    }

    public function show(Supplier $supplier)
    {
        $this->guardView();
        $u = auth()->user();
        $canBank = $u->can('vendors.bank.view') || $u->can('vendors.bank.manage') || $u->can('vendors.bank.approve');

        $supplier->load(['contacts', 'addresses', 'itemCategories', 'businessUnits.businessUnit', 'items.stockItem']);

        return view('vendors.show', [
            'supplier' => $supplier,
            'canEdit' => $this->mayEdit(),
            'canApprove' => $u->can('vendors.approve'),
            'canBlock' => $u->can('vendors.block'),
            'canBank' => $canBank,
            'canBankManage' => $u->can('vendors.bank.manage'),
            'canBankApprove' => $u->can('vendors.bank.approve'),
            'bankAccounts' => $canBank ? $supplier->bankAccounts : collect(),
            'bankRequests' => $canBank ? $supplier->bankChangeRequests()->with(['requester', 'reviewer', 'account'])->limit(30)->get() : collect(),
            'pendingMods' => $this->master->pendingModifications($supplier),
            'audit' => $supplier->auditLogs()->with(['changedBy', 'approvedBy'])
                ->when(! $canBank, fn ($q) => $q->where('entity', '!=', 'bank_account'))->limit(100)->get(),
            'businessUnits' => BusinessUnit::where('is_active', true)->orderBy('name')->get(),
            'terms' => $this->master->termsFor($supplier),
        ]);
    }

    public function update(Request $request, Supplier $supplier)
    {
        $this->guardEdit();
        $held = $this->master->update($supplier, app(SupplierController::class)->validatedData($request, $supplier));

        return back()->with('success', 'Vendor saved.' . ($held ? ' Changes to ' . implode(', ', array_map(fn ($f) => str_replace('_', ' ', $f), $held)) . ' wait for approval.' : ''));
    }

    /** Workflow buttons: submit, review, approve, reject, block, inactivate, reactivate. */
    public function workflow(Request $request, Supplier $supplier, string $action)
    {
        $this->guardView();
        $remarks = trim((string) $request->input('remarks'));

        match ($action) {
            'submit' => $this->guardEdit() ?? $this->master->submit($supplier),
            'review' => $this->master->startReview($supplier),
            'approve' => $this->master->approve($supplier, $remarks ?: null),
            'reject' => $this->master->reject($supplier, $remarks),
            'block' => $this->master->block($supplier, $remarks),
            'inactivate' => $this->master->inactivate($supplier, $remarks),
            'reactivate' => $this->master->reactivate($supplier, $remarks),
            default => abort(404),
        };

        return back()->with('success', 'Vendor ' . str_replace('_', ' ', $action) . ' done.');
    }

    public function decideModification(Request $request, SupplierAuditLog $log)
    {
        $this->guardView();
        $this->master->decideModification($log, $request->input('decision') === 'approve', null, $request->input('remarks'));

        return back()->with('success', 'Change decided.');
    }

    /*
    |--------------------------------------------------------------------------
    | Contacts / addresses / item categories / items / business units
    |--------------------------------------------------------------------------
    */

    public function storeContact(Request $request, Supplier $supplier)
    {
        $this->guardEdit();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'designation' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:50'],
            'contact_type' => ['required', 'in:' . implode(',', array_keys(SupplierContact::TYPES))],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        $this->saveChild($supplier, SupplierContact::class, $data, 'contact', $data['name']);
        $this->syncLegacy($supplier);

        return back()->with('success', 'Contact added.');
    }

    public function destroyContact(Supplier $supplier, SupplierContact $contact)
    {
        $this->guardEdit();
        $this->removeChild($supplier, $contact, 'contact', $contact->name);
        $this->syncLegacy($supplier);

        return back()->with('success', 'Contact removed.');
    }

    public function primaryContact(Supplier $supplier, SupplierContact $contact)
    {
        $this->guardEdit();
        $this->makePrimary($supplier, $contact, 'contact', $contact->name);
        $this->syncLegacy($supplier);

        return back()->with('success', 'Primary contact changed.');
    }

    public function storeAddress(Request $request, Supplier $supplier)
    {
        $this->guardEdit();
        $data = $request->validate([
            'address_type' => ['required', 'in:' . implode(',', array_keys(SupplierAddress::TYPES))],
            'address' => ['required', 'string', 'max:2000'], 'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'], 'postal_code' => ['nullable', 'string', 'max:30'],
            'country' => ['nullable', 'string', 'max:100'], 'is_primary' => ['nullable', 'boolean'],
        ]);

        $this->saveChild($supplier, SupplierAddress::class, $data, 'address', $data['address_type'] . ': ' . mb_substr($data['address'], 0, 60));
        $this->syncLegacy($supplier);

        return back()->with('success', 'Address added.');
    }

    public function destroyAddress(Supplier $supplier, SupplierAddress $address)
    {
        $this->guardEdit();
        $this->removeChild($supplier, $address, 'address', mb_substr($address->address, 0, 60));
        $this->syncLegacy($supplier);

        return back()->with('success', 'Address removed.');
    }

    public function primaryAddress(Supplier $supplier, SupplierAddress $address)
    {
        $this->guardEdit();
        $this->makePrimary($supplier, $address, 'address', mb_substr($address->address, 0, 60));
        $this->syncLegacy($supplier);

        return back()->with('success', 'Primary address changed.');
    }

    public function storeCategory(Request $request, Supplier $supplier)
    {
        $this->guardEdit();
        $data = $request->validate([
            'category_name' => ['required', 'string', 'max:120'],
            'item_type' => ['required', 'in:' . implode(',', array_keys(SupplierItemCategory::TYPES))],
            'item_category_id' => ['nullable', 'integer', 'exists:item_categories,id'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:999'],
            'moq' => ['nullable', 'numeric', 'min:0'], 'tolerance_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        abort_if($supplier->itemCategories()->where('category_name', $data['category_name'])->where('item_type', $data['item_type'])->exists(), 422, 'This category is already listed for the vendor.');

        $this->saveChild($supplier, SupplierItemCategory::class, $data, 'item_category', $data['category_name']);
        $this->syncLegacy($supplier);

        return back()->with('success', 'Item category added.');
    }

    public function destroyCategory(Supplier $supplier, SupplierItemCategory $category)
    {
        $this->guardEdit();
        $this->removeChild($supplier, $category, 'item_category', $category->category_name);
        $this->syncLegacy($supplier);

        return back()->with('success', 'Item category removed.');
    }

    public function storeItem(Request $request, Supplier $supplier)
    {
        $this->guardEdit();
        $data = $request->validate([
            'stock_item_id' => ['nullable', 'integer', 'exists:stock_items,id'],
            'item_name' => ['nullable', 'string', 'max:255', 'required_without:stock_item_id'],
            'item_type' => ['required', 'in:material,accessory'],
            'supplier_item_code' => ['nullable', 'string', 'max:100'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:999'], 'moq' => ['nullable', 'numeric', 'min:0'],
            'is_preferred' => ['nullable', 'boolean'],
        ]);

        $this->saveChild($supplier, SupplierItem::class, $data, 'item', $data['item_name'] ?? ('stock item #' . $data['stock_item_id']));

        return back()->with('success', 'Vendor item added.');
    }

    public function destroyItem(Supplier $supplier, SupplierItem $item)
    {
        $this->guardEdit();
        $this->removeChild($supplier, $item, 'item', $item->item_name ?: ('stock item #' . $item->stock_item_id));

        return back()->with('success', 'Vendor item removed.');
    }

    public function storeBusinessUnit(Request $request, Supplier $supplier)
    {
        $this->guardEdit();
        $data = $request->validate([
            'business_unit_id' => ['required', 'integer', 'exists:business_units,id'],
            'effective_date' => ['nullable', 'date'], 'expiry_date' => ['nullable', 'date', 'after_or_equal:effective_date'],
        ]);

        SupplierBusinessUnit::updateOrCreate(
            ['supplier_id' => $supplier->id, 'business_unit_id' => $data['business_unit_id']],
            ['status' => 'active', 'effective_date' => $data['effective_date'] ?? null, 'expiry_date' => $data['expiry_date'] ?? null]
        );
        $this->master->audit($supplier, 'business_unit_set', ['entity' => 'business_unit', 'entity_id' => $data['business_unit_id'], 'new_value' => BusinessUnit::find($data['business_unit_id'])?->name]);

        return back()->with('success', 'Business unit applicability saved.');
    }

    public function destroyBusinessUnit(Supplier $supplier, SupplierBusinessUnit $mapping)
    {
        $this->guardEdit();
        abort_unless($mapping->supplier_id === $supplier->id, 404);
        $this->master->audit($supplier, 'business_unit_removed', ['entity' => 'business_unit', 'entity_id' => $mapping->business_unit_id, 'old_value' => $mapping->businessUnit?->name]);
        $mapping->delete();

        return back()->with('success', 'Business unit removed.');
    }

    /*
    |--------------------------------------------------------------------------
    | Bank accounts (request -> second person approves)
    |--------------------------------------------------------------------------
    */

    public function requestBank(Request $request, Supplier $supplier)
    {
        $data = $request->validate([
            'action' => ['required', 'in:create,update,deactivate'],
            'account_id' => ['nullable', 'integer'],
            'bank_name' => ['required_unless:action,deactivate', 'nullable', 'string', 'max:255'],
            'bank_branch' => ['nullable', 'string', 'max:255'],
            'account_name' => ['required_unless:action,deactivate', 'nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:100'],
            'routing_number' => ['nullable', 'string', 'max:100'],
            'swift_code' => ['nullable', 'string', 'max:50'], 'iban' => ['nullable', 'string', 'max:50'],
            'currency' => ['nullable', 'string', 'max:10'], 'is_primary' => ['nullable', 'boolean'],
        ]);

        $account = $data['account_id'] ?? null ? SupplierBankAccount::where('supplier_id', $supplier->id)->find($data['account_id']) : null;
        $this->master->requestBankChange($supplier, $data['action'], collect($data)->except(['action', 'account_id'])->all(), $account);

        return back()->with('success', 'Bank change sent for approval. It is not active until another approver accepts it.');
    }

    public function decideBank(Request $request, SupplierBankChangeRequest $bankRequest)
    {
        $this->master->decideBankChange($bankRequest, $request->input('decision') === 'approve', $request->input('remarks'));

        return back()->with('success', 'Bank change ' . ($request->input('decision') === 'approve' ? 'approved' : 'rejected') . '.');
    }

    public function cancelBank(SupplierBankChangeRequest $bankRequest)
    {
        $this->master->cancelBankChange($bankRequest);

        return back()->with('success', 'Bank change cancelled.');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /** Create a child row; the first one, or one marked primary, becomes the only primary. */
    private function saveChild(Supplier $supplier, string $model, array $data, string $entity, string $label): void
    {
        DB::transaction(function () use ($supplier, $model, $data, $entity, $label) {
            $hasPrimary = in_array('is_primary', (new $model)->getFillable(), true);
            $data['is_primary'] = (bool) ($data['is_primary'] ?? false);
            $data['is_preferred'] = (bool) ($data['is_preferred'] ?? false);
            if (! in_array('is_preferred', (new $model)->getFillable(), true)) {
                unset($data['is_preferred']);
            }
            if (! $hasPrimary) {
                unset($data['is_primary']);
            }
            $wantsPrimary = $hasPrimary && (! empty($data['is_primary']) || ! $model::where('supplier_id', $supplier->id)->where('is_primary', true)->exists());

            if ($wantsPrimary) {
                $model::where('supplier_id', $supplier->id)->update(['is_primary' => false]);
            }

            $row = $model::create(['supplier_id' => $supplier->id] + $data + ($hasPrimary ? ['is_primary' => false] : []));

            if ($wantsPrimary) {
                $row->update(['is_primary' => true]);
            }

            $this->master->audit($supplier, $entity . '_added', ['entity' => $entity, 'entity_id' => $row->id, 'new_value' => $label]);
        });
    }

    private function removeChild(Supplier $supplier, $row, string $entity, string $label): void
    {
        abort_unless($row->supplier_id === $supplier->id, 404);

        DB::transaction(function () use ($supplier, $row, $entity, $label) {
            $wasPrimary = $row->is_primary ?? false;
            $row->delete();

            if ($wasPrimary && ($next = $supplier->{$entity === 'contact' ? 'contacts' : 'addresses'}()->first())) {
                $next->update(['is_primary' => true]);
            }

            $this->master->audit($supplier, $entity . '_removed', ['entity' => $entity, 'entity_id' => $row->id, 'old_value' => $label]);
        });
    }

    private function makePrimary(Supplier $supplier, $row, string $entity, string $label): void
    {
        abort_unless($row->supplier_id === $supplier->id, 404);

        DB::transaction(function () use ($supplier, $row, $entity, $label) {
            $row::where('supplier_id', $supplier->id)->update(['is_primary' => false]);
            $row->update(['is_primary' => true]);
            $this->master->audit($supplier, $entity . '_primary', ['entity' => $entity, 'entity_id' => $row->id, 'new_value' => $label]);
        });
    }

    private function syncLegacy(Supplier $supplier): void
    {
        $this->master->syncLegacyColumns($supplier);
    }
}
