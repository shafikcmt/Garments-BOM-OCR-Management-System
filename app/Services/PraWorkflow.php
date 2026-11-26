<?php

namespace App\Services;

use App\Exceptions\PraWorkflowException;
use App\Models\PaymentRequest;
use App\Models\PraApproval;
use App\Models\PraStageAssignment;
use App\Models\User;
use App\Support\PraStageActors;
use Illuminate\Support\Facades\DB;

/**
 * THE 6-STEP DEPARTMENTAL PRA STATE MACHINE.
 *
 *   1  Supply Chain  creates
 *   2  Merchant      Team  checks     ─┐
 *   3  Merchant      Head  approves    │ current_sequence walks 2 → 6, one
 *   4  Accounts      Team  checks      │ step at a time, and any ONE eligible
 *   5  Accounts      Head  approves    │ actor carries each step.
 *   6  Commercial    Head  completes  ─┘
 *
 * WHERE THE STATE LIVES. `payment_requests.current_sequence` is the step being
 * waited on; `pra_approvals` rows are written as decisions are TAKEN, so the
 * table is an audit log rather than a worklist. That is the deliberate
 * difference from flow 1, which pre-creates pending rows — and it is why a group
 * step ("the Merchant Team") needs no placeholder row naming a person who has
 * not been chosen yet.
 *
 * LEGACY ISOLATION IS THIS CLASS'S FIRST JOB. Every public mutator calls
 * assertDepartmental() before touching anything. A flow-1 PRA cannot be advanced,
 * rejected or resubmitted through here at all — it throws, having written
 * nothing. Nineteen live PRAs depend on that being airtight, and the guard is
 * deliberately the first statement in every method rather than a condition
 * folded into the logic.
 */
class PraWorkflow
{
    public function __construct(protected PraStageResolver $resolver)
    {
    }

    /**
     * Put a freshly created PRA onto the departmental chain, waiting on step 2.
     */
    public function start(PaymentRequest $paymentRequest): PaymentRequest
    {
        $first = $this->resolver->firstSequence();

        $paymentRequest->update([
            'flow_version' => PaymentRequest::FLOW_DEPARTMENTAL,
            'current_sequence' => $first,
            'status' => $this->statusFor($first),
        ]);

        return $paymentRequest->refresh();
    }

    /**
     * The step this PRA is waiting on, resolved to its eligible actors, or null
     * when it is finished, rejected or not on this flow at all.
     */
    public function currentStage(PaymentRequest $paymentRequest): ?PraStageActors
    {
        if (! $paymentRequest->isDepartmentalFlow() || $paymentRequest->current_sequence === null) {
            return null;
        }

        return $this->stage($paymentRequest, (int) $paymentRequest->current_sequence);
    }

    /**
     * Any step of this PRA, resolved to its eligible actors WITH the primary
     * assignee attached if the creator pointed at somebody.
     *
     * THE SPLIT. PraStageResolver answers "who is eligible"; this answers "who
     * was specifically pointed at" and lays it on top. The resolver never sees
     * the assignment, and the assignment never changes the resolver's answer —
     * withAssignment() copies the eligible set across untouched. A step nobody
     * assigned comes back exactly as the resolver returned it.
     *
     * Screens that want to show the assignee — the queue, the review page, the
     * PDF's pending block — ask here rather than the resolver directly.
     */
    public function stage(PaymentRequest $paymentRequest, int $sequence): PraStageActors
    {
        $stage = $this->resolver->resolve($paymentRequest, $sequence);

        $paymentRequest->loadMissing('stageAssignments.user');

        $assignment = $paymentRequest->stageAssignments
            ->first(fn (PraStageAssignment $row) => $row->sequence === $sequence);

        return $stage->withAssignment($assignment?->user);
    }

