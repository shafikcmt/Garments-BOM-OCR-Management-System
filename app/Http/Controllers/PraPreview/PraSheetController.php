<?php

namespace App\Http\Controllers\PraPreview;

use App\Http\Controllers\SupplyChain\PaymentRequestController;
use App\Models\PaymentRequest;
use App\Support\PraViewAccess;

/**
 * The PRA sheet (PDF preview) for approvers. Reuses the Supply Chain sheet so
 * the document is identical; only the access check differs: a person taking
 * part in a departmental PRA, not only the supply_chain role.
 */
class PraSheetController extends PaymentRequestController
{
    public function sheet(PaymentRequest $paymentRequest)
    {
        return $this->downloadPdf($paymentRequest);
    }

    protected function authorizePaymentRequest(PaymentRequest $paymentRequest): void
    {
        abort_if(! PraViewAccess::mayView($paymentRequest, auth()->user()), 403);
    }
}
