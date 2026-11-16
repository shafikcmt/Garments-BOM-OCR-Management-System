<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\GsComparativeStatement;
use App\Models\GsCsItem;
use App\Models\GsCsSupplier;
use App\Models\GsPurchaseOrder;
use App\Models\PurchaseRequisitionItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * General Stock purchasing: builds a Comparative Statement and moves it through
 * its sign-off boxes.
 *
 *   Draft -> (Prepared) Waiting for Check -> (Manager - SCM) Waiting for
 *   Approval -> (Approved By) Approved
 *
 * RULES
 *  - Only a Draft is edited; a sent-back sheet is a Draft again, with the note.
 *  - Nobody checks or approves a sheet they prepared (maker / checker). One
 *    person holding both the check and the approve permission clicks each.
 *  - A line can only ask for what the requisition still has outstanding.
 *  - Submitting needs the minimum number of suppliers and a priced supplier
 *    chosen for every line.
 *  - An approved CS with a live PO cannot be cancelled.
 */
class ComparativeStatementService
{
    public function __construct(
        private readonly GsDocumentNumberGenerator $numbers,
        private readonly PurchaseQueueService $queue,
    ) {
    }

    /**
     * Create or replace a draft from the form payload.
     *
     * @param  array<string, mixed>  $data  validated payload, see ComparativeStatementController::payloadRules()
     */
    public function save(?GsComparativeStatement $cs, User $user, array $data): GsComparativeStatement
    {
        if ($cs && ! $cs->isEditable()) {
            throw ValidationException::withMessages(['cs' => 'Only a draft Comparative Statement can be edited.']);
        }

        $this->assertQuantities($data['lines'] ?? [], $cs?->id);

        return DB::transaction(function () use ($cs, $user, $data) {
            if (! $cs) {
                $date = \Illuminate\Support\Carbon::parse($data['cs_date']);
                $cs = GsComparativeStatement::create([
                    'cs_no' => $this->numbers->nextCs($date),
                    'status' => GsComparativeStatement::STATUS_DRAFT,
                    'form_code' => config('purchasing.cs_form.code'),
                    'form_issue_date' => config('purchasing.cs_form.issue_date'),
                    'form_version_no' => config('purchasing.cs_form.version_no'),
                    'form_revision_no' => config('purchasing.cs_form.revision_no'),
                    'created_by' => $user->id,
                ] + $this->header($data));
            } else {
                $cs->update($this->header($data));
                // Children are rebuilt from the form. Safe: a draft has no PO.
                // Plain queries, not the ordered relations: some engines refuse ORDER BY on DELETE.
                GsCsItem::query()->where('gs_comparative_statement_id', $cs->id)->delete();
                GsCsSupplier::query()->where('gs_comparative_statement_id', $cs->id)->delete();
            }

            $supplierIds = [];

            foreach (array_values($data['suppliers'] ?? []) as $i => $s) {
                $key = (string) ($s['key'] ?? $i);
                $row = $cs->suppliers()->create([
                    'general_stock_supplier_id' => $s['general_stock_supplier_id'] ?? null,
                    'name' => trim((string) $s['name']),
                    'address' => $s['address'] ?? null,
                    'phone' => $s['phone'] ?? null,
                    'terms' => $s['terms'] ?? null,
                    'is_recommended' => (string) ($data['recommended_supplier'] ?? '') === $key,
                    'sort_order' => $i,
                ]);
                $supplierIds[$key] = $row->id;
            }

            foreach (array_values($data['lines'] ?? []) as $i => $line) {
                $source = ! empty($line['purchase_requisition_item_id'])
                    ? PurchaseRequisitionItem::with('stockItem')->find((int) $line['purchase_requisition_item_id'])
                    : null;

                $item = $cs->items()->create([
                    'purchase_requisition_item_id' => $source?->id,
                    'stock_item_id' => $source?->stock_item_id,
                    'item_name' => $source ? $source->displayName() : trim((string) ($line['item_name'] ?? '')),
                    'category' => filled($line['category'] ?? null) ? trim((string) $line['category']) : null,
                    'uom' => $source ? ($source->uom_snapshot ?: $source->stockItem?->uom) : ($line['uom'] ?? null),
                    'qty' => $line['qty'],
                    'specification' => $line['specification'] ?? ($source?->specification),
                    'current_supplier' => $line['current_supplier'] ?? null,
                    'selected_supplier_id' => $supplierIds[(string) ($line['selected'] ?? '')] ?? null,
                    'remarks' => $line['remarks'] ?? null,
                    'sort_order' => $i,
                ]);

                foreach (($line['quotes'] ?? []) as $key => $quote) {
                    $price = $quote['price'] ?? null;
                    $spec = $quote['spec'] ?? null;

                    if (! isset($supplierIds[(string) $key]) || (($price === null || $price === '') && ($spec === null || $spec === ''))) {
                        continue;
                    }

                    $item->quotes()->create([
                        'gs_cs_supplier_id' => $supplierIds[(string) $key],
                        'specification' => $spec,
                        'unit_price' => ($price === null || $price === '') ? null : $price,
                    ]);
                }
            }

            return $cs->fresh(['items.quotes', 'suppliers']);
        });
    }