    /**
     * Point steps at specific people — the creator's OPTIONAL manual override.
     *
     * NEVER CALLED BY THE WORKFLOW ITSELF. start(), approve(), reject() and
     * resubmit() do not know this method exists; it runs only when a creator
     * submits a selection from the create or resubmit form. With no call, no
     * row is written and every step routes exactly as it did before this
     * method existed.
     *
     * $assignments is sequence => user id, with null meaning "clear this step
     * back to automatic". Steps not mentioned are left as they are. Every named
     * person is checked against the RESOLVER's eligible set for that step and a
     * miss refuses the whole call, having written nothing — an assignment can
     * only ever name someone who could act anyway.
     *
     * @param  array<int, int|null>  $assignments
     */
    public function assign(PaymentRequest $paymentRequest, array $assignments, ?User $assignedBy = null): PaymentRequest
    {
        $this->assertDepartmental($paymentRequest);

        $writes = [];

        foreach ($assignments as $sequence => $userId) {
            $sequence = (int) $sequence;
            $userId = ($userId === null || $userId === '') ? null : (int) $userId;

            // Throws on an unknown sequence — the same refusal the resolver
            // gives everything else.
            $stage = $this->resolver->resolve($paymentRequest, $sequence);

            if ($userId !== null && ! $stage->includes($userId)) {
                throw PraWorkflowException::assigneeNotEligible($paymentRequest, $stage->label);
            }

            $writes[$sequence] = $userId;
        }

        DB::transaction(function () use ($paymentRequest, $writes, $assignedBy) {
            foreach ($writes as $sequence => $userId) {
                if ($userId === null) {
                    $paymentRequest->stageAssignments()->where('sequence', $sequence)->delete();

                    continue;
                }

                $paymentRequest->stageAssignments()->updateOrCreate(
                    ['sequence' => $sequence],
                    ['user_id' => $userId, 'assigned_by' => $assignedBy?->id]
                );
            }
        });

        return $paymentRequest->fresh(['stageAssignments.user']);
    }

    /**
     * Record this user's approval of the current step and advance.
     *
     * ANY ONE eligible actor carries the step — the first to act finishes it,
     * and the others simply find nothing waiting. No counting, no quorum.
     */
    public function approve(PaymentRequest $paymentRequest, User $user, ?string $comment = null): PaymentRequest
    {
        $stage = $this->assertActionableBy($paymentRequest, $user);
        $next = $this->resolver->nextSequence($stage->sequence);

        DB::transaction(function () use ($paymentRequest, $user, $comment, $stage, $next) {
            $this->recordDecision($paymentRequest, $user, $stage, PraApproval::STATUS_APPROVED, $comment);

            $paymentRequest->update(
                $this->legacyActorColumnsFor($stage->sequence, $user) + ($next === null
                    // Step 6 done: the payment is made and the chain is over.
                    ? ['status' => PaymentRequest::STATUS_COMPLETED, 'current_sequence' => null]
                    : ['status' => $this->statusFor($next), 'current_sequence' => $next])
            );
        });

        return $paymentRequest->refresh();
    }

    /**
     * Reject at the current step: the PRA goes back to the creator.
     *
     * EARLIER APPROVALS ARE KEPT. Nothing is deleted or reset — a rejection at
     * step 5 leaves steps 2, 3 and 4 standing in the audit trail, showing who
     * had already signed off before it turned. Resubmitting opens a fresh cycle
     * beside them rather than overwriting them.
     */
    public function reject(PaymentRequest $paymentRequest, User $user, string $comment): PaymentRequest
    {
        $stage = $this->assertActionableBy($paymentRequest, $user);

        DB::transaction(function () use ($paymentRequest, $user, $comment, $stage) {
            $this->recordDecision($paymentRequest, $user, $stage, PraApproval::STATUS_REJECTED, $comment);

            $paymentRequest->update([
                'status' => PaymentRequest::STATUS_REJECTED,
                'current_sequence' => null,
                // The chain did not finish, so the finalisation stamps must not
                // survive from a previous cycle and claim that it did.
                'approved_by' => null,
                'approved_at' => null,
            ]);
        });

        return $paymentRequest->refresh();
    }

