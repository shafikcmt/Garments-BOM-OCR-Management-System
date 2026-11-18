<?php

namespace App\Services;

use App\Models\Supplier;
use App\Models\SupplierAuditLog;
use App\Models\SupplierBankAccount;
use App\Models\SupplierBankChangeRequest;
use App\Models\SupplierItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Vendor Master business rules in one place: workflow, audit trail, controlled
 * modification, bank-account change approval and the terms a transaction inherits.
 *
 * Controllers stay thin and only call these methods, so the rules hold no matter
 * which screen (Admin, Merchant, Supply Chain) or import triggers the change.
 *
 * Bank numbers never reach the audit log or a message: only the masked summary does.
 */
class VendorMaster
{
    /** Changing these on an APPROVED vendor needs an approver; they are held as a pending change. */
    public const CONTROLLED_FIELDS = [
        'supplier_name', 'legal_name', 'supplier_code', 'vendor_type', 'tin', 'vat_bin_number',
        'business_reg_number', 'default_currency', 'default_payment_terms', 'external_erp_code',
    ];

    /** Fields a vendor needs filled before it may be submitted. */
    private const REQUIRED_TO_SUBMIT = ['supplier_name', 'vendor_type', 'country'];

    /*
    |--------------------------------------------------------------------------
    | Audit
    |--------------------------------------------------------------------------
    */

    public function audit(Supplier $supplier, string $action, array $extra = []): SupplierAuditLog
    {
        return SupplierAuditLog::create($extra + [
            'supplier_id' => $supplier->id,
            'entity' => 'vendor',
            'action' => $action,
            'approval_status' => $supplier->approval_status,
            'changed_by' => auth()->id(),
        ]);
    }

    /** Value as text for the log; dates and decimals normalised so "3" vs "3.00" is not a change. */
    private function text(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (string) (float) $value : (string) $value;
    }

    /*
    |--------------------------------------------------------------------------
    | Create / edit the vendor profile
    |--------------------------------------------------------------------------
    */

    /** A new vendor always starts as Draft and inactive: it cannot be used until approved. */
    public function create(array $data, ?User $by = null): Supplier
    {
        return DB::transaction(function () use ($data, $by) {
            $supplier = Supplier::create($data + [
                'approval_status' => 'draft',
                'vendor_status' => 'inactive',
                'created_by' => $by?->id ?? auth()->id(),
                'updated_by' => $by?->id ?? auth()->id(),
            ]);

            $this->audit($supplier, 'created', ['remarks' => 'Vendor created as draft.']);

            return $supplier;
        });
    }

    /**
     * Save profile edits and write one audit row per changed field.
     *
     * Returns the list of changes that were held for approval (empty for most edits).
     * Held = a controlled field on an approved vendor edited by someone who cannot approve.
     *
     * @return list<string> labels of the fields held for approval
     */
    public function update(Supplier $supplier, array $data, ?User $by = null): array
    {
        $by ??= auth()->user();
        $canApprove = $by?->can('vendors.approve') ?? false;
        $held = [];

        DB::transaction(function () use ($supplier, $data, $by, $canApprove, &$held) {
            $apply = [];

            foreach ($data as $field => $new) {
                $old = $supplier->getAttribute($field);

                if ($this->text($old) === $this->text($new)) {
                    continue;
                }

                $isAudited = in_array($field, Supplier::AUDITED, true);

                if ($supplier->approval_status === 'approved' && ! $canApprove && in_array($field, self::CONTROLLED_FIELDS, true)) {
                    $this->audit($supplier, 'modification_requested', [
                        'field' => $field, 'old_value' => $this->text($old), 'new_value' => $this->text($new),
                        'approval_status' => 'pending', 'changed_by' => $by?->id,
                    ]);
                    $held[] = $field;

                    continue;
                }

                $apply[$field] = $new;

                if ($isAudited) {
                    $this->audit($supplier, 'updated', [
                        'field' => $field, 'old_value' => $this->text($old), 'new_value' => $this->text($new),
                        'changed_by' => $by?->id,
                    ]);
                }
            }

            if ($apply !== []) {
                // Editing a rejected vendor sends it back to draft so it can be resubmitted.
                if ($supplier->approval_status === 'rejected') {
                    $apply['approval_status'] = 'draft';
                }

                $supplier->update($apply + ['updated_by' => $by?->id]);
            }
        });

        return $held;
    }

