<?php

namespace App\Http\Controllers;

use App\Exceptions\PraWorkflowException;
use App\Models\PaymentRequest;
use App\Models\PraApproval;
use App\Services\PraApprovalService;
use App\Services\PraStageResolver;
use App\Services\PraWorkflow;
use Illuminate\Http\Request;

/**
 * The departmental PRA queue — Merchandising, Accounts and Commercial.
 *
 * TWO GATES, AND BOTH ARE LOAD-BEARING:
 *
 *   route middleware  can:pra.departmental.act — may you open this queue at all.
 *                     Deliberately NOT approve-pra, which belongs to the legacy
 *                     flow and its hand-picked pool.
 *   PraStageResolver  may you act on THIS PRA right now. Asked per record, every
 *                     time, on read and on write alike.
 *
 * The permission alone grants nothing: a merchant holding it still sees only the
 * PRAs whose current step resolves to them. Every write goes through
 * PraWorkflow, which re-asks the same question rather than trusting that the
 * screen only offered reachable buttons.
 *
 * LEGACY PRAs ARE NOT VISIBLE HERE AT ALL. Every query and every action is
 * fenced to flow_version 2 — see assertDepartmental(). A flow-1 PRA reached by
 * hand-typed id gets a 404, not a filtered-empty page, because pretending it
 * does not exist is the correct answer to someone probing for it.
 */
class PraDepartmentalApprovalController extends Controller
{
    public function __construct(
        protected PraWorkflow $workflow,
        protected PraStageResolver $resolver,
        protected PraApprovalService $notifier,
    ) {
    }

    /**
     * Everything waiting on a step THIS user may act on.
     *
     * The flow_version and "in progress" narrowing happen in SQL; the
     * per-PRA eligibility is applied in PHP because it depends on the resolver,
     * which reads buyer ownership and access per record. The SQL half keeps the
     * PHP half small — it only ever sees departmental PRAs that are actually
     * mid-chain.
     */
    public function index()
    {
        $user = auth()->user();

        $pending = PaymentRequest::query()
            ->where('flow_version', PaymentRequest::FLOW_DEPARTMENTAL)
            ->whereNotNull('current_sequence')
            ->with(['createdBy', 'approvals.approver', 'items.excelFile', 'stageAssignments.user'])
            ->latest('id')
            ->get()
            ->filter(fn (PaymentRequest $pra) => $this->resolver->mayAct(
                $pra, (int) $pra->current_sequence, $user
            ))
            ->values();

        // Each row's current step WITH its primary assignee, so the list can
        // say "assigned to you" — resolved once here rather than in the view.
        $stages = $pending->mapWithKeys(fn (PaymentRequest $pra) => [
            $pra->id => $this->workflow->currentStage($pra),
        ]);

        // Rows pointed at this user float to the top; the rest keep newest-first.
        $pending = $pending
            ->sortBy(fn (PaymentRequest $pra) => $stages[$pra->id]?->isAssignee($user) ? 0 : 1, SORT_NUMERIC, false)
            ->values();

        return view('pra-departmental.index', [
            'pendingRequests' => $pending,
            'stages' => $stages,
        ]);
    }

    /**
     * Every departmental PRA this user has a legitimate connection to, at any
     * point in its life — the read-only companion to the queue above.
     *
     * The queue answers "what is waiting on me"; this answers "where did the
     * one I handled go". A PRA leaves the queue the moment its step advances,
     * which is correct, but until this list existed the person who had just
     * approved it had no page on which to find it again.
     *
     * Scope is participation, not department: the creator, or anyone holding
     * an approval row on it (any step, any cycle). A teammate who was eligible
     * but never acted is NOT a participant — their department's involvement is
     * on record under the colleague who did act. An admin sees everything; that
     * is the oversight view nobody had before.
     */
    public function all()
    {
        $user = auth()->user();

        $query = PaymentRequest::query()
            ->where('flow_version', PaymentRequest::FLOW_DEPARTMENTAL)
            ->with(['createdBy', 'approvals.approver']);

        if (! $user->hasRole('admin')) {
            $query->where(fn ($q) => $q
                ->where('created_by', $user->id)
                ->orWhereHas('approvals', fn ($a) => $a->where('approver_id', $user->id)));
        }

        $requests = $query->latest('id')->get();

        // The user's own last decision on each row — the "what did I do here"
        // column. Newest cycle wins, so after a rejection and resubmit the row
        // shows the round they most recently took part in.
        $myDecisions = $requests->mapWithKeys(fn (PaymentRequest $pra) => [
            $pra->id => $pra->approvals
                ->where('approver_id', $user->id)
                ->sortByDesc(fn (PraApproval $row) => [$row->cycle, $row->id])
                ->first(),
        ]);

        return view('pra-departmental.all', [
            'requests' => $requests,
            'myDecisions' => $myDecisions,
            'isAdmin' => $user->hasRole('admin'),
        ]);
    }