    /**
     * Lines were taken off a PRA that is part-way through approval: close the
     * current cycle and start again at step 2, so everyone signs the changed
     * document. Earlier decisions stay in the audit trail.
     *
     * Only for a PRA that is waiting on a step. A rejected PRA needs nothing here
     * (its creator resubmits it), and a finished one cannot be changed at all.
     */
    public function restartAfterChange(PaymentRequest $paymentRequest, User $user, string $note): PaymentRequest
    {
        $this->assertDepartmental($paymentRequest);

        $stage = $this->currentStage($paymentRequest);

        if ($stage === null) {
            throw PraWorkflowException::notInProgress($paymentRequest);
        }

        DB::transaction(function () use ($paymentRequest, $user, $stage, $note) {
            $this->recordDecision($paymentRequest, $user, $stage, PraApproval::STATUS_RESTARTED, $note);

            $first = $this->resolver->firstSequence();

            $paymentRequest->update([
                'current_sequence' => $first,
                'status' => $this->statusFor($first),
                // The chain starts over, so the stamps of the old round must not
                // survive and claim it was checked or approved.
                'checked_by' => null,
                'checked_at' => null,
                'approved_by' => null,
                'approved_at' => null,
            ]);
        });

        return $paymentRequest->refresh();
    }

    /**
     * ADMIN CORRECTION: put a PRA that is waiting on a step back onto an EARLIER
     * (or the same) step, so that step is done properly by the right people.
     *
     * Used when the chain was changed under a PRA that was already moving, or a
     * step was signed by the wrong person. A PRA never goes FORWARD this way:
     * skipping approvals is not a correction.
     *
     * HISTORY IS KEPT. The current cycle is closed with a "restarted" row naming
     * the admin and the reason, exactly as restartAfterChange() does. Decisions
     * taken BEFORE the target step are still valid, so they are copied into the
     * new cycle (same person, date and signature) and keep showing on the sheet.
     * Decisions at or after the target step stay behind in the old cycle.
     */
    public function sendBackToStep(PaymentRequest $paymentRequest, User $admin, int $targetSequence, string $note): PaymentRequest
    {
        $this->assertDepartmental($paymentRequest);

        $current = $this->currentStage($paymentRequest);

        if ($current === null) {
            throw PraWorkflowException::notInProgress($paymentRequest);
        }

        if (! in_array($targetSequence, $this->resolver->sequences(), true)) {
            throw new \InvalidArgumentException('That step is not part of the approval chain.');
        }

        $targetPosition = $this->resolver->definitionFor($targetSequence)['position'];
        $currentPosition = $this->resolver->definitionFor($current->sequence)['position'];

        if ($targetPosition > $currentPosition) {
            throw new \InvalidArgumentException('A PRA can only be sent back to an earlier step, not forward.');
        }

        DB::transaction(function () use ($paymentRequest, $admin, $current, $targetSequence, $targetPosition, $note) {
            $closedCycle = $this->activeCycle($paymentRequest);

            $this->recordDecision(
                $paymentRequest, $admin, $current, PraApproval::STATUS_RESTARTED,
                'Sent back to "' . $this->resolver->definitionFor($targetSequence)['label'] . '" by admin: ' . $note
            );

            $keep = $paymentRequest->approvals
                ->where('cycle', $closedCycle)
                ->filter(fn (PraApproval $row) => $row->isApproved()
                    && isset($this->resolver->steps()[$row->sequence])
                    && $this->resolver->definitionFor((int) $row->sequence)['position'] < $targetPosition);

            $newCycle = $closedCycle + 1;

            foreach ($keep as $row) {
                $copy = $row->replicate();
                $copy->cycle = $newCycle;
                $copy->save();
            }

            $paymentRequest->load('approvals');

            $paymentRequest->update([
                'current_sequence' => $targetSequence,
                'status' => $this->statusFor($targetSequence),
                'approved_by' => null,
                'approved_at' => null,
            ] + ($targetSequence === $this->resolver->firstSequence()
                ? ['checked_by' => null, 'checked_at' => null]
                : []));
        });

        return $paymentRequest->refresh();
    }