    /** Draft -> Waiting for Check (the Prepared box). */
    public function submit(GsComparativeStatement $cs, User $user): GsComparativeStatement
    {
        $this->assertStage($cs, [GsComparativeStatement::STATUS_DRAFT], 'submitted');
        $cs->loadMissing(['items.quotes', 'suppliers']);
        $this->assertComplete($cs);

        $cs->forceFill([
            'status' => GsComparativeStatement::STATUS_SUBMITTED,
            'prepared_by' => $user->id,
            'prepared_at' => now(),
            'return_note' => null,
            'returned_by' => null,
            'returned_at' => null,
        ])->save();

        $this->tellPermission($cs, 'store.purchase_cs.review', $user, 'Comparative Statement to check',
            $user->name.' sent '.$cs->cs_no.' for checking.');

        return $cs;
    }

    /** Waiting for Check -> Waiting for Approval (the Manager - SCM box). */
    public function check(GsComparativeStatement $cs, User $user): GsComparativeStatement
    {
        $this->assertStage($cs, [GsComparativeStatement::STATUS_SUBMITTED], 'checked');
        $this->assertNotPreparer($cs, $user, 'check');

        $cs->forceFill([
            'status' => GsComparativeStatement::STATUS_CHECKED,
            'checked_by' => $user->id,
            'checked_at' => now(),
        ])->save();

        $this->tellPermission($cs, 'store.purchase_cs.approve', $user, 'Comparative Statement to approve',
            $user->name.' checked '.$cs->cs_no.'. It is waiting for your approval.');
        $this->tellMaker($cs, $user, 'Comparative Statement checked', $user->name.' checked '.$cs->cs_no.' and sent it for approval.');

        return $cs;
    }

    /** Waiting for Approval -> Approved (the Approved By box). */
    public function approve(GsComparativeStatement $cs, User $user, ?string $note = null): GsComparativeStatement
    {
        $this->assertStage($cs, [GsComparativeStatement::STATUS_CHECKED], 'approved');
        $this->assertNotPreparer($cs, $user, 'approve');

        $cs->forceFill([
            'status' => GsComparativeStatement::STATUS_APPROVED,
            'approved_by' => $user->id,
            'approved_at' => now(),
            'approver_note' => filled($note) ? trim($note) : $cs->approver_note,
        ])->save();

        $this->tellMaker($cs, $user, 'Comparative Statement approved', $user->name.' approved '.$cs->cs_no.'. You can now make the purchase order.');

        return $cs;
    }

    /** Send back to the preparer, with a note saying what to fix. */
    public function sendBack(GsComparativeStatement $cs, User $user, string $note): GsComparativeStatement
    {
        $this->assertStage($cs, [GsComparativeStatement::STATUS_SUBMITTED, GsComparativeStatement::STATUS_CHECKED], 'returned');

        if (trim($note) === '') {
            throw ValidationException::withMessages(['note' => 'Write what needs to be corrected.']);
        }

        $this->tellMaker($cs, $user, 'Comparative Statement sent back', $user->name.' sent back '.$cs->cs_no.': '.trim($note));

        $cs->forceFill([
            'status' => GsComparativeStatement::STATUS_DRAFT,
            'return_note' => trim($note),
            'returned_by' => $user->id,
            'returned_at' => now(),
            'checked_by' => null,
            'checked_at' => null,
            'prepared_by' => null,
            'prepared_at' => null,
        ])->save();

        return $cs;
    }

    public function cancel(GsComparativeStatement $cs, User $user, string $reason): GsComparativeStatement
    {
        if ($cs->status === GsComparativeStatement::STATUS_CANCELLED) {
            return $cs;
        }

        if ($cs->purchaseOrders()->where('status', '<>', GsPurchaseOrder::STATUS_CANCELLED)->exists()) {
            throw ValidationException::withMessages(['cs' => 'This Comparative Statement has a purchase order. Cancel the PO first.']);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Write why it is being cancelled.']);
        }

        $cs->forceFill(['status' => GsComparativeStatement::STATUS_CANCELLED, 'cancel_reason' => trim($reason)])->save();

        return $cs;
    }

