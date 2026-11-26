<?php

namespace App\Support;

use App\Models\BookingPo;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Where a user may go to see a Booking PO in full — or nowhere.
 *
 * NAVIGATION ONLY, NOT A GATE. The screens this points at enforce their own
 * access (BookingController::authorizeBookingPo and its admin override), and
 * that is the real check. This class exists so a PRA page never shows a
 * button that would 403 the person clicking it: it answers the same question
 * those guards ask, and hides the link when the answer is no.
 *
 *   admin         the PO Generate Control copy of the screen, any buyer.
 *   supply_chain  the Booking Preview screen, if the PO's buyer is within
 *                 their buyer access — the same hasBuyerAccess() the screen
 *                 itself calls, so the two cannot disagree.
 *   anyone else   null. Merchant, Accounts and Commercial have no screen of
 *                 their own for a Booking PO; the PRA page shows them the
 *                 summary it already holds instead.
 */
class BookingPoLink
{
    public static function urlFor(?User $user, BookingPo $bookingPo, ?PaymentRequest $paymentRequest = null): ?string
    {
        if ($user === null) {
            return null;
        }

        if ($user->hasRole('admin')) {
            return route('admin.po-generate-control.show', $bookingPo);
        }

        if ($user->hasRole('supply_chain')) {
            $bookingPo->loadMissing('excelFile');
            $buyerId = $bookingPo->excelFile?->buyer_id;

            if ($user->hasBuyerAccess($buyerId === null ? null : (int) $buyerId)) {
                return route('supply_chain.bookings.show', $bookingPo);
            }
        }

        // Everyone else taking part in the PRA (Merchant, Accounts, Commercial,
        // a Supply Chain approver outside the PO's buyer) gets the read-only
        // PO document, but only through the PRA that pays for it.
        if ($paymentRequest !== null && \App\Support\PraViewAccess::mayView($paymentRequest, $user)) {
            return route('pra_departmental.po', [$paymentRequest, $bookingPo]);
        }

        return null;
    }

    /**
     * The distinct Booking POs a PRA asks payment for, each with the lines of
     * THIS PRA that belong to it and the URL (if any) the viewer may open.
     *
     * Keyed by the PO's own id. A line with no booking_po_id — every legacy
     * PRA, and any PRA raised before PO Generation stamped the id — is simply
     * not here, so those documents show nothing new.
     *
     * @return Collection<int, array{po: BookingPo, url: string|null, items: Collection}>
     */
    public static function forPaymentRequest(PaymentRequest $paymentRequest, ?User $user): Collection
    {
        $paymentRequest->loadMissing('items.bookingPo.excelFile');

        return $paymentRequest->items
            ->filter(fn ($item) => $item->bookingPo !== null)
            ->groupBy('booking_po_id')
            ->map(fn (Collection $items) => [
                'po' => $items->first()->bookingPo,
                'url' => self::urlFor($user, $items->first()->bookingPo, $paymentRequest),
                'items' => $items->values(),
            ]);
    }
}