    /**
     * Close a PRA whose lines have all been taken off. It leaves the chain
     * (no current step) and keeps its history; whatever it held is free again.
     */
    public function cancel(PaymentRequest $paymentRequest): PaymentRequest
    {
        $this->assertDepartmental($paymentRequest);

        if (in_array($paymentRequest->status, [PaymentRequest::STATUS_COMPLETED, PaymentRequest::STATUS_CANCELLED], true)) {
            throw PraWorkflowException::notInProgress($paymentRequest);
        }

        $paymentRequest->update([
            'status' => PaymentRequest::STATUS_CANCELLED,
            'current_sequence' => null,
            'total_pi_amount' => 0,
        ]);

        return $paymentRequest->refresh();
    }

    /**
     * Send a rejected PRA back around: a new cycle, starting again at step 2.
     *
     * The previous cycle's rows stay exactly where they are. activeCycle() reads
     * the new number from them, so nothing needs storing on the PRA itself.
     */
    public function resubmit(PaymentRequest $paymentRequest): PaymentRequest
    {
        $this->assertDepartmental($paymentRequest);

        if ($paymentRequest->status !== PaymentRequest::STATUS_REJECTED) {
            throw PraWorkflowException::notRejected($paymentRequest);
        }

        $first = $this->resolver->firstSequence();

        $paymentRequest->update([
            'current_sequence' => $first,
            'status' => $this->statusFor($first),
        ]);

        return $paymentRequest->refresh();
    }

    /**
     * The cycle new decisions belong to.
     *
     * Derived rather than stored: a cycle is CLOSED BY A REJECTION, so the
     * active one is the highest cycle on record, plus one if that cycle was
     * rejected. A PRA with no departmental rows yet is on cycle 1.
     *
     * Legacy rows (sequence 0) are excluded — a PRA is only ever on one flow,
     * but reading them here would be wrong if that ever stopped being true.
     */
    public function activeCycle(PaymentRequest $paymentRequest): int
    {
        $rows = $paymentRequest->approvals
            ->where('sequence', '!=', PraApproval::SEQUENCE_LEGACY);

        if ($rows->isEmpty()) {
            return 1;
        }

        $highest = (int) $rows->max('cycle');

        // A cycle is closed by a rejection, or by a restart (lines were taken off
        // the PRA, which sends it back to step 2).
        $wasRejected = $rows->where('cycle', $highest)
            ->contains(fn (PraApproval $row) => $row->isRejected() || $row->status === PraApproval::STATUS_RESTARTED);

        return $wasRejected ? $highest + 1 : $highest;
    }

    /**
     * Every decision taken in the current cycle, oldest step first. The audit
     * trail the review screen and the PDF both read.
     *
     * @return \Illuminate\Support\Collection<int, PraApproval>
     */
    public function currentCycleDecisions(PaymentRequest $paymentRequest)
    {
        $rows = $paymentRequest->approvals
            ->where('sequence', '!=', PraApproval::SEQUENCE_LEGACY);

        if ($rows->isEmpty()) {
            return collect();
        }

        // WHICH CYCLE IS "CURRENT" DEPENDS ON WHETHER THE PRA IS STILL MOVING,
        // and reading activeCycle() unconditionally gets it wrong the moment it
        // matters most. A rejection CLOSES its cycle, so activeCycle() jumps to
        // the next number the instant the rejection lands — and this method,
        // asked immediately afterwards, looked for rows in a cycle that does not
        // exist yet and found none. The people who had already approved were
        // then invisible to the rejection notice meant for them.
        //
        //   still in progress  the cycle new decisions go to. After a resubmit
        //                      that is the fresh, empty cycle — correct, the
        //                      previous round's decisions are history now.
        //   finished or        the highest cycle on record: the one that just
        //   rejected           ended, whose decisions are exactly what the
        //                      notice and the audit card want to show.
        $cycle = $paymentRequest->current_sequence !== null
            ? $this->activeCycle($paymentRequest)
            : (int) $rows->max('cycle');

        return $rows->where('cycle', $cycle)->sortBy('sequence')->values();
    }