    /** What this user may do to this sheet right now. */
    public function allowed(GsComparativeStatement $cs, ?User $user): array
    {
        if (! $user) {
            return [];
        }

        $notPreparer = (int) $cs->prepared_by !== (int) $user->id;
        $isDraft = $cs->status === GsComparativeStatement::STATUS_DRAFT;

        return array_keys(array_filter([
            'edit' => $isDraft && $user->can('store.purchase_cs.create'),
            'submit' => $isDraft && $user->can('store.purchase_cs.create'),
            'check' => $cs->status === GsComparativeStatement::STATUS_SUBMITTED && $user->can('store.purchase_cs.review') && $notPreparer,
            'approve' => $cs->status === GsComparativeStatement::STATUS_CHECKED && $user->can('store.purchase_cs.approve') && $notPreparer,
            'send_back' => in_array($cs->status, [GsComparativeStatement::STATUS_SUBMITTED, GsComparativeStatement::STATUS_CHECKED], true)
                && ($user->can('store.purchase_cs.review') || $user->can('store.purchase_cs.approve')) && $notPreparer,
            'cancel' => $cs->status !== GsComparativeStatement::STATUS_CANCELLED && $user->can('store.purchase_cs.create'),
            'make_po' => $cs->isApproved() && $user->can('store.purchase_po.create'),
        ]));
    }

    /** @return array<string, mixed> */
    private function header(array $data): array
    {
        return [
            'cs_date' => $data['cs_date'],
            'recommendation' => $data['recommendation'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'signatories' => collect($data['signatories'] ?? [])
                ->filter(fn ($b) => filled($b['label'] ?? null))
                ->take(GsComparativeStatement::MAX_SIGNATORIES)
                ->map(fn ($b) => ['label' => trim($b['label']), 'role' => $b['role'] ?? 'blank'])
                ->values()->all() ?: null,
        ];
    }

    /**
     * A line asks for no more than the requisition still has outstanding,
     * leaving out what THIS sheet already holds (it is being replaced).
     */
    private function assertQuantities(array $lines, ?int $statementId): void
    {
        $ids = collect($lines)->pluck('purchase_requisition_item_id')->filter()->map(fn ($v) => (int) $v)->unique()->all();

        if ($ids === []) {
            return;
        }

        $covered = $this->queue->coveredQty($ids, $statementId);
        $asked = [];
        $errors = [];

        foreach ($lines as $key => $line) {
            $id = (int) ($line['purchase_requisition_item_id'] ?? 0);

            if (! $id) {
                continue;
            }

            $asked[$id] = ($asked[$id] ?? 0.0) + (float) $line['qty'];
            $source = PurchaseRequisitionItem::query()->find($id);
            $available = $source ? (float) $source->to_be_procured_qty - ($covered[$id] ?? 0.0) : 0.0;

            if (! $source || $asked[$id] > $available + 0.00005) {
                $errors["lines.$key.qty"] = 'Only '.rtrim(rtrim(number_format(max(0, $available), 4, '.', ''), '0'), '.').' of this item is left to purchase.';
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertComplete(GsComparativeStatement $cs): void
    {
        $min = max(1, (int) config('purchasing.min_suppliers_to_submit', 2));

        if ($cs->items->isEmpty()) {
            throw ValidationException::withMessages(['cs' => 'Add at least one item.']);
        }

        if ($cs->suppliers->count() < $min) {
            throw ValidationException::withMessages(['cs' => "A comparative statement needs at least {$min} suppliers."]);
        }

        foreach ($cs->items as $item) {
            /** @var GsCsItem $item */
            $quote = $item->selectedQuote();

            if (! $quote || $quote->unit_price === null) {
                throw ValidationException::withMessages([
                    'cs' => '"'.$item->item_name.'" has no supplier chosen with a price. Choose one for every item.',
                ]);
            }
        }
    }

    private function assertStage(GsComparativeStatement $cs, array $from, string $verb): void
    {
        if (! in_array($cs->status, $from, true)) {
            throw ValidationException::withMessages(['cs' => 'This Comparative Statement cannot be '.$verb.' from "'.$cs->statusLabel().'".']);
        }
    }

    private function assertNotPreparer(GsComparativeStatement $cs, User $user, string $verb): void
    {
        if ((int) $cs->prepared_by === (int) $user->id) {
            throw ValidationException::withMessages(['cs' => 'You prepared this Comparative Statement, so someone else has to '.$verb.' it.']);
        }
    }

    /** Alert everyone who holds a permission, except the person who just acted and the maker. */
    private function tellPermission(GsComparativeStatement $cs, string $permission, User $by, string $title, string $message): void
    {
        User::permission($permission)->where('status', 1)->get()
            ->reject(fn (User $u) => $u->id === $by->id || (int) $u->id === (int) $cs->prepared_by)
            ->each(fn (User $u) => $this->notify($u, $by, $title, $message, $cs));
    }

    /** Tell the person who prepared the sheet (or created it, if it has no preparer yet). */
    private function tellMaker(GsComparativeStatement $cs, User $by, string $title, string $message): void
    {
        $maker = User::find($cs->prepared_by ?: $cs->created_by);

        if ($maker && $maker->id !== $by->id) {
            $this->notify($maker, $by, $title, $message, $cs);
        }
    }

    private function notify(User $to, User $by, string $title, string $message, GsComparativeStatement $cs): void
    {
        AppNotification::create([
            'user_id' => $to->id, 'actor_id' => $by->id, 'type' => 'purchase_cs', 'title' => $title, 'message' => $message,
            'url' => route('store.purchasing.cs.show', $cs),
        ]);
    }
}