    public function show(PaymentRequest $paymentRequest)
    {
        $this->assertDepartmental($paymentRequest);

        $paymentRequest->load(['items.excelFile', 'createdBy', 'approvals.approver', 'approvals.assignedUser', 'stageAssignments.user']);

        $stage = $this->workflow->currentStage($paymentRequest);
        $user = auth()->user();

        // Read access follows the same rule as write access, with one addition:
        // someone who already acted on this PRA — or raised it — may look back
        // at it. Anyone else is refused rather than shown a read-only copy of
        // another department's payment paperwork. The same participation rule
        // scopes the All PRAs list, so a row that list shows always opens.
        $mayView = ($stage && $stage->includes($user))
            || $paymentRequest->approvals->contains('approver_id', $user->id)
            || (int) $paymentRequest->created_by === (int) $user->id
            || $user->hasRole('admin');

        abort_if(! $mayView, 403);

        $canAct = (bool) ($stage && $stage->includes($user));

        return view('pra-departmental.show', [
            'paymentRequest' => $paymentRequest,
            'stage' => $stage,
            'decisions' => $this->workflow->currentCycleDecisions($paymentRequest),
            'canAct' => $canAct,
            // The Booking POs this PRA pays for, with the lines of this PRA
            // under each and a link only where this viewer could open it.
            'bookingPos' => \App\Support\BookingPoLink::forPaymentRequest($paymentRequest, $user),
            // The step this approval will hand on to, with ITS eligible actors —
            // the options for the optional "assign the next step to" picker.
            // Null at step 6, which hands on to nobody.
            'nextStage' => $canAct ? $this->nextStageOf($paymentRequest, $stage) : null,
        ]);
    }

    /**
     * The step after the current one, resolved for this PRA, or null when the
     * current step is the last.
     */
    protected function nextStageOf(PaymentRequest $paymentRequest, ?\App\Support\PraStageActors $stage): ?\App\Support\PraStageActors
    {
        if ($stage === null) {
            return null;
        }

        $next = $this->resolver->nextSequence($stage->sequence);

        return $next === null ? null : $this->workflow->stage($paymentRequest, $next);
    }

    public function approve(Request $request, PaymentRequest $paymentRequest)
    {
        $this->assertDepartmental($paymentRequest);

        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:2000'],
            // Optional: the primary assignee for the step this approval hands
            // on to. Checked against that step's eligible set before the
            // approval is recorded, so a bad pick refuses the whole submission
            // rather than approving and then failing to assign.
            'assign_next' => ['nullable', 'integer'],
        ]);

        $assignNext = ! empty($validated['assign_next']) ? (int) $validated['assign_next'] : null;
        $nextSequence = null;

        if ($assignNext !== null) {
            $paymentRequest->load('stageAssignments.user');
            $nextStage = $this->nextStageOf($paymentRequest, $this->workflow->currentStage($paymentRequest));

            if ($nextStage === null || ! $nextStage->includes($assignNext)) {
                return redirect()->route('pra_departmental.show', $paymentRequest)
                    ->with('warning', 'The person selected for the next step is not eligible to act on it. Choose someone from the list, or leave it automatic.');
            }

            $nextSequence = $nextStage->sequence;
        }

        try {
            $paymentRequest = $this->workflow->approve(
                $paymentRequest, auth()->user(), $validated['comment'] ?? null
            );

            // Only after the approval stands. assign() is the one writer of
            // assignments and re-checks eligibility itself.
            if ($assignNext !== null && $nextSequence !== null) {
                $paymentRequest = $this->workflow->assign(
                    $paymentRequest, [$nextSequence => $assignNext], auth()->user()
                );
            }
        } catch (PraWorkflowException $e) {
            return redirect()->route('pra_departmental.index')->with('warning', $e->getMessage());
        }

        $next = $this->workflow->currentStage($paymentRequest);

        // Hand the baton on. A blocked next step notifies nobody — nobody holds
        // the post — and the warning below is what reports that instead.
        if ($next) {
            $this->notifier->notifyStageActors($paymentRequest, $next);
        } else {
            $this->notifier->notifyDepartmentalResult(
                $paymentRequest,
                PaymentRequest::STATUS_COMPLETED,
                $this->workflow->currentCycleDecisions($paymentRequest),
                auth()->id()
            );
        }

        $message = $next
            ? 'PRA ' . $paymentRequest->request_no . ' approved and sent to ' . $next->label
                . ($next->assignee ? ', assigned to ' . $next->assignee->name : '') . '.'
            : 'PRA ' . $paymentRequest->request_no . ' is complete. Payment has been recorded.';

        // A step nobody can act on must say so here, at the moment it becomes
        // somebody's problem, rather than waiting for them to find it.
        if ($next && ! $next->isActionable()) {
            return redirect()->route('pra_departmental.index')
                ->with('warning', $message . ' ' . $next->problem());
        }

        return redirect()->route('pra_departmental.index')->with('success', $message);
    }

    public function reject(Request $request, PaymentRequest $paymentRequest)
    {
        $this->assertDepartmental($paymentRequest);

        $validated = $request->validate([
            'comment' => ['required', 'string', 'max:2000'],
        ], [
            'comment.required' => 'A reason is required when rejecting a PRA.',
        ]);

        try {
            $paymentRequest = $this->workflow->reject(
                $paymentRequest, auth()->user(), $validated['comment']
            );
        } catch (PraWorkflowException $e) {
            return redirect()->route('pra_departmental.index')->with('warning', $e->getMessage());
        }

        // The creator AND everyone who already signed off this cycle — they
        // approved something that has since been refused.
        $this->notifier->notifyDepartmentalResult(
            $paymentRequest,
            PaymentRequest::STATUS_REJECTED,
            $this->workflow->currentCycleDecisions($paymentRequest),
            auth()->id()
        );

        return redirect()->route('pra_departmental.index')->with(
            'success',
            'PRA ' . $paymentRequest->request_no . ' has been returned to '
            . ($paymentRequest->createdBy?->name ?? 'the creator') . '.'
        );
    }

    /**
     * 404, not 403. A legacy PRA is not a record this screen is refusing to show
     * you — it is a record this screen has no concept of.
     */
    protected function assertDepartmental(PaymentRequest $paymentRequest): void
    {
        abort_if(! $paymentRequest->isDepartmentalFlow(), 404);
    }
}
