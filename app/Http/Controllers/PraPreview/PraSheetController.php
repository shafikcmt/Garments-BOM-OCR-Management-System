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
    /** The PRA sheet inside the app (toolbar + the PDF), not a bare browser PDF tab. */
    public function sheet(PaymentRequest $paymentRequest)
    {
        $this->authorizePaymentRequest($paymentRequest);

        return view('pra-departmental.sheet', ['paymentRequest' => $paymentRequest]);
    }

    /** The same sheet as HTML, for the in-app paper view (the page frames and scales it). */
    public function html(PaymentRequest $paymentRequest)
    {
        $this->authorizePaymentRequest($paymentRequest);

        [$approvalRows, $summary] = $this->approvalDataFor($paymentRequest);

        return view('supply-chain.payment-requests.approval_pdf', [
            'paymentRequest' => $paymentRequest,
            'summary' => $summary,
            'approvalRows' => $approvalRows,
            'isPdf' => false,
        ]);
    }

    public function pdf(PaymentRequest $paymentRequest)
    {
        return $this->downloadPdf($paymentRequest);
    }

    protected function authorizePaymentRequest(PaymentRequest $paymentRequest): void
    {
        abort_if(! PraViewAccess::mayView($paymentRequest, auth()->user()), 403);
    }
}
