<?php

namespace App\Services;

use App\Mail\PraApprovalRequestMail;
use App\Mail\PraApprovalResultMail;
use App\Models\AppNotification;
use App\Models\PaymentRequest;
use App\Models\PraApproval;
use App\Models\User;
use App\Support\PraApprovalSettings;
use App\Support\PraStageActors;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Central place for dispatching PRA approval notifications through both
 * channels: in-app (AppNotification, always on) and email (toggleable via
 * PraApprovalSettings). Email failures are logged and never break the flow.
 */
class PraApprovalService
{
    /**
     * Notify each selected approver that a PRA needs their approval.
     *
     * @param Collection<int, User> $approvers
     */
    public function notifyApprovalRequest(PaymentRequest $paymentRequest, Collection $approvers): void
    {
        $actorId = $paymentRequest->created_by;
        $reviewUrl = route('pra_approvals.show', $paymentRequest);
        $creatorName = optional($paymentRequest->createdBy)->name ?? 'A supply chain user';

        $mailData = [
            'request_no' => $paymentRequest->request_no,
            'requested_by' => $creatorName,
            'buyer' => $paymentRequest->buyer_name,
            'supplier' => $paymentRequest->supplier_name,
            'season' => $paymentRequest->season_name,
            'total_amount' => '$ ' . number_format((float) $paymentRequest->total_pi_amount, 2),
            'payment_required_date' => $this->requiredDateLabel($paymentRequest),
            'review_url' => $reviewUrl,
        ];

        $mailEnabled = PraApprovalSettings::mailEnabled();

        foreach ($approvers as $approver) {
            AppNotification::create([
                'user_id' => $approver->id,
                'actor_id' => $actorId,
                'type' => 'pra_approval_request',
                'title' => 'PRA Approval Request: ' . $paymentRequest->request_no,
                'message' => $creatorName . ' has requested your approval for PRA ' . $paymentRequest->request_no . '.',
                'url' => $reviewUrl,
                'data' => ['payment_request_id' => $paymentRequest->id],
            ]);

            if ($mailEnabled && $approver->email) {
                try {
                    Mail::to($approver->email)->send(new PraApprovalRequestMail($mailData));
                } catch (\Throwable $e) {
                    Log::error('PRA approval request email failed: ' . $e->getMessage(), [
                        'payment_request_id' => $paymentRequest->id,
                        'approver_id' => $approver->id,
                    ]);
                }
            }
        }
    }

    /**
     * Notify the designated checker that a PRA is waiting for their check, the
     * first step of the sequential Check -> Approve flow. Approvers are only
     * notified after the check is completed.
     */
    public function notifyCheckRequest(PaymentRequest $paymentRequest, User $checker): void
    {
        $actorId = $paymentRequest->created_by;
        $reviewUrl = route('pra_approvals.show', $paymentRequest);
        $creatorName = optional($paymentRequest->createdBy)->name ?? 'A supply chain user';

        AppNotification::create([
            'user_id' => $checker->id,
            'actor_id' => $actorId,
            'type' => 'pra_check_request',
            'title' => 'PRA Check Request: ' . $paymentRequest->request_no,
            'message' => $creatorName . ' has requested you to check PRA ' . $paymentRequest->request_no . ' before approval.',
            'url' => $reviewUrl,
            'data' => ['payment_request_id' => $paymentRequest->id],
        ]);

        if (PraApprovalSettings::mailEnabled() && $checker->email) {
            try {
                Mail::to($checker->email)->send(new PraApprovalRequestMail([
                    'request_no' => $paymentRequest->request_no,
                    'requested_by' => $creatorName,
                    'buyer' => $paymentRequest->buyer_name,
                    'supplier' => $paymentRequest->supplier_name,
                    'season' => $paymentRequest->season_name,
                    'total_amount' => '$ ' . number_format((float) $paymentRequest->total_pi_amount, 2),
                    'payment_required_date' => $this->requiredDateLabel($paymentRequest),
                    'review_url' => $reviewUrl,
                ]));
            } catch (\Throwable $e) {
                Log::error('PRA check request email failed: ' . $e->getMessage(), [
                    'payment_request_id' => $paymentRequest->id,
                    'checker_id' => $checker->id,
                ]);
            }
        }
    }