    /** Pending modification rows for a vendor (controlled-field changes waiting for an approver). */
    public function pendingModifications(Supplier $supplier)
    {
        return $supplier->auditLogs()->where('action', 'modification_requested')->where('approval_status', 'pending')->get();
    }

    public function decideModification(SupplierAuditLog $log, bool $approve, ?User $by = null, ?string $remarks = null): void
    {
        $by ??= auth()->user();
        $this->requirePermission($by, 'vendors.approve');

        if ($log->action !== 'modification_requested' || $log->approval_status !== 'pending') {
            throw ValidationException::withMessages(['modification' => 'This change has already been decided.']);
        }

        DB::transaction(function () use ($log, $approve, $by, $remarks) {
            $supplier = $log->supplier;

            if ($approve) {
                // Code uniqueness is re-checked at the moment of approval.
                if ($log->field === 'supplier_code' && $log->new_value !== null
                    && Supplier::where('supplier_code', $log->new_value)->where('id', '!=', $supplier->id)->exists()) {
                    throw ValidationException::withMessages(['modification' => 'That vendor code is now used by another vendor.']);
                }

                $supplier->update([$log->field => $log->new_value, 'updated_by' => $by->id]);
            }

            $log->update([
                'approval_status' => $approve ? 'approved' : 'rejected',
                'approved_by' => $by->id, 'approved_at' => now(),
                'remarks' => $remarks ?: $log->remarks,
            ]);
        });
    }

    /**
     * Save parsed import entries (see VendorWorkbookImport::parse) in ONE transaction.
     * Vendors arrive as drafts; bank rows become pending change requests, never live accounts.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array{vendors: int, contacts: int, addresses: int, categories: int, bank_requests: int}
     */
    public function import(array $entries, ?User $by = null): array
    {
        $by ??= auth()->user();
        $n = ['vendors' => 0, 'contacts' => 0, 'addresses' => 0, 'categories' => 0, 'bank_requests' => 0];

        DB::transaction(function () use ($entries, $by, &$n) {
            foreach ($entries as $entry) {
                $vendor = $this->create(array_filter($entry['vendor'], fn ($v) => $v !== null), $by);
                $this->audit($vendor, 'imported', ['remarks' => 'Created by Excel import.']);
                $n['vendors']++;

                foreach ($entry['contacts'] as $i => $row) {
                    $vendor->contacts()->create($row + ['is_primary' => false]);
                    $n['contacts']++;
                }
                foreach ($entry['addresses'] as $row) {
                    $vendor->addresses()->create($row);
                    $n['addresses']++;
                }
                foreach ($entry['categories'] as $row) {
                    $vendor->itemCategories()->firstOrCreate(['category_name' => $row['category_name'], 'item_type' => $row['item_type']], $row);
                    $n['categories']++;
                }

                // Exactly one primary contact / address, whatever the file said.
                foreach (['contacts', 'addresses'] as $rel) {
                    $rows = $vendor->{$rel}()->get();
                    $primary = $rows->firstWhere('is_primary', true) ?? $rows->first();
                    $rows->each(fn ($r) => $r->update(['is_primary' => $primary && $r->id === $primary->id]));
                }

                foreach ($entry['banks'] as $bank) {
                    $this->requestBankChange($vendor, 'create', $bank, null, $by);
                    $n['bank_requests']++;
                }

                $this->syncLegacyColumns($vendor);
            }
        });

        return $n;
    }

