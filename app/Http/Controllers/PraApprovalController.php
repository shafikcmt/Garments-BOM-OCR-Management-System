<?php

namespace App\Http\Controllers;

use App\Models\PaymentRequest;
use App\Models\PraApproval;
use App\Models\User;
use App\Services\PraApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Approver-facing screens: the "Pending PRA Approvals" list, the review page
 * and the check / approve / reject actions. Access is gated by the
 * `approve-pra` permission; each user only ever sees the PRAs they were
 * selected for, and only for the stage (check or approve) that is currently
 * active in the sequential Check -> Approve flow.
 */
class PraApprovalController extends Controller
{
    public function __construct(protected PraApprovalService $notifier)
    {
    }

    public function index()
    {
        $userId = auth()->id();

        // PRAs awaiting either a check or an approval from this user. The
        // stage-correct, current-cycle pending check is applied in PHP so stale
        // rows from earlier (rejected) cycles or the not-yet-active stage never
        // leak in.
        $pending = PaymentRequest::query()
            ->whereIn('status', [PaymentRequest::STATUS_PENDING_CHECK, PaymentRequest::STATUS_PENDING_APPROVAL])
            ->whereHas('approvals', fn ($q) => $q->where('approver_id', $userId)->where('status', PraApproval::STATUS_PENDING))
            ->with(['createdBy', 'approvals.approver'])
            ->latest('id')
            ->get()
            ->filter(fn (PaymentRequest $pr) => $this->actionableApprovalFor($pr, $userId) !== null)
            ->values();

        return view('pra-approvals.index', [
            'pendingRequests' => $pending,
        ]);
    }

    public function show(PaymentRequest $paymentRequest)
    {
        $paymentRequest->load(['items', 'createdBy', 'approvals.approver']);

        $userId = auth()->id();
        $actionable = $this->actionableApprovalFor($paymentRequest, $userId);
        $myRow = $paymentRequest->currentApprovals()->firstWhere('approver_id', $userId);

        // Only someone who was selected for this PRA (current or a past cycle)
        // or an admin may open the review page.
        abort_if(! $myRow && ! $paymentRequest->approvals->contains('approver_id', $userId) && ! auth()->user()->hasRole('admin'), 403);

        return view('pra-approvals.show', [
            'paymentRequest' => $paymentRequest,
            'progress' => $paymentRequest->approvalProgress(),
            'myApproval' => $myRow,
            'actionable' => $actionable,
            'currentApprovals' => $paymentRequest->currentApprovals()->load('approver'),
        ]);
    }

    public function approve(Request $request, PaymentRequest $paymentRequest)
    {
        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $paymentRequest->load(['approvals', 'createdBy']);
        $approval = $this->actionableApprovalFor($paymentRequest, auth()->id());

        if (! $approval) {
            return redirect()->route('pra_approvals.index')
                ->with('warning', 'This PRA is no longer awaiting your action.');
        }

        $isCheckStage = $approval->isCheckStage();

        DB::transaction(function () use ($approval, $paymentRequest, $validated, $isCheckStage) {
            $approval->update([
                'status' => PraApproval::STATUS_APPROVED,
                'comment' => $validated['comment'] ?? null,
                'signature_path' => auth()->user()?->signature_path,
                'acted_at' => now(),
            ]);

            $paymentRequest->load('approvals');

            if ($isCheckStage) {
                // Record the check, then release the PRA to the approvers (or
                // finalise it when the checker is the only reviewer).
                $paymentRequest->update([
                    'checked_by' => auth()->id(),
                    'checked_at' => now(),
                ]);

                $hasApprovers = $paymentRequest->currentApproveApprovals()->isNotEmpty();

                $paymentRequest->update([
                    'status' => $hasApprovers
                        ? PaymentRequest::STATUS_PENDING_APPROVAL
                        : PaymentRequest::STATUS_APPROVED,
                    'approved_at' => $hasApprovers ? null : now(),
                ]);

                return;
            }

            // Approve stage: all-must-approve — finalise only when every approver
            // in this cycle has approved.
            $approveRows = $paymentRequest->currentApproveApprovals();
            if ($approveRows->where('status', PraApproval::STATUS_APPROVED)->count() === $approveRows->count()) {
                $paymentRequest->update([
                    'status' => PaymentRequest::STATUS_APPROVED,
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                ]);
            }
        });

        $paymentRequest->refresh()->load(['approvals.approver', 'createdBy']);

        // Check completed and approvers are now due -> notify them.
        if ($isCheckStage && $paymentRequest->status === PaymentRequest::STATUS_PENDING_APPROVAL) {
            $approverIds = $paymentRequest->currentApproveApprovals()->pluck('approver_id');
            $approvers = User::whereIn('id', $approverIds)->get();
            $this->notifier->notifyApprovalRequest($paymentRequest, $approvers);

            return redirect()->route('pra_approvals.index')
                ->with('success', 'PRA ' . $paymentRequest->request_no . ' checked and sent to ' . $approvers->count() . ' approver(s).');
        }

        if ($paymentRequest->status === PaymentRequest::STATUS_APPROVED) {
            $this->notifier->notifyResult($paymentRequest, PaymentRequest::STATUS_APPROVED, auth()->id());

            $msg = $isCheckStage
                ? 'PRA ' . $paymentRequest->request_no . ' has been checked and approved.'
                : 'PRA ' . $paymentRequest->request_no . ' is now fully approved.';

            return redirect()->route('pra_approvals.index')->with('success', $msg);
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
        $approval = $this->actionableApprovalFor($paymentRequest, auth()->id());

        if (! $approval) {
            return redirect()->route('pra_approvals.index')
                ->with('warning', 'This PRA is no longer awaiting your action.');
        }

        DB::transaction(function () use ($approval, $paymentRequest, $validated) {
            $approval->update([
                'status' => PraApproval::STATUS_REJECTED,
                'comment' => $validated['comment'],
                'acted_at' => now(),
            ]);

            // A single rejection (at either stage) rejects the whole PRA; the
            // remaining reviewers no longer need to act.
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
     * The current-cycle row this user may act on right now, respecting the
     * active stage: the check row while the PRA is pending_check, or their
     * approve row while it is pending_approval. Null when nothing is due.
     */
    protected function actionableApprovalFor(PaymentRequest $paymentRequest, int $userId): ?PraApproval
    {
        if ($paymentRequest->status === PaymentRequest::STATUS_PENDING_CHECK) {
            $check = $paymentRequest->currentCheckApproval();

            return ($check && $check->approver_id === $userId && $check->isPending()) ? $check : null;
        }

        if ($paymentRequest->status === PaymentRequest::STATUS_PENDING_APPROVAL) {
            return $paymentRequest->currentApproveApprovals()
                ->first(fn (PraApproval $a) => $a->approver_id === $userId && $a->isPending());
        }

        return null;
    }
}