    /**
     * Notify the PRA creator that the current cycle finished (approved or
     * rejected), with the approver-wise decision breakdown.
     */
    public function notifyResult(PaymentRequest $paymentRequest, string $state, ?int $actorId = null): void
    {
        $creator = $paymentRequest->createdBy;
        if (! $creator) {
            return;
        }

        $isRejected = $state === PaymentRequest::STATUS_REJECTED;
        $statusLabel = $isRejected ? 'Rejected' : 'Approved';

        $decisions = $paymentRequest->currentApprovals()
            ->map(fn (PraApproval $approval) => [
                'name' => optional($approval->approver)->name ?? '—',
                'status' => $approval->status,
                'comment' => $approval->comment,
            ])
            ->values()
            ->all();

        $reviewUrl = route('supply_chain.payment_requests.show', $paymentRequest);

        AppNotification::create([
            'user_id' => $creator->id,
            'actor_id' => $actorId,
            'type' => $isRejected ? 'pra_rejected' : 'pra_approved',
            'title' => 'PRA ' . $statusLabel . ': ' . $paymentRequest->request_no,
            'message' => 'Your PRA ' . $paymentRequest->request_no . ' has been ' . strtolower($statusLabel) . '.',
            'url' => $reviewUrl,
            'data' => ['payment_request_id' => $paymentRequest->id],
        ]);

        if (PraApprovalSettings::mailEnabled() && $creator->email) {
            try {
                Mail::to($creator->email)->send(new PraApprovalResultMail([
                    'request_no' => $paymentRequest->request_no,
                    'status' => $state,
                    'status_label' => $statusLabel,
                    'decisions' => $decisions,
                    'review_url' => $reviewUrl,
                ]));
            } catch (\Throwable $e) {
                Log::error('PRA approval result email failed: ' . $e->getMessage(), [
                    'payment_request_id' => $paymentRequest->id,
                ]);
            }
        }
    }

    /**
     * Tell the people who can act on a departmental PRA's CURRENT step that it
     * is waiting for them.
     *
     * BUYER SCOPING IS ALREADY DONE, and this is the reason there is no scoping
     * logic in this method. $stage->users is what PraStageResolver returned, and
     * the resolver has already confined the merchant steps to the PRA's buyer
     * through User::hasBuyerAccess(). Re-filtering here would be a second copy
     * of a rule that exists once — which is exactly how the earlier fan-out bug
     * happened, one filter drifting out of step with the other. The fan-out is
     * correct because the SET is correct.
     *
     * A step nobody holds notifies nobody, deliberately: the PRA still parks
     * there visibly with PraStageActors::problem() on screen. Emailing an empty
     * set is not a way to report a configuration gap.
     */
    public function notifyStageActors(PaymentRequest $paymentRequest, PraStageActors $stage): void
    {
        if (! $stage->isActionable()) {
            return;
        }

        $actorId = $paymentRequest->created_by;
        $reviewUrl = route('pra_departmental.show', $paymentRequest);
        $creatorName = optional($paymentRequest->createdBy)->name ?? 'A supply chain user';

        $mailData = [
            'request_no' => $paymentRequest->request_no,
            'requested_by' => $creatorName,
            'buyer' => $paymentRequest->buyer_name,
            'supplier' => $paymentRequest->supplier_name,
            'season' => $paymentRequest->season_name,
            'total_amount' => '$ ' . number_format((float) $paymentRequest->total_pi_amount, 2),
            'payment_required_date' => $this->requiredDateLabel($paymentRequest),
            'review_url' => $reviewUrl,
        ];

        $mailEnabled = PraApprovalSettings::mailEnabled();

        foreach ($stage->users as $actor) {
            AppNotification::create([
                'user_id' => $actor->id,
                'actor_id' => $actorId,
                'type' => 'pra_stage_request',
                'title' => 'PRA ' . $stage->label . ': ' . $paymentRequest->request_no,
                'message' => 'PRA ' . $paymentRequest->request_no . ' is waiting for '
                    . $stage->label . '. Raised by ' . $creatorName . '.',
                'url' => $reviewUrl,
                'data' => [
                    'payment_request_id' => $paymentRequest->id,
                    'sequence' => $stage->sequence,
                ],
            ]);

            if ($mailEnabled && $actor->email) {
                try {
                    Mail::to($actor->email)->send(new PraApprovalRequestMail($mailData));
                } catch (\Throwable $e) {
                    Log::error('PRA stage request email failed: ' . $e->getMessage(), [
                        'payment_request_id' => $paymentRequest->id,
                        'actor_id' => $actor->id,
                        'sequence' => $stage->sequence,
                    ]);
                }
            }
        }
    }

