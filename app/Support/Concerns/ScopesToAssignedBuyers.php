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