    /** Keep the old single-value columns equal to the primary contact / address / first category. */
    public function syncLegacyColumns(Supplier $supplier): void
    {
        $supplier->refresh();
        $c = $supplier->contacts()->first();
        $a = $supplier->addresses()->first();

        $supplier->forceFill([
            'contact_person' => $c?->name, 'email' => $c?->email, 'phone' => $c?->phone,
            'address' => $a?->address, 'city' => $a?->city,
        ])->saveQuietly();
    }

    /*
    |--------------------------------------------------------------------------
    | Workflow: Draft -> Submitted -> Under Review -> Approved / Rejected -> Active
    |--------------------------------------------------------------------------
    */

    public function submit(Supplier $supplier): Supplier
    {
        $this->requireState($supplier, ['draft', 'rejected'], 'submitted for approval');

        $missing = collect(self::REQUIRED_TO_SUBMIT)->filter(fn ($f) => blank($supplier->{$f}))
            ->map(fn ($f) => str_replace('_', ' ', $f))->all();

        if ($missing) {
            throw ValidationException::withMessages(['workflow' => 'Please fill in before submitting: ' . implode(', ', $missing) . '.']);
        }

        return $this->transition($supplier, 'submitted', ['submitted_at' => now()], 'submitted');
    }

    public function startReview(Supplier $supplier, ?User $by = null): Supplier
    {
        $this->requirePermission($by ?? auth()->user(), 'vendors.approve');
        $this->requireState($supplier, ['submitted'], 'taken under review');

        return $this->transition($supplier, 'under_review', [], 'review_started');
    }

    public function approve(Supplier $supplier, ?string $remarks = null, ?User $by = null): Supplier
    {
        $by ??= auth()->user();
        $this->requirePermission($by, 'vendors.approve');
        $this->requireState($supplier, ['submitted', 'under_review'], 'approved');

        return DB::transaction(function () use ($supplier, $remarks, $by) {
            $attrs = ['approved_by' => $by->id, 'approved_at' => now(), 'status_reason' => null];

            // Approval is what activates a vendor (unless it is blocked).
            if ($supplier->vendor_status !== 'blocked') {
                $attrs['vendor_status'] = 'active';
            }

            if (blank($supplier->supplier_code)) {
                $attrs['supplier_code'] = $this->nextCode();
            }

            return $this->transition($supplier, 'approved', $attrs, 'approved', $remarks);
        });
    }

    public function reject(Supplier $supplier, string $remarks, ?User $by = null): Supplier
    {
        $this->requirePermission($by ?? auth()->user(), 'vendors.approve');
        $this->requireState($supplier, ['submitted', 'under_review'], 'rejected');
        $this->requireRemarks($remarks, 'A reason is required to reject a vendor.');

        return $this->transition($supplier, 'rejected', ['status_reason' => $remarks], 'rejected', $remarks);
    }

    public function block(Supplier $supplier, string $reason, ?User $by = null): Supplier
    {
        $this->requirePermission($by ?? auth()->user(), 'vendors.block');
        $this->requireRemarks($reason, 'A reason is required to block a vendor.');

        return $this->changeStatus($supplier, 'blocked', $reason, 'blocked');
    }

    public function inactivate(Supplier $supplier, string $reason, ?User $by = null): Supplier
    {
        $this->requirePermission($by ?? auth()->user(), 'vendors.block');
        $this->requireRemarks($reason, 'A reason is required to inactivate a vendor.');

        return $this->changeStatus($supplier, 'inactive', $reason, 'inactivated');
    }

    public function reactivate(Supplier $supplier, string $reason, ?User $by = null): Supplier
    {
        $this->requirePermission($by ?? auth()->user(), 'vendors.block');
        $this->requireRemarks($reason, 'A reason is required to reactivate a vendor.');

        if ($supplier->approval_status !== 'approved') {
            throw ValidationException::withMessages(['workflow' => 'Only an approved vendor can be reactivated. Submit it for approval first.']);
        }

        if ($supplier->isExpired()) {
            throw ValidationException::withMessages(['workflow' => 'This vendor has expired. Change the expiry date first.']);
        }

        return $this->changeStatus($supplier, 'active', $reason, 'reactivated');
    }

