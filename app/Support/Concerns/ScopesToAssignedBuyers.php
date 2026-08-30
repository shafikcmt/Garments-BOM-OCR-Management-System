<?php

namespace App\Support\Concerns;

use App\Models\User;

/**
 * The read-time half of buyer scoping, in one place.
 *
 * WHY A TRAIT AND NOT SIX COPIES. Buyer scoping used to exist exactly once, as
 * a protected method on DepartmentReportService, because exactly one screen
 * applied it. Switching a second department on means applying the same rule to
 * PO Generate, PRA, the department dashboard and the email history — and a
 * scope rule restated four times is four places for the next department to be
 * missed. Everything here reads the Phase 4 predicates on User; nothing
 * restates what "confined" means.
 *
 * THE RULE, stated once so the callers do not have to:
 *
 *   unconfined viewer   returns immediately. A super admin, Management, an
 *                       unscoped department and an 'all'-mode user all answer
 *                       false to isBuyerConfined() and are never narrowed.
 *   untagged record     stays VISIBLE. buyer_id null means no buyer can claim
 *                       the file, and hiding the whole pre-tagging archive from
 *                       a scoped user is an outage, not a scope rule. Every
 *                       live BOM file has a null buyer_id today, so dropping
 *                       this clause gives Supply Chain empty screens.
 *   no file at all      stays VISIBLE, for the same reason. booking_pos and
 *                       payment_request_items both hold a NULLABLE
 *                       excel_file_id, and a bare whereHas() would silently
 *                       delete those rows from the list.
 *
 * WHAT THIS IS NOT. Buyer scoping is data visibility, never permission. Role
 * checks stay exactly where they are; every method here runs after them.
 */
trait ScopesToAssignedBuyers
{
    /**
     * The user the scope is applied for.
     *
     * Overridable because DepartmentReportService is also driven from Admin and
     * Management screens, where the viewer is not always the logged-in user.
     * Controllers want the default.
     */
    protected function buyerScopeViewer(): ?User
    {
        return auth()->user();
    }

    /**
     * Narrow a query whose model reaches a buyer through an ExcelFile relation.
     *
     * Covers ExcelRow, BookingPo and PaymentRequestItem, which all carry
     * excel_file_id. Pass a nested path ('bookingPo.excelFile') where the model
     * is one hop further away.
     *
     * REUSES isBuyerConfined() / scopedBuyerIds() rather than restating them,
     * and deliberately does NOT use hasBuyerAccess(): that answers ALLOWED for a
     * null buyer, which is right for a lock but cannot be turned into SQL.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation  $query
     */
    protected function applyBuyerScope($query, string $relation = 'excelFile'): void
    {
        $user = $this->buyerScopeViewer();

        if (! $user?->isBuyerConfined()) {
            return;
        }

        $buyerIds = $user->scopedBuyerIds();

        $query->where(function ($outer) use ($relation, $buyerIds) {
            $outer->whereDoesntHave($relation)
                ->orWhereHas($relation, function ($file) use ($buyerIds) {
                    $this->constrainBuyerColumn($file, $buyerIds);
                });
        });
    }

    /**
     * Narrow a query running ON excel_files itself, where there is no relation
     * to hop through.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation  $query
     */
    protected function applyBuyerScopeToFiles($query): void
    {
        $user = $this->buyerScopeViewer();

        if (! $user?->isBuyerConfined()) {
            return;
        }

        $buyerIds = $user->scopedBuyerIds();

        $query->where(fn ($scope) => $this->constrainBuyerColumn($scope, $buyerIds));
    }

    /**
     * Narrow a PaymentRequest query to the PRAs this viewer may see.
     *
     * A PRA carries no buyer of its own — its ITEMS do, each holding the
     * excel_file_id of the BOM line its PO came from — so a PRA is in scope
     * when ANY of its items is. Any, not all: a PRA can combine POs, and
     * refusing the whole document because one line sits with another buyer
     * would hide work the user genuinely raised.
     *
     * A PRA with no items at all stays visible, for the same reason an untagged
     * file does: it belongs to no buyer, so no buyer's team can claim it.
     *
     * LIVES HERE, not on a controller. Supply Chain's PRA list, Supply Chain's
     * dashboard and now the Accounts dashboard all ask this same question, and
     * it was already written twice before Accounts made it three times. A rule
     * about who may see a PRA has to have one answer.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation  $query
     */
    protected function applyBuyerScopeToPaymentRequests($query): void
    {
        if (! $this->buyerScopeViewer()?->isBuyerConfined()) {
            return;
        }

        $query->where(function ($outer) {
            $outer->whereDoesntHave('items')
                ->orWhereHas('items', fn ($item) => $this->applyBuyerScope($item));
        });
    }

