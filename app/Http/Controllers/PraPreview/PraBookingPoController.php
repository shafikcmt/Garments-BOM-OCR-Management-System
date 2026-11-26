<?php

namespace App\Http\Controllers\PraPreview;

use App\Http\Controllers\SupplyChain\BookingController;
use App\Models\BookingPo;
use App\Models\PaymentRequest;
use App\Support\PraViewAccess;

/**
 * A Booking PO (print view or PDF) for approvers of a PRA that pays for it.
 * Reuses the Supply Chain document; the access check is "may view this PRA and
 * the PO is one of its lines".
 */
class PraBookingPoController extends BookingController
{
    protected ?PaymentRequest $viaPra = null;

    public function previewPo(PaymentRequest $paymentRequest, BookingPo $bookingPo)
    {
        $this->viaPra = $paymentRequest;

        return $this->print($bookingPo);
    }

    public function pdfPo(PaymentRequest $paymentRequest, BookingPo $bookingPo)
    {
        $this->viaPra = $paymentRequest;

        return $this->download($bookingPo);
    }

    protected function authorizeBookingPo(BookingPo $bookingPo): void
    {
        $pra = $this->viaPra;

        abort_if(
            $pra === null
                || ! PraViewAccess::mayView($pra, auth()->user())
                || ! $pra->items()->where('booking_po_id', $bookingPo->id)->exists(),
            403
        );
    }
}
