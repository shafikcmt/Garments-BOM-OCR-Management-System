<?php

namespace App\Services;

use App\Models\GsComparativeStatement;
use App\Models\GsCsItem;
use App\Models\GsPurchaseOrder;
use App\Models\GsPurchaseOrderItem;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Models\StockPurchase;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * General Stock purchasing: makes the Internal PO (the "booking" with an
 * internal PO number) from an APPROVED Comparative Statement, and follows it to
 * the Store's receiving.
 *
 *   one approved CS --> one PO per chosen supplier --> Draft
 *   Draft --(Checked By)--> Checked --(Authorised Signatory)--> Issued --(Store receives)--> Partly Received --> Received
 *
 * Two approval points after the maker: Checked By, then Authorised Signatory.
 * Three different people: whoever made the PO cannot check or authorise it, and
 * whoever checked it cannot authorise it.
 *
 * Rate and quantity are copied from the approved CS and locked: a PO cannot
 * quietly differ from what was approved. Only the header and each line's
 * due-on / remark are edited, and only while the PO is a Draft.
 */
class PurchaseOrderService
{
    public function __construct(private readonly GsDocumentNumberGenerator $numbers)
    {
    }

    /**
     * @return Collection<int, GsPurchaseOrder> the draft POs created
     */
    public function createFromStatement(GsComparativeStatement $cs, User $user): Collection
    {
        if (! $cs->isApproved()) {
            throw ValidationException::withMessages(['cs' => 'A purchase order can only be made from an approved Comparative Statement.']);
        }

        $cs->load(['items.quotes', 'suppliers']);

        $taken = GsPurchaseOrderItem::query()
            ->whereIn('gs_cs_item_id', $cs->items->pluck('id'))
            ->whereHas('order', fn ($q) => $q->where('status', '<>', GsPurchaseOrder::STATUS_CANCELLED))
            ->pluck('gs_cs_item_id')
            ->all();

        $groups = $cs->items
            ->reject(fn (GsCsItem $i) => in_array($i->id, $taken, true) || ! $i->selectedQuote() || $i->selectedQuote()->unit_price === null)
            ->groupBy('selected_supplier_id');

        if ($groups->isEmpty()) {
            throw ValidationException::withMessages(['cs' => 'Every item of this Comparative Statement already has a purchase order.']);
        }

        $defaults = (array) config('purchasing.po_defaults');

        return DB::transaction(function () use ($cs, $user, $groups, $defaults) {
            $orders = collect();

            foreach ($groups as $supplierId => $items) {
                $supplier = $cs->suppliers->firstWhere('id', (int) $supplierId);

                $po = GsPurchaseOrder::create([
                    'po_no' => $poNo = $this->numbers->nextPo(now()),
                    // PO-26-27/T-080 carries its own reference: T-080.
                    'reference_no' => str_contains($poNo, '/') ? substr($poNo, strrpos($poNo, '/') + 1) : null,
                    'po_date' => now()->toDateString(),
                    'status' => GsPurchaseOrder::STATUS_DRAFT,
                    'gs_comparative_statement_id' => $cs->id,
                    'gs_cs_supplier_id' => $supplier?->id,
                    'general_stock_supplier_id' => $supplier?->general_stock_supplier_id,
                    'supplier_name' => $supplier?->name ?? '—',
                    'supplier_address' => $supplier?->address,
                    'supplier_phone' => $supplier?->phone,
                    'bin_number' => $defaults['bin_number'] ?? null,
                    'invoice_to' => $defaults['invoice_to'] ?? null,
                    'consignee' => $defaults['consignee'] ?? null,
                    'dispatched_through' => $defaults['dispatched_through'] ?? null,
                    'destination' => $defaults['destination'] ?? null,
                    'payment_terms' => filled($supplier?->terms) ? $supplier->terms : ($defaults['payment_terms'] ?? null),
                    'delivery_terms' => $defaults['delivery_terms'] ?? null,
                    'remarks' => $defaults['remarks'] ?? null,
                    'created_by' => $user->id,
                ]);

                $total = 0.0;

                foreach ($items->values() as $i => $item) {
                    /** @var GsCsItem $item */
                    $rate = (float) $item->selectedQuote()->unit_price;
                    $amount = round((float) $item->qty * $rate, 4);
                    $total += $amount;

                    $po->items()->create([
                        'gs_cs_item_id' => $item->id,
                        'purchase_requisition_item_id' => $item->purchase_requisition_item_id,
                        'stock_item_id' => $item->stock_item_id,
                        'item_name' => $item->item_name,
                        'uom' => $item->uom,
                        'qty' => $item->qty,
                        'rate' => $rate,
                        'amount' => $amount,
                        'due_on' => $defaults['due_on'] ?? null,
                        'remarks' => $item->remarks,
                        'sort_order' => $i,
                    ]);
                }

                $po->update(['total_amount' => round($total, 2)]);
                $orders->push($po);
            }

            return $orders;
        });
    }