    /**
     * The end of a departmental PRA: completed at step 6, or rejected anywhere.
     *
     * NOT notifyResult(). That one reads currentApprovals(), which is flow-1
     * shape — pending rows for a fixed set of chosen approvers — and would
     * report a departmental PRA as having no reviewers at all. Same intent,
     * different data, so a different method rather than a branch inside one.
     *
     * WHO ELSE HEARS ABOUT A REJECTION. Everyone who already approved in this
     * cycle, not just the creator. Someone who signed off at step 2 has a stake
     * in learning it was refused at step 5 — under a single-step flow that
     * person WAS the decision, so telling only the creator loses nothing; across
     * six steps it loses most of the audience. The rejecting actor is excluded;
     * they were there.
     *
     * @param  \Illuminate\Support\Collection<int, PraApproval>  $decisions
     */
    public function notifyDepartmentalResult(
        PaymentRequest $paymentRequest,
        string $state,
        Collection $decisions,
        ?int $actorId = null
    ): void {
        $isRejected = $state === PaymentRequest::STATUS_REJECTED;
        $statusLabel = $isRejected ? 'Rejected' : 'Completed';

        $reviewUrl = route('supply_chain.payment_requests.show', $paymentRequest);

        $summary = $decisions
            ->map(fn (PraApproval $decision) => [
                'name' => optional($decision->approver)->name ?? '—',
                'status' => $decision->status,
                'comment' => $decision->comment,
            ])
            ->values()
            ->all();

        $message = $isRejected
            ? 'PRA ' . $paymentRequest->request_no . ' was rejected and has been returned to the creator.'
            : 'PRA ' . $paymentRequest->request_no . ' is complete. Payment has been recorded.';

        foreach ($this->departmentalAudience($paymentRequest, $decisions, $actorId) as $recipient) {
            AppNotification::create([
                'user_id' => $recipient->id,
                'actor_id' => $actorId,
                'type' => $isRejected ? 'pra_rejected' : 'pra_completed',
                'title' => 'PRA ' . $statusLabel . ': ' . $paymentRequest->request_no,
                'message' => $message,
                'url' => $reviewUrl,
                'data' => ['payment_request_id' => $paymentRequest->id],
            ]);

            if (PraApprovalSettings::mailEnabled() && $recipient->email) {
                try {
                    Mail::to($recipient->email)->send(new PraApprovalResultMail([
                        'request_no' => $paymentRequest->request_no,
                        'status' => $state,
                        'status_label' => $statusLabel,
                        'decisions' => $summary,
                        'review_url' => $reviewUrl,
                    ]));
                } catch (\Throwable $e) {
                    Log::error('PRA departmental result email failed: ' . $e->getMessage(), [
                        'payment_request_id' => $paymentRequest->id,
                        'recipient_id' => $recipient->id,
                    ]);
                }
            }
        }
    }

    /**
     * The creator, plus everyone who already approved in this cycle, minus the
     * person who just acted. Deduplicated — the creator may also have approved.
     *
     * @param  \Illuminate\Support\Collection<int, PraApproval>  $decisions
     * @return \Illuminate\Support\Collection<int, User>
     */
    protected function departmentalAudience(
        PaymentRequest $paymentRequest,
        Collection $decisions,
        ?int $actorId
    ): Collection {
        $ids = $decisions
            ->filter(fn (PraApproval $decision) => $decision->isApproved())
            ->pluck('approver_id')
            ->push($paymentRequest->created_by)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => $actorId !== null && $id === (int) $actorId)
            ->unique()
            ->values();

        return $ids->isEmpty() ? collect() : User::whereIn('id', $ids->all())->get();
    }

    protected function requiredDateLabel(PaymentRequest $paymentRequest): ?string
    {
        $raw = data_get($paymentRequest->data, 'payment_required_date');

        if (! $raw) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($raw)->format('jS M-Y');
        } catch (\Throwable $e) {
            return (string) $raw;
        }
    }
}
