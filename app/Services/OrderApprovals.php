<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\BuyerOrder;
use App\Models\MotherStyle;
use App\Models\OrderSizeQuantity;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The buyer order (customer PO) register of a style and its approval.
 *
 *   merchant submits -> an approver approves, or returns it with a reason
 *
 * Approvers are the same people who approve a costing: Admin, Management of the
 * buyer, and a merchant who is a department admin. The person who submitted does
 * not approve their own order unless nobody else could.
 *
 * WHAT IS APPROVED. A fingerprint over the PO's colours, order quantities, ship
 * dates and size quantities, taken at submission. If the workspace changes any
 * of it afterwards the PO shows "Changed after approval"; nothing is blocked.
 */
class OrderApprovals
{
    public const KEY_REQUIRED_FOR_PO = 'order_approval_required_for_po';

    /** Admin switch (off by default): no PO / booking for a customer PO until its order is approved. */
    public static function requiredForPo(): bool
    {
        return (bool) (int) \App\Models\AppSetting::get(self::KEY_REQUIRED_FOR_PO, 0);
    }

    /**
     * Why PO generation must stop for this worksheet row because its buyer order
     * is not approved, or null to let it go. A row that is not linked to a style
     * (an older file) is never blocked.
     */
    public function blockReason(\App\Models\ExcelRow $row): ?string
    {
        if (! self::requiredForPo()) {
            return null;
        }

        $line = \App\Models\OrderLine::whereHas('bomItems', fn ($q) => $q->where('excel_row_id', $row->id))->first();
        if (! $line || ! $line->mother_style_id || ! ($mother = MotherStyle::find($line->mother_style_id))) {
            return null;
        }

        $order = $this->rows($mother)->firstWhere('key', $this->norm($line->customer_po_no));
        if (! $order) {
            return null;
        }
        $label = 'PO ' . ($order['po'] !== '' ? $order['po'] : '(no PO number)') . ' of style ' . $mother->style_no;

        return match (true) {
            $order['status'] === BuyerOrder::APPROVED && $order['changed_after_approval'] => 'The order ' . $label . ' changed after it was approved. Ask Merchandising to send it for approval again before ordering.',
            $order['status'] === BuyerOrder::APPROVED => null,
            default => 'The order ' . $label . ' is not approved. Ask Merchandising to send it for approval before ordering.',
        };
    }

    /**
     * One row per customer PO of the style: figures read from the workspace
     * order lines, plus the saved register record (if any).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(MotherStyle $mother): Collection
    {
        $lines = $mother->orderLines()->orderBy('customer_po_no')->orderBy('gmts_color')->get()
            ->groupBy(fn ($l) => $this->norm($l->customer_po_no));
        $sizes = OrderSizeQuantity::where('mother_style_id', $mother->id)->get()->groupBy(fn ($q) => $this->norm($q->customer_po_no));
        $saved = $mother->buyerOrders()->with(['costing', 'submitter:id,name', 'decider:id,name'])->get()->keyBy(fn ($o) => $this->norm($o->customer_po_no));

        // A saved record whose PO has left the workspace is still listed.
        $keys = $lines->keys()->merge($saved->keys())->unique()->values();

        return $keys->map(function ($key) use ($lines, $sizes, $saved) {
            $poLines = $lines->get($key, collect());
            $order = $saved->get($key);
            $sizeTotal = (float) ($sizes->get($key) ?? collect())->sum('qty');
            $fingerprint = $this->fingerprint($poLines, $sizes->get($key) ?? collect());

            return [
                'key' => $key,
                'po' => (string) ($poLines->first()?->customer_po_no ?? $order?->customer_po_no ?? ''),
                'colors' => $poLines->pluck('gmts_color')->filter()->unique()->values()->all(),
                'order_qty' => $poLines->isEmpty() ? null : (float) $poLines->sum(fn ($l) => (float) $l->order_qty),
                'size_total' => $sizeTotal,
                'ship_date' => $poLines->pluck('ship_date')->filter()->min(),
                'order' => $order,
                'status' => $order?->approval_status ?? BuyerOrder::DRAFT,
                'changed_after_approval' => $order && $order->approval_status === BuyerOrder::APPROVED
                    && $order->approved_fingerprint !== null && $order->approved_fingerprint !== $fingerprint,
                'in_workspace' => $poLines->isNotEmpty(),
            ];
        })->values();
    }

    public function fingerprint(Collection $lines, Collection $sizes): string
    {
        $a = $lines->map(fn ($l) => mb_strtolower(trim((string) $l->gmts_color)) . ':' . (float) $l->order_qty . ':' . ($l->ship_date?->toDateString() ?? ''))->sort()->values()->all();
        $b = $sizes->map(fn ($q) => mb_strtolower(trim((string) $q->gmts_color)) . ':' . $q->size . ':' . (float) $q->qty)->sort()->values()->all();

        return md5(json_encode([$a, $b]));
    }

    /** Save the register fields; creates the record on first save. */
    public function save(MotherStyle $mother, string $po, array $fields, User $by): BuyerOrder
    {
        $order = BuyerOrder::firstOrNew(['mother_style_id' => $mother->id, 'customer_po_no' => $po]);
        $order->fill($fields)->fill(['updated_by' => $by->id])->save();

        return $order;
    }