    /**
     * Edit the header of a draft PO. The item lines come from the approved
     * Comparative Statement and are not editable, and the BIN is fixed.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(GsPurchaseOrder $po, array $data): GsPurchaseOrder
    {
        if (! $po->isDraft()) {
            throw ValidationException::withMessages(['po' => 'Only a draft purchase order can be edited.']);
        }

        DB::transaction(function () use ($po, $data) {
            $po->update(array_intersect_key($data, array_flip([
                'po_date', 'reference_no', 'invoice_to', 'consignee', 'dispatched_through',
                'destination', 'payment_terms', 'delivery_terms', 'remarks',
            ])));
        });

        return $po->fresh('items');
    }

    /** Draft -> Checked: the Checked By box (approval point 1 of 2). */
    public function check(GsPurchaseOrder $po, User $user): GsPurchaseOrder
    {
        if (! $po->isDraft()) {
            throw ValidationException::withMessages(['po' => 'Only a draft purchase order can be checked.']);
        }

        if ((int) $po->created_by === (int) $user->id) {
            throw ValidationException::withMessages(['po' => 'You made this purchase order, so someone else has to check it.']);
        }

        $po->forceFill([
            'status' => GsPurchaseOrder::STATUS_CHECKED,
            'checked_by' => $user->id,
            'checked_at' => now(),
        ])->save();

        return $po;
    }

    /** Checked -> Draft: the checker sends it back to its maker to be corrected. */
    public function sendBack(GsPurchaseOrder $po): GsPurchaseOrder
    {
        if ($po->status !== GsPurchaseOrder::STATUS_CHECKED) {
            throw ValidationException::withMessages(['po' => 'Only a checked purchase order can be sent back.']);
        }

        $po->forceFill(['status' => GsPurchaseOrder::STATUS_DRAFT, 'checked_by' => null, 'checked_at' => null])->save();

        return $po;
    }

    /** Checked -> Issued: the Authorised Signatory box (approval point 2 of 2). */
    public function authorise(GsPurchaseOrder $po, User $user): GsPurchaseOrder
    {
        if ($po->status !== GsPurchaseOrder::STATUS_CHECKED) {
            throw ValidationException::withMessages(['po' => 'A purchase order has to be checked before it is authorised.']);
        }

        if ((int) $po->created_by === (int) $user->id || (int) $po->checked_by === (int) $user->id) {
            throw ValidationException::withMessages(['po' => 'You made or checked this purchase order, so someone else has to authorise it.']);
        }

        $po->forceFill([
            'status' => GsPurchaseOrder::STATUS_ISSUED,
            'authorised_by' => $user->id,
            'authorised_at' => now(),
        ])->save();

        $this->syncRequisitions($po);

        return $po;
    }

    /** A PO nobody has received against can be cancelled; its quantity goes back to the queue only through a new CS. */
    public function cancel(GsPurchaseOrder $po, string $reason): GsPurchaseOrder
    {
        if ($po->status === GsPurchaseOrder::STATUS_CANCELLED) {
            return $po;
        }

        if ($po->receivings()->exists()) {
            throw ValidationException::withMessages(['po' => 'Store has already received goods against this purchase order.']);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Write why it is being cancelled.']);
        }

        $po->forceFill(['status' => GsPurchaseOrder::STATUS_CANCELLED, 'cancel_reason' => trim($reason)])->save();

        $this->syncRequisitions($po);

        return $po;
    }

