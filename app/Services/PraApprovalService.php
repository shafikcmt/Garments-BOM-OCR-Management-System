<?php

namespace App\Services;

use App\Mail\PraApprovalRequestMail;
use App\Mail\PraApprovalResultMail;
use App\Models\AppNotification;
use App\Models\PaymentRequest;
use App\Models\PraApproval;
use App\Models\User;
use App\Support\PraApprovalSettings;
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