    /**
     * Narrow an EmailLog query to the logs this viewer may see.
     *
     * An email log is the audit trail of a PO or a PRA, so it inherits that
     * document's buyer. Both branches use the scope above, so the PO half and
     * the PRA half can never drift apart.
     *
     * A log attached to NEITHER — there is no such row today — is hidden from a
     * confined viewer rather than shown. That is the opposite of the untagged
     * rule on purpose: an untagged FILE is a real record that belongs to no
     * buyer, while a log belonging to no document is a row with no buyer to
     * trace, and guessing in the permissive direction there would be a leak.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation  $query
     */
    protected function applyBuyerScopeToEmailLogs($query): void
    {
        if (! $this->buyerScopeViewer()?->isBuyerConfined()) {
            return;
        }

        $query->where(function ($outer) {
            $outer->whereHas('bookingPo', fn ($po) => $this->applyBuyerScope($po))
                ->orWhereHas('paymentRequest.items', fn ($item) => $this->applyBuyerScope($item));
        });
    }

    /**
     * THE WRITE GUARD. Refuse outright rather than quietly returning nothing.
     *
     * A list that silently omits a record and an action that silently does
     * nothing look identical to a user and mean opposite things. Everything a
     * scoped user may not act on answers 403 here, so a hand-made POST naming
     * another buyer's PO is refused, not merely absent from a screen.
     *
     * Answers ALLOWED for a null buyer, matching the untagged rule above.
     */
    protected function assertBuyerAccess(?int $buyerId): void
    {
        abort_if(! $this->allowsBuyerAccess($buyerId), 403, 'This record belongs to a buyer outside your buyer access.');
    }

    /**
     * THE WRITE GUARD for any record that reaches its buyer through an
     * ExcelFile relation — a receiving, a requisition, a bulk issue, a BOM row.
     *
     * WHY THE RELATION AND NOT THE ROW'S OWN buyer_name. Every one of these
     * tables denormalises buyer_name off the BOM at write time, and that column
     * is free text a merchant can edit. A gate reading it is a gate anyone can
     * walk through by typing, so the guard always hops to the file's buyer_id.
     *
     * A record with no file, or a file with a null buyer_id, is ALLOWED — the
     * untagged rule this trait documents. Every live BOM file carries a null
     * buyer_id today, so a guard that refused them would lock Store out of its
     * own screens on the day scoping was switched on.
     *
     * @param  \Illuminate\Database\Eloquent\Model|null  $model
     */
    protected function assertBuyerAccessVia($model, string $relation = 'excelFile'): void
    {
        if (! $this->buyerScopeViewer()?->isBuyerConfined()) {
            return;
        }

        $model?->loadMissing($relation);

        $buyerId = $model?->getRelationValue($relation)?->buyer_id;

        $this->assertBuyerAccess($buyerId === null ? null : (int) $buyerId);
    }

    /** The same question without the abort, for callers that filter a set. */
    protected function allowsBuyerAccess(?int $buyerId): bool
    {
        $user = $this->buyerScopeViewer();

        return $user === null
            ? false
            : $user->hasBuyerAccess($buyerId);
    }

    /**
     * `buyer_id IS NULL OR buyer_id IN (...)` on an excel_files query.
     *
     * A user confined to nothing — mode 'specific' with no assignment, which
     * validation makes unreachable through the UI — arrives with an empty set
     * and sees untagged files only. Never every file: an empty set widening
     * access is the failure mode buyer_scope_mode exists to prevent.
     *
     * @param  list<int>  $buyerIds
     */
    private function constrainBuyerColumn($query, array $buyerIds)
    {
        $query->whereNull('buyer_id');

        if ($buyerIds !== []) {
            $query->orWhereIn('buyer_id', $buyerIds);
        }

        return $query;
    }
}