    /** @return array<string, bool> what this user may do to this PO now */
    public function allowed(GsPurchaseOrder $po, ?User $user): array
    {
        if (! $user) {
            return [];
        }

        return array_filter([
            'edit' => $po->isDraft() && $user->can('store.purchase_po.create'),
            'check' => $po->isDraft() && $user->can('store.purchase_po.review') && (int) $po->created_by !== (int) $user->id,
            'send_back' => $po->status === GsPurchaseOrder::STATUS_CHECKED
                && ($user->can('store.purchase_po.review') || $user->can('store.purchase_po.approve')),
            'authorise' => $po->status === GsPurchaseOrder::STATUS_CHECKED && $user->can('store.purchase_po.approve')
                && (int) $po->created_by !== (int) $user->id && (int) $po->checked_by !== (int) $user->id,
            'cancel' => in_array($po->status, [GsPurchaseOrder::STATUS_DRAFT, GsPurchaseOrder::STATUS_CHECKED, GsPurchaseOrder::STATUS_ISSUED], true)
                && $user->can('store.purchase_po.create') && ! $po->receivings()->exists(),
        ]);
    }

    /**
     * Quantity received so far per PO line.
     *
     * @return array<int, float> gs_purchase_order_item_id => qty
     */
    public function receivedByLine(GsPurchaseOrder $po): array
    {
        return StockPurchase::query()
            ->where('gs_purchase_order_id', $po->id)
            ->whereNotNull('gs_purchase_order_item_id')
            ->selectRaw('gs_purchase_order_item_id, SUM(qty) as received')
            ->groupBy('gs_purchase_order_item_id')
            ->pluck('received', 'gs_purchase_order_item_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /** Move Issued / Partly Received / Received to match what Store has recorded. */
    public function syncReceiving(GsPurchaseOrder $po): void
    {
        if (! in_array($po->status, [GsPurchaseOrder::STATUS_ISSUED, GsPurchaseOrder::STATUS_PARTIAL, GsPurchaseOrder::STATUS_RECEIVED], true)) {
            return;
        }

        $received = $this->receivedByLine($po);
        $po->loadMissing('items');

        $all = $po->items->isNotEmpty() && $po->items->every(
            fn (GsPurchaseOrderItem $i) => ($received[$i->id] ?? 0.0) + 0.00005 >= (float) $i->qty
        );

        $status = $all
            ? GsPurchaseOrder::STATUS_RECEIVED
            : (array_sum($received) > 0 ? GsPurchaseOrder::STATUS_PARTIAL : GsPurchaseOrder::STATUS_ISSUED);

        if ($status !== $po->status) {
            $po->forceFill(['status' => $status])->save();
        }
    }

    /**
     * Mark a requisition "Purchase Action Taken" once every line to be bought
     * is on an issued PO, and back to Approved if a PO is cancelled. Uses the
     * status the requisition flow already defines; nothing else about the
     * requisition changes.
     */
    public function syncRequisitions(GsPurchaseOrder $po): void
    {
        $requisitionIds = PurchaseRequisitionItem::query()
            ->whereIn('id', $po->items()->whereNotNull('purchase_requisition_item_id')->pluck('purchase_requisition_item_id'))
            ->pluck('purchase_requisition_id')
            ->unique();

        foreach (PurchaseRequisition::query()->whereIn('id', $requisitionIds)
            ->whereIn('status', [PurchaseRequisition::STATUS_APPROVED, PurchaseRequisition::STATUS_PURCHASE_ACTION_TAKEN])->get() as $req) {
            $lines = $req->items()->where('to_be_procured_qty', '>', 0)->get(['id', 'to_be_procured_qty']);

            $ordered = GsPurchaseOrderItem::query()
                ->whereIn('purchase_requisition_item_id', $lines->pluck('id'))
                ->whereHas('order', fn ($q) => $q->whereNotIn('status', [GsPurchaseOrder::STATUS_DRAFT, GsPurchaseOrder::STATUS_CHECKED, GsPurchaseOrder::STATUS_CANCELLED]))
                ->selectRaw('purchase_requisition_item_id, SUM(qty) as ordered')
                ->groupBy('purchase_requisition_item_id')
                ->pluck('ordered', 'purchase_requisition_item_id');

            $covered = $lines->isNotEmpty() && $lines->every(
                fn ($l) => (float) ($ordered[$l->id] ?? 0) + 0.00005 >= (float) $l->to_be_procured_qty
            );

            if ($covered && $req->status === PurchaseRequisition::STATUS_APPROVED) {
                $req->forceFill(['status' => PurchaseRequisition::STATUS_PURCHASE_ACTION_TAKEN])->save();
            } elseif (! $covered && $req->status === PurchaseRequisition::STATUS_PURCHASE_ACTION_TAKEN) {
                $req->forceFill(['status' => PurchaseRequisition::STATUS_APPROVED])->save();
            }
        }
    }
}
