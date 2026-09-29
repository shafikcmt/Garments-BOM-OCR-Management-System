<?php

namespace App\Services;

use App\Models\Department;
use App\Models\IndentSection;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionAction;
use App\Models\PurchaseRequisitionQtyChange;
use App\Models\PurchaseRequisitionStage;
use App\Models\RequisitionSignatureBlock;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * THE REQUISITION STAGE ENGINE.
 *
 * A separate engine from the PRA one, copying its patterns: a "current
 * stage" column on the record, a plan written per cycle, decisions recorded
 * as they are taken, and a new cycle on resubmit.
 *
 * STAGES ARE THE SIGNATURE BOXES. Admin > Requisition Signatures lists the
 * boxes per sheet level; box 1 is "Prepared" (the submit itself) and every
 * box after it is a stage somebody must approve, so the printed boxes and
 * the approvers cannot drift apart.
 *
 *   Department sheet: Prepared → Section/Department Head → Store Head
 *   Final sheet:      Prepared → Store Head → HR Audit → Finance & Commercial
 *                     → Operation Head
 *
 * WHO APPROVES A STAGE is fixed at submit and saved on the plan:
 *   - Requester's Department Head: the review chain (nearest head; a head's
 *     own step is skipped). No head → the stage is skipped.
 *   - Store Department Head: the Store head on Admin > Departments.
 *   - A specific user: that user.
 * "Anyone with a role" and "Person who raised it" cannot approve a stage.
 *
 * WHAT A STAGE DOES besides being approved comes from the box's "filled
 * from" setting, so the sheet keeps writing the same status and stamp
 * columns as before — lists, counting and reports need no change:
 *   department_head → the head's decision columns
 *   store_head      → status Received by Store, store_ack_by / at
 *   approved        → status Approved, approved_by / at
 * When the last stage is approved, a final sheet is Approved and a
 * department sheet is Received by Store (it then waits for the merge).
 *
 * RULES: the raiser never approves their own sheet at any stage; one person
 * holding two stages clicks each one; no delegation; Admin is not an
 * approver; no stage is skipped by amount.
 *
 * OLD SHEETS (workflow_version NULL) never come through here.
 */
class RequisitionWorkflow
{
    public const VERSION = 1;

    public function __construct(
        private readonly RequisitionHistory $history,
        private readonly PurchaseRequisitionService $requisitions,
    ) {
    }

