<?php

namespace App\Http\Controllers;

use App\Models\PaymentRequest;
use App\Models\PraApproval;
use App\Services\PraApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Approver-facing screens: the "Pending PRA Approvals" list, the review page
 * and the approve / reject actions. Access is gated by the `approve-pra`
 * permission; each approver only ever sees the PRAs they were selected for.
 */
class PraApprovalController extends Controller
{
    public function __construct(protected PraApprovalService $notifier)
    {
    }

    public function index()
    {
        $userId = auth()->id();

        // Candidate PRAs still in the approval stage that this approver has a
        // row for; the current-cycle pending check is applied in PHP so stale
        // rows from earlier (rejected) cycles never leak in.
        $pending = PaymentRequest::query()
            ->where('status', PaymentRequest::STATUS_PENDING_APPROVAL)
            ->whereHas('approvals', fn ($q) => $q->where('approver_id', $userId)->where('status', PraApproval::STATUS_PENDING))
            ->with(['createdBy', 'approvals.approver'])
            ->latest('id')
            ->get()
            ->filter(function (PaymentRequest $pr) use ($userId) {
                return $pr->currentApprovals()
                    ->where('approver_id', $userId)
                    ->where('status', PraApproval::STATUS_PENDING)
                    ->isNotEmpty();
            })
            ->values();

        return view('pra-approvals.index', [
            'pendingRequests' => $pending,
        ]);
    }

    public function show(PaymentRequest $paymentRequest)
    {
        $paymentRequest->load(['items', 'createdBy', 'approvals.approver']);

        $myApproval = $this->currentApprovalFor($paymentRequest, auth()->id());

        // Only someone who was selected for this PRA (current or a past cycle)
        // or an admin may open the review page.
        abort_if(! $myApproval && ! $paymentRequest->approvals->contains('approver_id', auth()->id()) && ! auth()->user()->hasRole('admin'), 403);

        return view('pra-approvals.show', [
            'paymentRequest' => $paymentRequest,
            'progress' => $paymentRequest->approvalProgress(),
            'myApproval' => $myApproval,
            'currentApprovals' => $paymentRequest->currentApprovals()->load('approver'),
        ]);
    }

    public function approve(Request $request, PaymentRequest $paymentRequest)
    {
        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $paymentRequest->load(['approvals', 'createdBy']);
        $approval = $this->currentApprovalFor($paymentRequest, auth()->id());

        if (! $approval || ! $approval->isPending() || $paymentRequest->status !== PaymentRequest::STATUS_PENDING_APPROVAL) {
            return redirect()->route('pra_approvals.index')
                ->with('warning', 'This PRA is no longer awaiting your approval.');
        }

        DB::transaction(function () use ($approval, $paymentRequest, $validated) {
            $approval->update([
                'status' => PraApproval::STATUS_APPROVED,
                'comment' => $validated['comment'] ?? null,
                'acted_at' => now(),
            ]);

            $paymentRequest->load('approvals');
            $current = $paymentRequest->currentApprovals();

            // All-must-approve: only finalise when every selected approver in
            // this cycle has approved.
            if ($current->where('status', PraApproval::STATUS_APPROVED)->count() === $current->count()) {
                $paymentRequest->update([
                    'status' => PaymentRequest::STATUS_APPROVED,
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                ]);
            }
        });

        $paymentRequest->refresh()->load(['approvals.approver', 'createdBy']);

        if ($paymentRequest->status === PaymentRequest::STATUS_APPROVED) {
            $this->notifier->notifyResult($paymentRequest, PaymentRequest::STATUS_APPROVED, auth()->id());

            return redirect()->route('pra_approvals.index')
                ->with('success', 'PRA ' . $paymentRequest->request_no . ' is now fully approved.');
        }

        $progress = $paymentRequest->approvalProgress();

        return redirect()->route('pra_approvals.index')
            ->with('success', 'Your approval for ' . $paymentRequest->request_no . ' is recorded. ' . $progress['label'] . '.');
    }

    public function reject(Request $request, PaymentRequest $paymentRequest)
    {
        $validated = $request->validate([
            'comment' => ['required', 'string', 'max:2000'],
        ], [
            'comment.required' => 'A reason is required when rejecting a PRA.',
        ]);

        $paymentRequest->load(['approvals', 'createdBy']);
        $approval = $this->currentApprovalFor($paymentRequest, auth()->id());

        if (! $approval || ! $approval->isPending() || $paymentRequest->status !== PaymentRequest::STATUS_PENDING_APPROVAL) {
            return redirect()->route('pra_approvals.index')
                ->with('warning', 'This PRA is no longer awaiting your approval.');
        }

        DB::transaction(function () use ($approval, $paymentRequest, $validated) {
            $approval->update([
                'status' => PraApproval::STATUS_REJECTED,
                'comment' => $validated['comment'],
                'acted_at' => now(),
            ]);

            // A single rejection rejects the whole PRA; remaining approvers no
            // longer need to act.
            $paymentRequest->update([
                'status' => PaymentRequest::STATUS_REJECTED,
                'approved_by' => null,
                'approved_at' => null,
            ]);
        });

        $paymentRequest->refresh()->load(['approvals.approver', 'createdBy']);
        $this->notifier->notifyResult($paymentRequest, PaymentRequest::STATUS_REJECTED, auth()->id());

        return redirect()->route('pra_approvals.index')
            ->with('success', 'PRA ' . $paymentRequest->request_no . ' has been rejected. The creator has been notified.');
    }

    /**
     * The current-cycle approval row for a given user, or null.
     */
    protected function currentApprovalFor(PaymentRequest $paymentRequest, int $userId): ?PraApproval
    {
        return $paymentRequest->currentApprovals()
            ->firstWhere('approver_id', $userId);
    }
}