    /** Scheduled daily: vendors past their expiry date become inactive. Returns how many. */
    public function expireOverdue(): int
    {
        $count = 0;

        Supplier::query()->where('vendor_status', 'active')->whereNotNull('expiry_date')
            ->where('expiry_date', '<', now()->toDateString())->each(function (Supplier $s) use (&$count) {
                $s->update(['vendor_status' => 'inactive', 'status_reason' => 'Expired on ' . $s->expiry_date->format('d M Y')]);
                $this->audit($s, 'expired', ['field' => 'vendor_status', 'old_value' => 'active', 'new_value' => 'inactive', 'changed_by' => null, 'remarks' => 'Expiry date passed.']);
                $count++;
            });

        return $count;
    }

    private function changeStatus(Supplier $supplier, string $to, string $reason, string $action): Supplier
    {
        if ($supplier->vendor_status === $to) {
            throw ValidationException::withMessages(['workflow' => 'The vendor is already ' . $to . '.']);
        }

        $from = $supplier->vendor_status;
        $supplier->update(['vendor_status' => $to, 'status_reason' => $reason, 'updated_by' => auth()->id()]);
        $this->audit($supplier, $action, ['field' => 'vendor_status', 'old_value' => $from, 'new_value' => $to, 'remarks' => $reason]);

        return $supplier->refresh();
    }

    private function transition(Supplier $supplier, string $to, array $attrs, string $action, ?string $remarks = null): Supplier
    {
        $from = $supplier->approval_status;
        $supplier->update($attrs + ['approval_status' => $to, 'updated_by' => auth()->id()]);
        $this->audit($supplier, $action, [
            'field' => 'approval_status', 'old_value' => $from, 'new_value' => $to, 'remarks' => $remarks,
            'approved_by' => in_array($to, ['approved', 'rejected'], true) ? auth()->id() : null,
            'approved_at' => in_array($to, ['approved', 'rejected'], true) ? now() : null,
        ]);

        return $supplier->refresh();
    }

    private function nextCode(): string
    {
        $n = (int) (Supplier::withTrashed()->max('id') ?? 0) + 1;

        do {
            $code = 'VND-' . str_pad((string) $n++, 5, '0', STR_PAD_LEFT);
        } while (Supplier::withTrashed()->where('supplier_code', $code)->exists());

        return $code;
    }

    private function requireState(Supplier $supplier, array $allowed, string $verb): void
    {
        if (! in_array($supplier->approval_status, $allowed, true)) {
            throw ValidationException::withMessages(['workflow' => 'This vendor is ' . (Supplier::APPROVALS[$supplier->approval_status] ?? $supplier->approval_status) . ' and cannot be ' . $verb . '.']);
        }
    }

    private function requireRemarks(?string $text, string $message): void
    {
        if (trim((string) $text) === '') {
            throw ValidationException::withMessages(['remarks' => $message]);
        }
    }

    private function requirePermission(?User $user, string $permission): void
    {
        abort_unless($user && $user->can($permission), 403, 'You do not have permission for this vendor action.');
    }