    /**
     * A person named on a stage must be able to open the requisition screens
     * to approve: give them store.requisition.view directly (permissions here
     * are per-user grants). Nothing is revoked from anyone.
     */
    public static function grantView(?int $userId): void
    {
        $user = $userId ? User::find($userId) : null;

        if ($user && ! $user->hasPermissionTo('store.requisition.view')) {
            $user->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('store.requisition.view', 'web'));
        }
    }

    /**
     * Why each box after "Prepared" could not be approved today, for Admin >
     * Requisition Signatures. Keyed by level, then the box's position.
     *
     * @return array<string, array<int, string>>
     */
    public static function settingsWarnings(): array
    {
        $storeHead = Department::where('key', 'store')->value('head_user_id');
        $warnings = [];

        foreach (RequisitionSignatureBlock::levelLabels() as $level => $label) {
            foreach (RequisitionSignatureBlock::query()->forLevel($level)->get()->values() as $i => $block) {
                if ($i === 0) {
                    continue;
                }

                $warning = match ($block->assignee_type) {
                    RequisitionSignatureBlock::ASSIGNEE_USER => $block->user_id ? null : 'Not set. Choose a user.',
                    RequisitionSignatureBlock::ASSIGNEE_STORE_HEAD => $storeHead ? null : 'Not set. Set the Store department head on Departments.',
                    RequisitionSignatureBlock::ASSIGNEE_DEPARTMENT_HEAD => null,
                    default => 'Must be a specific person to approve this stage.',
                };

                if ($warning) {
                    $warnings[$level][$i] = $warning;
                }
            }
        }

        return $warnings;
    }

    /*
    |--------------------------------------------------------------------------
    | The plan
    |--------------------------------------------------------------------------
    */

    /**
     * The stages a sheet would get, with each approver resolved. The head
     * stage comes from the review chain, so it matches the head step the
     * sheet is actually sent to.
     *
     * @return list<array{sequence: int, label: string, title: ?string, stamp_source: ?string, assignee_type: string, user: ?User, skipped: bool, problem: ?string}>
     */
    public function plan(string $level, ?User $raiser, ?IndentSection $section, ?string $department): array
    {
        $blocks = RequisitionSignatureBlock::query()->forLevel($level)->with('user')->get()->values();
        $stages = [];

        foreach ($blocks as $i => $block) {
            if ($i === 0) {
                continue; // "Prepared" — the submit itself.
            }

            $stage = [
                'sequence' => $i + 1,
                'label' => $block->label,
                'title' => $block->title,
                'stamp_source' => $block->stamp_source ?: null,
                'assignee_type' => $block->assignee_type,
                'user' => null,
                'skipped' => false,
                'problem' => null,
            ];
            $name = $this->stageName($block);

            switch ($block->assignee_type) {
                case RequisitionSignatureBlock::ASSIGNEE_DEPARTMENT_HEAD:
                    $first = RequisitionReviewChain::stepsFor($raiser, $section, $department)[0] ?? null;
                    $stage['user'] = $first['head'] ?? null;
                    // No head above the raiser: the stage does not apply.
                    $stage['skipped'] = $stage['user'] === null;
                    break;

                case RequisitionSignatureBlock::ASSIGNEE_STORE_HEAD:
                    $stage['user'] = User::find(Department::where('key', 'store')->value('head_user_id'));
                    $stage['problem'] = $stage['user'] ? null
                        : $name.' approver is not set. Ask Admin to set the Store department head.';
                    break;

                case RequisitionSignatureBlock::ASSIGNEE_USER:
                    $stage['user'] = $block->user;
                    $stage['problem'] = $stage['user'] ? null
                        : $name.' approver is not set. Ask Admin.';
                    break;

                default:
                    $stage['problem'] = $name.' approver must be a specific person, not "'
                        .(RequisitionSignatureBlock::assigneeTypeLabels()[$block->assignee_type] ?? $block->assignee_type)
                        .'". Ask Admin.';
            }

            if (! $stage['problem'] && ! $stage['skipped'] && $raiser && $stage['user']?->id === $raiser->id) {
                $stage['problem'] = 'You are the '.$name.' approver for this sheet, and nobody may approve their own requisition. Ask another person to prepare it.';
            }

            $stages[] = $stage;
        }

        return $stages;
    }

    /**
     * Why this sheet cannot be submitted yet, in words for the screen.
     *
     * @return list<string>
     */
    public function problems(string $level, ?User $raiser, ?IndentSection $section, ?string $department): array
    {
        return collect($this->plan($level, $raiser, $section, $department))
            ->pluck('problem')->filter()->unique()->values()->all();
    }

    /**
     * Start (or restart, after a return) a sheet on the stage engine: write
     * this cycle's plan and point current_stage at the first stage waiting.
     * Refuses — having written nothing — if any stage has no approver or
     * the raiser holds a stage. Called inside the submit transaction.
     */
    public function start(PurchaseRequisition $requisition): void
    {
        $raiser = $requisition->createdBy()->first();
        $section = $requisition->indentSection()->first() ?: $raiser?->requisitionSection;
        $plan = $this->plan($requisition->level, $raiser, $section, $requisition->department_role);

        $problems = collect($plan)->pluck('problem')->filter()->unique()->values()->all();

        if ($problems !== []) {
            throw ValidationException::withMessages(['workflow' => $problems]);
        }

        $cycle = (int) PurchaseRequisitionStage::where('purchase_requisition_id', $requisition->id)->max('cycle') + 1;

        // Box 1: prepared by the raiser, at submit.
        PurchaseRequisitionStage::create([
            'purchase_requisition_id' => $requisition->id,
            'cycle' => $cycle,
            'sequence' => 1,
            'label' => RequisitionSignatureBlock::query()->forLevel($requisition->level)->value('label') ?: 'Prepared by',
            'assignee_type' => RequisitionSignatureBlock::ASSIGNEE_REQUESTER,
            'assignee_user_id' => $raiser?->id,
            'assignee_name' => $raiser?->name,
            'status' => PurchaseRequisitionStage::STATUS_APPROVED,
            'acted_by' => $raiser?->id,
            'acted_by_name' => $raiser?->name,
            'acted_at' => now(),
        ]);

        foreach ($plan as $stage) {
            PurchaseRequisitionStage::create([
                'purchase_requisition_id' => $requisition->id,
                'cycle' => $cycle,
                'sequence' => $stage['sequence'],
                'label' => $stage['label'],
                'title' => $stage['title'],
                'stamp_source' => $stage['stamp_source'],
                'assignee_type' => $stage['assignee_type'],
                'assignee_user_id' => $stage['user']?->id,
                'assignee_name' => $stage['user']?->name,
                'status' => $stage['skipped'] ? PurchaseRequisitionStage::STATUS_SKIPPED : PurchaseRequisitionStage::STATUS_PENDING,
            ]);
        }

        $requisition->forceFill([
            'workflow_version' => self::VERSION,
            'current_stage' => $this->firstPending($requisition, $cycle)?->sequence,
        ])->save();
    }

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    public function activeCycle(PurchaseRequisition $requisition): int
    {
        return (int) PurchaseRequisitionStage::where('purchase_requisition_id', $requisition->id)->max('cycle');
    }

    /** @return Collection<int, PurchaseRequisitionStage> this cycle's plan, in order */
    public function stages(PurchaseRequisition $requisition): Collection
    {
        return PurchaseRequisitionStage::where('purchase_requisition_id', $requisition->id)
            ->where('cycle', $this->activeCycle($requisition))
            ->orderBy('sequence')->get();
    }

    public function currentStage(PurchaseRequisition $requisition): ?PurchaseRequisitionStage
    {
        if (! $requisition->usesStageEngine() || ! $requisition->current_stage) {
            return null;
        }

        return PurchaseRequisitionStage::where('purchase_requisition_id', $requisition->id)
            ->where('cycle', $this->activeCycle($requisition))
            ->where('sequence', $requisition->current_stage)
            ->first();
    }

    /**
     * Whether this user approves the stage the sheet waits at. Only the
     * person on the plan — not Admin, not Management, never the raiser.
     */
    public function canAct(PurchaseRequisition $requisition, ?User $user): bool
    {
        $stage = $this->currentStage($requisition);

        return $user !== null
            && $stage !== null
            && $stage->status === PurchaseRequisitionStage::STATUS_PENDING
            && (int) $stage->assignee_user_id === (int) $user->id
            && ! $requisition->isRaisedBy($user);
    }

    /*
    |--------------------------------------------------------------------------
    | Approving
    |--------------------------------------------------------------------------
    */

    /**
     * Approve the current stage and move to the next. The caller holds the
     * row lock and the transaction, and has checked canAct().
     */
    public function approve(PurchaseRequisition $requisition, User $user, ?string $comment = null): ?PurchaseRequisitionStage
    {
        $stage = $this->currentStage($requisition);
        $from = $requisition->status;

        $stage->update([
            'status' => PurchaseRequisitionStage::STATUS_APPROVED,
            'acted_by' => $user->id,
            'acted_by_name' => $user->name,
            'acted_at' => now(),
            'comment' => trim((string) $comment) ?: null,
        ]);

        $this->applyStamp($requisition, $stage->stamp_source, $user);

        $next = $this->firstPending($requisition, $stage->cycle);

        if (! $next) {
            $this->complete($requisition, $user);
        }

        $requisition->forceFill(['current_stage' => $next?->sequence])->save();

        $this->history->record($requisition, $this->actionFor($stage, $next === null, $requisition), [
            'stage' => $this->historyStage($stage, $requisition),
            'from' => $from,
            'comment' => $comment,
            'meta' => ['stage_sequence' => $stage->sequence, 'stage_label' => $stage->displayLabel()],
        ]);

        return $next;
    }

    /**
     * Send the sheet back to the PREVIOUS stage, with a reason (kept in the
     * history). The previous stage is the last one approved in this cycle —
     * a skipped stage is passed over. Its approval is undone, so it acts
     * again; what it had written on the sheet is taken back too.
     *
     * Back to "Prepared" means back to the raiser: the sheet is theirs to
     * amend and resubmit (a new cycle) or cancel. The caller holds the row
     * lock and the transaction, and has checked canAct().
     *
     * @return ?PurchaseRequisitionStage the stage now waiting, or null when the sheet is back with its raiser
     */
    public function returnToPrevious(PurchaseRequisition $requisition, User $user, string $reason): ?PurchaseRequisitionStage
    {
        $current = $this->currentStage($requisition);
        $from = $requisition->status;

        $previous = PurchaseRequisitionStage::where('purchase_requisition_id', $requisition->id)
            ->where('cycle', $current->cycle)
            ->where('sequence', '<', $current->sequence)
            ->where('status', PurchaseRequisitionStage::STATUS_APPROVED)
            ->orderByDesc('sequence')
            ->first();

        $toRaiser = ! $previous || $previous->sequence === 1;

        if ($toRaiser) {
            // A head returning the sheet keeps writing what the old flow did,
            // so the raiser's screens read "Returned by Section Head".
            if ($current->stamp_source === RequisitionSignatureBlock::STAMP_DEPARTMENT_HEAD) {
                $requisition->forceFill([
                    'department_review_status' => PurchaseRequisition::DEPT_REVIEW_REJECTED,
                    'department_review_note' => $reason,
                ] + $requisition->headDecisionStamp($user));
            }

            $requisition->forceFill(['current_stage' => 1])->save();
        } else {
            $this->undoStamp($requisition, $previous->stamp_source);

            $previous->update([
                'status' => PurchaseRequisitionStage::STATUS_PENDING,
                'acted_by' => null,
                'acted_by_name' => null,
                'acted_at' => null,
                'comment' => null,
            ]);

            $requisition->forceFill(['current_stage' => $previous->sequence])->save();
        }

        // A head returning the sheet reads "Returned by Head", as on the old flow.
        $action = $current->stamp_source === RequisitionSignatureBlock::STAMP_DEPARTMENT_HEAD
            ? PurchaseRequisitionAction::HEAD_RETURNED
            : PurchaseRequisitionAction::RETURNED;

        $this->history->record($requisition, $action, [
            'stage' => $this->historyStage($current, $requisition),
            'from' => $from,
            'comment' => $reason,
            'meta' => [
                'stage_sequence' => $current->sequence,
                'stage_label' => $current->displayLabel(),
                'returned_to' => $toRaiser ? 'Requester' : $previous->displayLabel(),
            ],
        ]);

        return $toRaiser ? null : $previous->fresh();
    }

    /**
     * The approver the sheet waits for changes line quantities at their stage
     * (Step 3d) — up or down, 0 meaning "not approved", with ONE reason for
     * the save. Only quantities: no line is added, removed or re-pointed; a
     * wrong item goes back with Return.
     *
     * The line is updated in place, so every report follows the approved
     * figure. To Be Procured and Amount are recalculated from the line's
     * FROZEN stock snapshot and rate — the snapshot is not re-read. Each
     * change is logged (purchase_requisition_qty_changes) and recorded in the
     * history. Approving is a separate click.
     *
     * The caller holds the row lock and the transaction, and has checked
     * canAct().
     *
     * @param  array<int|string, int|float|string>  $quantities  line id => new qty
     * @return int how many lines changed
     */
    public function changeQuantities(PurchaseRequisition $requisition, User $user, array $quantities, string $reason): int
    {
        $stage = $this->currentStage($requisition);
        $lines = $requisition->items()->get()->keyBy('id');

        $unknown = array_diff(array_map('intval', array_keys($quantities)), $lines->keys()->all());
        if ($unknown !== []) {
            throw ValidationException::withMessages(['qty' => 'A line on this form is not on the requisition. Reload the page and try again.']);
        }

        $new = fn ($line) => round((float) ($quantities[$line->id] ?? $line->qty_requested), 4);
        $changed = $lines->filter(fn ($line) => abs($new($line) - (float) $line->qty_requested) > 0.00001);

        if ($changed->isEmpty()) {
            throw ValidationException::withMessages(['qty' => 'No quantity was changed.']);
        }

        if ($lines->every(fn ($line) => $new($line) <= 0)) {
            throw ValidationException::withMessages(['qty' => 'Every line would be 0. Return or cancel the requisition instead.']);
        }

        $diff = [];

        foreach ($changed as $line) {
            $old = (float) $line->qty_requested;
            $qty = $new($line);
            $toBeProcured = $this->requisitions->toBeProcured($qty, $line->stock_in_hand_snapshot === null ? null : (float) $line->stock_in_hand_snapshot);

            $line->update([
                'qty_requested' => $qty,
                'to_be_procured_qty' => $toBeProcured,
                'amount' => $line->rate_appx !== null ? $toBeProcured * (float) $line->rate_appx : 0,
            ]);

            PurchaseRequisitionQtyChange::create([
                'purchase_requisition_id' => $requisition->id,
                'purchase_requisition_item_id' => $line->id,
                'cycle' => $stage->cycle,
                'stage_sequence' => $stage->sequence,
                'stage_label' => $stage->displayLabel(),
                'item_name' => $line->displayName(),
                'old_qty' => $old,
                'new_qty' => $qty,
                'reason' => $reason,
                'user_id' => $user->id,
                'user_name' => $user->name,
            ]);

            $diff[] = ['item' => $line->displayName(), 'change' => 'changed', 'old_qty' => $old, 'new_qty' => $qty];
        }

        $this->history->record($requisition, PurchaseRequisitionAction::QTY_CHANGED, [
            'stage' => $this->historyStage($stage, $requisition),
            'from' => $requisition->status,
            'comment' => $reason,
            'meta' => ['stage_sequence' => $stage->sequence, 'stage_label' => $stage->displayLabel(), 'lines' => $diff],
        ]);

        return $changed->count();
    }

    /**
     * What each line was ORIGINALLY requested at in the current round, for
     * the lines an approver has changed: line id => ['qty' => original,
     * 'stages' => "HR Audit, Finance"]. A resubmitted sheet starts a new
     * round, so the raiser's new figure is the request again.
     *
     * @return array<int, array{qty: float, stages: string}>
     */
    public function originalQuantities(PurchaseRequisition $requisition): array
    {
        if (! $requisition->usesStageEngine()) {
            return [];
        }

        return PurchaseRequisitionQtyChange::where('purchase_requisition_id', $requisition->id)
            ->where('cycle', $this->activeCycle($requisition))
            ->whereNotNull('purchase_requisition_item_id')
            ->orderBy('id')
            ->get()
            ->groupBy('purchase_requisition_item_id')
            ->map(fn ($changes) => [
                'qty' => (float) $changes->first()->old_qty,
                'stages' => $changes->pluck('stage_label')->filter()->unique()->implode(', '),
            ])
            ->all();
    }

    /**
     * Close the sheet for good, with a reason. Either flow. The caller checks
     * canBeCancelledBy(), holds the lock and the transaction.
     */
    public function cancel(PurchaseRequisition $requisition, User $user, string $reason): void
    {
        $from = $requisition->status;

        $requisition->forceFill([
            'status' => PurchaseRequisition::STATUS_CANCELLED,
            'current_stage' => null,
        ])->save();

        $this->history->record($requisition, PurchaseRequisitionAction::CANCELLED, [
            'stage' => $requisition->isRaisedBy($user) ? PurchaseRequisitionAction::STAGE_RAISER : PurchaseRequisitionAction::STAGE_ADMIN,
            'from' => $from,
            'comment' => $reason,
        ]);
    }

    /** Take back what a stage wrote on the sheet, so it can act again. */
    private function undoStamp(PurchaseRequisition $requisition, ?string $stamp): void
    {
        match ($stamp) {
            RequisitionSignatureBlock::STAMP_DEPARTMENT_HEAD => $requisition->forceFill([
                'department_review_status' => PurchaseRequisition::DEPT_REVIEW_PENDING,
            ] + PurchaseRequisition::clearedHeadStamps()),
            RequisitionSignatureBlock::STAMP_STORE_HEAD => $requisition->forceFill([
                'status' => PurchaseRequisition::STATUS_SUBMITTED,
                'store_ack_by' => null,
                'store_ack_at' => null,
            ]),
            default => null,
        };

        $requisition->save();
    }

    /** What a stage writes on the sheet, from its box's "filled from" setting. */
    private function applyStamp(PurchaseRequisition $requisition, ?string $stamp, User $user): void
    {
        match ($stamp) {
            RequisitionSignatureBlock::STAMP_DEPARTMENT_HEAD => $requisition->forceFill([
                'department_review_status' => PurchaseRequisition::DEPT_REVIEW_APPROVED,
                'department_review_note' => null,
            ] + $requisition->headDecisionStamp($user)),
            RequisitionSignatureBlock::STAMP_STORE_HEAD => $requisition->forceFill([
                'status' => PurchaseRequisition::STATUS_STORE_REVIEWED,
                'store_ack_by' => $user->id,
                'store_ack_at' => now(),
            ]),
            RequisitionSignatureBlock::STAMP_APPROVED => $requisition->forceFill([
                'status' => PurchaseRequisition::STATUS_APPROVED,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]),
            default => null,
        };

        $requisition->save();
    }

    /**
     * No stage left. A final sheet is Approved; a department sheet has
     * reached the Store and waits there to be merged. Whatever the boxes'
     * settings already wrote stands.
     */
    private function complete(PurchaseRequisition $requisition, User $user): void
    {
        if ($requisition->isDepartmentLevel()) {
            if ($requisition->status === PurchaseRequisition::STATUS_SUBMITTED) {
                $requisition->forceFill([
                    'status' => PurchaseRequisition::STATUS_STORE_REVIEWED,
                    'store_ack_by' => $requisition->store_ack_by ?? $user->id,
                    'store_ack_at' => $requisition->store_ack_at ?? now(),
                ])->save();
            }

            return;
        }

        if ($requisition->status !== PurchaseRequisition::STATUS_APPROVED) {
            $requisition->forceFill([
                'status' => PurchaseRequisition::STATUS_APPROVED,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ])->save();
        }
    }

    private function firstPending(PurchaseRequisition $requisition, int $cycle): ?PurchaseRequisitionStage
    {
        return PurchaseRequisitionStage::where('purchase_requisition_id', $requisition->id)
            ->where('cycle', $cycle)
            ->where('status', PurchaseRequisitionStage::STATUS_PENDING)
            ->orderBy('sequence')
            ->first();
    }

    private function actionFor(PurchaseRequisitionStage $stage, bool $last, PurchaseRequisition $requisition): string
    {
        if ($stage->stamp_source === RequisitionSignatureBlock::STAMP_DEPARTMENT_HEAD) {
            return PurchaseRequisitionAction::HEAD_APPROVED;
        }

        if ($last && ! $requisition->isDepartmentLevel()) {
            return PurchaseRequisitionAction::APPROVED;
        }

        return PurchaseRequisitionAction::STAGE_APPROVED;
    }

    private function historyStage(PurchaseRequisitionStage $stage, PurchaseRequisition $requisition): string
    {
        return match (true) {
            $stage->stamp_source === RequisitionSignatureBlock::STAMP_DEPARTMENT_HEAD => RequisitionHistory::headStage($requisition),
            $stage->assignee_type === RequisitionSignatureBlock::ASSIGNEE_STORE_HEAD,
            $stage->stamp_source === RequisitionSignatureBlock::STAMP_STORE_HEAD => PurchaseRequisitionAction::STAGE_STORE,
            default => PurchaseRequisitionAction::STAGE_APPROVER,
        };
    }

    /** "HR Head" / "Store Head" / "Checked by" — how a stage is named in messages. */
    private function stageName(RequisitionSignatureBlock $block): string
    {
        return trim((string) $block->title) ?: $block->label;
    }
}
