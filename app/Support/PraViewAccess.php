<?php

namespace App\Support;

use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\PraWorkflow;

/**
 * Who may look at a departmental PRA and the documents behind it (the PRA sheet,
 * its Booking POs, its PI copy).
 *
 * The same participation rule the PRA detail page uses: the people at the
 * CURRENT step, anyone who already acted on it, its creator, and Admin. Someone
 * at a later step is NOT included, so a PRA does not reach the next step's
 * users until the step before it is approved.
 */
class PraViewAccess
{
    public static function mayView(PaymentRequest $paymentRequest, ?User $user): bool
    {
        if ($user === null || ! $paymentRequest->isDepartmentalFlow()) {
            return false;
        }

        if ($user->hasRole('admin') || (int) $paymentRequest->created_by === (int) $user->id) {
            return true;
        }

        if ($paymentRequest->approvals()->where('approver_id', $user->id)->exists()) {
            return true;
        }

        $stage = app(PraWorkflow::class)->currentStage($paymentRequest);

        return (bool) ($stage && $stage->includes($user));
    }
}