    /*
    |--------------------------------------------------------------------------
    | Bank accounts: every change is a request a second person approves
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $data  bank_name, bank_branch, account_name, account_number, routing_number, swift_code, iban, currency, is_primary
     */
    public function requestBankChange(Supplier $supplier, string $action, array $data, ?SupplierBankAccount $account = null, ?User $by = null): SupplierBankChangeRequest
    {
        $by ??= auth()->user();
        $this->requirePermission($by, 'vendors.bank.manage');

        if (! in_array($action, ['create', 'update', 'deactivate'], true)) {
            throw ValidationException::withMessages(['bank' => 'Unknown bank change.']);
        }

        if ($action !== 'create' && (! $account || $account->supplier_id !== $supplier->id)) {
            throw ValidationException::withMessages(['bank' => 'Bank account not found for this vendor.']);
        }

        $number = $action === 'deactivate' ? null : preg_replace('/\s+/', '', (string) ($data['account_number'] ?? ''));

        if ($action === 'create' && $number === '') {
            throw ValidationException::withMessages(['account_number' => 'Account number is required.']);
        }

        if ($number && SupplierBankAccount::where('account_number_hash', SupplierBankAccount::hashNumber($number))
            ->where('status', 'active')->where('id', '!=', $account?->id ?? 0)->exists()) {
            throw ValidationException::withMessages(['account_number' => 'This account number is already on a vendor bank account.']);
        }

        $payload = $action === 'deactivate' ? [] : $data;
        if (array_key_exists('account_number', $payload)) {
            // Blank on an update means "keep the current number".
            $payload['account_number'] = $number ?: null;
        }

        $summary = trim(($data['bank_name'] ?? $account?->bank_name ?? '') . ' / '
            . ($data['account_name'] ?? $account?->account_name ?? '') . ' / '
            . ($number ? '****' . substr($number, -4) : ($account?->maskedNumber() ?? '')), ' /');

        return DB::transaction(function () use ($supplier, $action, $payload, $summary, $account, $by) {
            $request = SupplierBankChangeRequest::create([
                'supplier_id' => $supplier->id, 'supplier_bank_account_id' => $account?->id, 'action' => $action,
                'payload' => $payload, 'summary' => $summary, 'status' => 'pending', 'requested_by' => $by->id,
            ]);

            $this->audit($supplier, 'bank_change_requested', [
                'entity' => 'bank_account', 'entity_id' => $account?->id, 'field' => $action,
                'new_value' => $summary, 'approval_status' => 'pending', 'changed_by' => $by->id,
            ]);

            return $request;
        });
    }

    public function decideBankChange(SupplierBankChangeRequest $request, bool $approve, ?string $remarks = null, ?User $by = null): void
    {
        $by ??= auth()->user();
        $this->requirePermission($by, 'vendors.bank.approve');

        if ($request->status !== 'pending') {
            throw ValidationException::withMessages(['bank' => 'This request has already been decided.']);
        }

        // Maker-checker: the person who asked can never approve their own request.
        if ($approve && $request->requested_by === $by->id) {
            throw ValidationException::withMessages(['bank' => 'You cannot approve your own bank change. Another approver must do it.']);
        }

        if (! $approve) {
            $this->requireRemarks($remarks, 'A reason is required to reject a bank change.');
        }

        DB::transaction(function () use ($request, $approve, $remarks, $by) {
            $accountId = $request->supplier_bank_account_id;

            if ($approve) {
                $accountId = $this->applyBankChange($request, $by)?->id ?? $accountId;
            }

            $request->update([
                'status' => $approve ? 'approved' : 'rejected',
                'reviewed_by' => $by->id, 'reviewed_at' => now(), 'review_remarks' => $remarks,
                'supplier_bank_account_id' => $accountId,
            ]);

            $this->audit($request->supplier, $approve ? 'bank_change_approved' : 'bank_change_rejected', [
                'entity' => 'bank_account', 'entity_id' => $accountId, 'field' => $request->action,
                'new_value' => $request->summary, 'approval_status' => $approve ? 'approved' : 'rejected',
                'changed_by' => $request->requested_by, 'approved_by' => $by->id, 'approved_at' => now(), 'remarks' => $remarks,
            ]);
        });
    }

    public function cancelBankChange(SupplierBankChangeRequest $request, ?User $by = null): void
    {
        $by ??= auth()->user();

        if ($request->status !== 'pending' || $request->requested_by !== $by?->id) {
            throw ValidationException::withMessages(['bank' => 'Only the requester can cancel a pending request.']);
        }

        $request->update(['status' => 'cancelled']);
        $this->audit($request->supplier, 'bank_change_cancelled', ['entity' => 'bank_account', 'entity_id' => $request->supplier_bank_account_id, 'field' => $request->action, 'new_value' => $request->summary, 'approval_status' => 'cancelled']);
    }