    /**
     * The status a PRA holds while waiting on a given step. Derived from the
     * step's stage so the three "pending" states stay in step with the step
     * table rather than being restated here.
     */
    public function statusFor(int $sequence): string
    {
        return match ($this->resolver->definitionFor($sequence)['stage']) {
            PraApproval::STAGE_CHECK => PaymentRequest::STATUS_PENDING_CHECK,
            PraApproval::STAGE_APPROVE => PaymentRequest::STATUS_PENDING_APPROVAL,
            PraApproval::STAGE_COMPLETE => PaymentRequest::STATUS_PENDING_COMPLETION,
        };
    }

    /**
     * THE ISOLATION GUARD. First statement of every mutator.
     */
    protected function assertDepartmental(PaymentRequest $paymentRequest): void
    {
        if (! $paymentRequest->isDepartmentalFlow()) {
            throw PraWorkflowException::notDepartmental($paymentRequest);
        }
    }

    /**
     * The three refusals every action shares, in the order that gives the most
     * useful message: wrong flow, then nothing pending, then the configuration
     * gap, then not your step.
     */
    protected function assertActionableBy(PaymentRequest $paymentRequest, User $user): PraStageActors
    {
        $this->assertDepartmental($paymentRequest);

        $stage = $this->currentStage($paymentRequest);

        if ($stage === null) {
            throw PraWorkflowException::notInProgress($paymentRequest);
        }

        // Checked before membership on purpose: "no Accounts Head is configured"
        // is a far more useful thing to be told than "you are not the approver",
        // and it is the message that names who can fix it.
        if (! $stage->isActionable()) {
            throw PraWorkflowException::stepBlocked((string) $stage->problem());
        }

        if (! $stage->includes($user)) {
            throw PraWorkflowException::notYourStep($paymentRequest, $stage->label);
        }

        return $stage;
    }

    protected function recordDecision(
        PaymentRequest $paymentRequest,
        User $user,
        PraStageActors $stage,
        string $status,
        ?string $comment
    ): PraApproval {
        $row = PraApproval::create([
            'payment_request_id' => $paymentRequest->id,
            'approver_id' => $user->id,
            'cycle' => $this->activeCycle($paymentRequest),
            'sequence' => $stage->sequence,
            'actor_kind' => $stage->actorKind,
            'department' => $stage->department,
            'stage' => $stage->stage,
            'status' => $status,
            'comment' => $comment,
            // Who the step had been POINTED AT, whoever actually acted. Frozen
            // here so a later reassignment cannot rewrite a closed cycle. Null
            // for the ordinary case where nobody was named.
            'assigned_user_id' => $stage->namedAssignee()?->id,
            // Snapshotted, not referenced: the PDF must keep showing the
            // signature that was current when they signed.
            'signature_path' => $user->signature_path,
            'acted_at' => now(),
        ]);

        $paymentRequest->load('approvals');

        return $row;
    }

    /**
     * Keep the legacy actor columns meaningful for flow-2 PRAs too.
     *
     * checked_by / approved_by predate this flow and are read by existing list
     * screens and the PDF. Mapping the departmental chain onto them — first
     * check at step 2, final approval at step 5 — means those screens show
     * something true rather than blank. Step 6 is a payment, not an approval,
     * and deliberately claims neither column; it lives in pra_approvals only.
     *
     * @return array<string, mixed>
     */
    protected function legacyActorColumnsFor(int $sequence, User $user): array
    {
        // The first step of the chain is "the check", the last approving step is "the
        // approval" (the chain is Admin-defined, so these are found, not fixed).
        return match ($sequence) {
            $this->resolver->firstSequence() => ['checked_by' => $user->id, 'checked_at' => now()],
            $this->resolver->lastApproveSequence() => ['approved_by' => $user->id, 'approved_at' => now()],
            default => [],
        };
    }
}