    /** @throws \DomainException */
    public function submit(MotherStyle $mother, string $po, User $by): BuyerOrder
    {
        $row = $this->rows($mother)->firstWhere('key', $this->norm($po));
        if (! $row || ! $row['in_workspace']) {
            throw new \DomainException('This PO has no order lines yet. Import or enter the order first.');
        }
        $order = $row['order'] ?? BuyerOrder::firstOrNew(['mother_style_id' => $mother->id, 'customer_po_no' => $row['po']]);
        if ($order->approval_status === BuyerOrder::PENDING) {
            throw new \DomainException('This order is already waiting for approval.');
        }
        if (! $row['order_qty']) {
            throw new \DomainException('The order quantity is missing. Fill it in the workspace first.');
        }

        $lines = $mother->orderLines()->get()->filter(fn ($l) => $this->norm($l->customer_po_no) === $row['key']);
        $sizes = OrderSizeQuantity::where('mother_style_id', $mother->id)->get()->filter(fn ($q) => $this->norm($q->customer_po_no) === $row['key']);

        $order->fill([
            'approval_status' => BuyerOrder::PENDING, 'submitted_by' => $by->id, 'submitted_at' => now(),
            'approved_fingerprint' => $this->fingerprint($lines, $sizes), 'decision_comment' => null, 'decided_by' => null, 'decided_at' => null,
            'updated_by' => $by->id,
        ])->save();

        $order->setRelation('motherStyle', $mother);
        $this->approversFor($mother)->reject(fn (User $u) => $u->id === $by->id)->each(fn (User $u) => $this->notify(
            $u, $by, 'Order to approve', $by->name . ' submitted PO ' . ($row['po'] ?: '(no PO no.)') . ' of style ' . $mother->style_no . ' for approval.', $mother
        ));

        return $order;
    }

    /** @throws \DomainException */
    public function approve(BuyerOrder $order, User $by, ?string $comment): BuyerOrder
    {
        if (! $this->canDecide($by, $order)) {
            throw new \DomainException('You cannot approve this order.');
        }
        $order->update(['approval_status' => BuyerOrder::APPROVED, 'decided_by' => $by->id, 'decided_at' => now(), 'decision_comment' => $comment]);
        $this->tellSubmitter($order, $by, 'Order approved', $by->name . ' approved PO ' . ($order->customer_po_no ?: '(no PO no.)') . ' of style ' . $order->motherStyle->style_no . '.');

        return $order;
    }

    /** @throws \DomainException */
    public function returnOrder(BuyerOrder $order, User $by, string $comment): BuyerOrder
    {
        if (! $this->canDecide($by, $order)) {
            throw new \DomainException('You cannot return this order.');
        }
        $order->update(['approval_status' => BuyerOrder::RETURNED, 'approved_fingerprint' => null, 'decided_by' => $by->id, 'decided_at' => now(), 'decision_comment' => $comment]);
        $this->tellSubmitter($order, $by, 'Order returned', $by->name . ' returned PO ' . ($order->customer_po_no ?: '(no PO no.)') . ' of style ' . $order->motherStyle->style_no . ': ' . $comment);

        return $order;
    }

    public function approversFor(MotherStyle $mother): Collection
    {
        $buyerId = (int) $mother->buyer_id;

        return User::role(['admin', 'management', 'merchant'])->where('status', 1)->get()->filter(function (User $u) use ($buyerId) {
            if ($u->hasRole('admin')) {
                return true;
            }

            return ($u->hasRole('management') || ($u->hasRole('merchant') && $u->is_department_admin)) && $u->hasBuyerAccess($buyerId);
        })->values();
    }

    public function canDecide(User $user, BuyerOrder $order): bool
    {
        if ($order->approval_status !== BuyerOrder::PENDING) {
            return false;
        }
        $approvers = $this->approversFor($order->motherStyle);
        if (! $approvers->contains('id', $user->id)) {
            return false;
        }

        return $user->id !== (int) $order->submitted_by || $approvers->where('id', '!=', $user->id)->isEmpty();
    }

    private function tellSubmitter(BuyerOrder $order, User $by, string $title, string $message): void
    {
        $to = User::find($order->submitted_by);
        if ($to && $to->id !== $by->id) {
            $this->notify($to, $by, $title, $message, $order->motherStyle);
        }
    }

    private function notify(User $to, User $by, string $title, string $message, MotherStyle $mother): void
    {
        AppNotification::create([
            'user_id' => $to->id, 'actor_id' => $by->id, 'type' => 'order_approval', 'title' => $title, 'message' => $message,
            'url' => route('merchant.orders.show', $mother),
        ]);
    }

    private function norm(?string $po): string
    {
        return mb_strtolower(trim((string) $po));
    }
}
