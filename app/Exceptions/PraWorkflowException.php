<?php

namespace App\Exceptions;

use App\Models\PaymentRequest;
use RuntimeException;

/**
 * A refusal from the departmental PRA state machine.
 *
 * Every message here is written to be shown to a non-technical user as-is —
 * office and factory staff, per CLAUDE.md — so each one says what went wrong
 * AND what to do about it. Phase 4 renders these straight into a flash message.
 *
 * These are guard violations, not validation errors: reaching one means either
 * a stale screen or a hand-made request, and both deserve a refusal rather than
 * a quiet no-op.
 */
class PraWorkflowException extends RuntimeException
{
    public static function notDepartmental(PaymentRequest $paymentRequest): self
    {
        // THE ISOLATION GUARD. A legacy PRA reaching the departmental machine is
        // a programming error, and it fails loudly here rather than quietly
        // rewriting a live record's status.
        return new self(
            'PRA ' . $paymentRequest->request_no . ' is not on the departmental approval flow.'
        );
    }

    public static function notInProgress(PaymentRequest $paymentRequest): self
    {
        return new self(
            'PRA ' . $paymentRequest->request_no . ' is not waiting on any approval step.'
        );
    }

    public static function notYourStep(PaymentRequest $paymentRequest, string $stepLabel): self
    {
        return new self(
            'You are not the assigned approver for the ' . $stepLabel
            . ' step of PRA ' . $paymentRequest->request_no . '.'
        );
    }

    /**
     * The configuration gap: the step has nobody who can act on it.
     * $problem comes from PraStageActors::problem() and already names the fix.
     */
    public static function stepBlocked(string $problem): self
    {
        return new self($problem);
    }

    /**
     * A creator tried to point a step at someone the resolver would not let act
     * on it. Only reachable by a stale form or a hand-made request — the
     * dropdown offers nobody else.
     */
    public static function assigneeNotEligible(PaymentRequest $paymentRequest, string $stepLabel): self
    {
        return new self(
            'The person selected for the ' . $stepLabel . ' step of PRA '
            . $paymentRequest->request_no . ' is not eligible to act on it. Choose someone from the list, or leave it automatic.'
        );
    }

    public static function notRejected(PaymentRequest $paymentRequest): self
    {
        return new self(
            'PRA ' . $paymentRequest->request_no . ' can only be resubmitted after it has been rejected.'
        );
    }
}