    private function applyBankChange(SupplierBankChangeRequest $request, User $by): ?SupplierBankAccount
    {
        $p = $request->payload ?? [];
        $supplierId = $request->supplier_id;
        $approval = ['verification_status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now()];

        if ($request->action === 'deactivate') {
            $account = $request->account;
            $account?->update(['status' => 'inactive', 'is_primary' => false]);

            return $account;
        }

        $fields = [
            'bank_name' => $p['bank_name'] ?? null, 'bank_branch' => $p['bank_branch'] ?? null,
            'account_name' => $p['account_name'] ?? null, 'routing_number' => $p['routing_number'] ?? null,
            'swift_code' => $p['swift_code'] ?? null, 'iban' => $p['iban'] ?? null, 'currency' => $p['currency'] ?? null,
        ];

        $number = $p['account_number'] ?? null;
        if ($number) {
            $fields += ['account_number' => $number, 'account_number_last4' => substr($number, -4), 'account_number_hash' => SupplierBankAccount::hashNumber($number)];
        }

        if ($request->action === 'create') {
            $account = SupplierBankAccount::create($fields + $approval + ['supplier_id' => $supplierId, 'status' => 'active']);
        } else {
            $account = $request->account;
            $account->update(array_filter($fields, fn ($v) => $v !== null) + $approval);
        }

        $first = ! SupplierBankAccount::where('supplier_id', $supplierId)->where('status', 'active')->where('is_primary', true)->where('id', '!=', $account->id)->exists();

        if (! empty($p['is_primary']) || $first) {
            SupplierBankAccount::where('supplier_id', $supplierId)->where('id', '!=', $account->id)->update(['is_primary' => false]);
            $account->update(['is_primary' => true]);
        }

        return $account;
    }

    /*
    |--------------------------------------------------------------------------
    | What a transaction inherits from the vendor
    |--------------------------------------------------------------------------
    */

    /**
     * Terms for a PO line: item-level beats category-level beats vendor default.
     * The caller may still override incoterm / ship mode / tolerance on the transaction.
     *
     * @return array{lead_time_days: ?int, moq: ?float, tolerance_percent: ?float, incoterm: ?string, ship_mode: ?string, currency: ?string, payment_terms: ?string, source: string}
     */
    public function termsFor(Supplier $supplier, ?string $category = null, ?int $stockItemId = null): array
    {
        $item = $stockItemId ? SupplierItem::where('supplier_id', $supplier->id)->where('stock_item_id', $stockItemId)->where('status', 'active')->first() : null;
        $cat = $category ? $supplier->itemCategories()->where('status', 'active')->whereLike('category_name', $category)->first() : null;

        $pick = fn ($field) => $item?->{$field} ?? $cat?->{$field} ?? $supplier->{$field === 'tolerance_percent' ? 'tolerance_percent' : $field};

        return [
            'lead_time_days' => $pick('lead_time_days'),
            'moq' => ($v = $pick('moq')) !== null ? (float) $v : null,
            'tolerance_percent' => ($v = $cat?->tolerance_percent ?? $supplier->tolerance_percent) !== null ? (float) $v : null,
            'incoterm' => $supplier->incoterm,
            'ship_mode' => $supplier->ship_mode,
            'currency' => $supplier->default_currency,
            'payment_terms' => $supplier->default_payment_terms,
            'source' => $item ? 'item' : ($cat ? 'category' : 'vendor'),
        ];
    }

    /** Frozen copy of the vendor terms stored on a PO. Contains no bank data. */
    public function snapshot(Supplier $supplier, ?string $category = null): array
    {
        $contact = $supplier->contacts()->where('status', 'active')->first();

        return [
            'vendor_code' => $supplier->supplier_code,
            'vendor_name' => $supplier->supplier_name,
            'legal_name' => $supplier->legal_name,
            'vendor_type' => $supplier->vendor_type,
            'contact_name' => $contact?->name,
            'contact_email' => $contact?->email,
            'taken_at' => now()->toDateTimeString(),
        ] + $this->termsFor($supplier, $category);
    }
}
