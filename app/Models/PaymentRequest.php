<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentRequest extends Model
{
    use \App\Models\Concerns\LinksVendor;

    use HasFactory;

    // Existing statuses ('preview', 'draft') are unchanged. These are the
    // additional states used by the digital approval flow.
    public const STATUS_PENDING_CHECK = 'pending_check';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    // A PRA whose lines were moved into a merged PRA. It keeps its number and
    // history but holds no lines and no amount, and takes no further part in
    // approval.
    public const STATUS_MERGED = 'merged';

    // Flow 2 only. The departmental chain ends in a PAYMENT, not an approval,
    // so it needs a state the legacy flow has no concept of. No flow-1 PRA can
    // ever hold either value — nothing writes them outside PraWorkflow.
    public const STATUS_PENDING_COMPLETION = 'pending_completion';
    public const STATUS_COMPLETED = 'completed';

    /**
     * WHICH APPROVAL MACHINE OWNS THIS PRA.
     *
     *   1  the legacy optional Check -> Approve flow, reviewers hand-picked from
     *      the pra_approvers pool. Every PRA that existed before the departmental
     *      chain is this, and its behaviour must never change.
     *   2  the 6-step departmental chain driven by PraWorkflow.
     *
     * This is the isolation boundary. PraWorkflow refuses a version-1 PRA
     * outright rather than trying to be clever about it.
     */
    public const FLOW_LEGACY = 1;

    public const FLOW_DEPARTMENTAL = 2;

    protected $fillable = [
        'supplier_id',
        'request_no',
        'supplier_name',
        'buyer_name',
        'season_name',
        'total_pi_amount',
        'status',
        'flow_version',
        'current_sequence',
        'created_by',
        'checked_by',
        'approved_by',
        'checked_at',
        'approved_at',
        'prepared_signature_path',
        'remarks',
        'data',
    ];

    protected $casts = [
        'total_pi_amount' => 'decimal:4',
        'checked_at' => 'datetime',
        'approved_at' => 'datetime',
        'flow_version' => 'integer',
        'current_sequence' => 'integer',
        'data' => 'array',
    ];

    /**
     * The database default is 1, but a database default only lands on the ROW —
     * a freshly created model holds null for it until something reloads. Code
     * reading flow_version straight after create() would see null and, worse,
     * a `=== FLOW_LEGACY` test would come out false for a PRA that is plainly
     * legacy. Mirrors how PraApproval defaults `stage`.
     */
    protected $attributes = [
        'flow_version' => self::FLOW_LEGACY,
    ];

    /**
     * Whether the 6-step departmental chain owns this PRA.
     *
     * THE ONE CHECK EVERY SHARED CODE PATH MUST MAKE before reading approval
     * state. approvalProgress() below, the approver queue and the PDF all
     * describe flow 1 and would misreport a flow-2 PRA — not because they are
     * wrong, but because they answer a question flow 2 does not ask.
     */
    /**
     * Supporting documents (PI copy, etc.) uploaded when the PRA was created.
     * Stored in the existing `data` JSON under `attachments`, so no schema change.
     *
     * @return array<int, array{name:string,path:string,size:int,mime:?string,uploaded_by:?int,uploaded_at:?string}>
     */
    public function attachments(): array
    {
        return array_values((array) data_get($this->data, 'attachments', []));
    }

    /**
     * Whether this PRA can still be merged with others: a departmental PRA that
     * is waiting at its first step and that nobody has acted on yet. Once any
     * decision exists, merging would rewrite a document someone already signed.
     * Needs `approvals` loaded to avoid a query per row.
     */
    public function isMergeable(): bool
    {
        return $this->isDepartmentalFlow()
            && $this->status === self::STATUS_PENDING_CHECK
            && (int) $this->current_sequence === \App\Services\PraStageResolver::FIRST_SEQUENCE
            && $this->approvals->isEmpty();
    }

    public function isDepartmentalFlow(): bool
    {
        return (int) $this->flow_version === self::FLOW_DEPARTMENTAL;
    }

    /**
     * Progress in the shape every existing view already reads, for EITHER flow.
     *
     * approvalProgress() below is left exactly as it was — it is flow-1 logic
     * and 19 live PRAs depend on it. This wraps it rather than branching inside
     * it, so a flow-1 PRA still travels the identical code path and gets the
     * identical array, byte for byte.
     *
     * The departmental branch returns the same keys because the views consume
     * `state`, `label` and `has_flow` and should not have to care which machine
     * produced them.
     *
     * @return array<string, mixed>
     */
    public function flowProgress(): array
    {
        if (! $this->isDepartmentalFlow()) {
            return $this->approvalProgress();
        }

        $label = match ($this->status) {
            self::STATUS_COMPLETED => 'Completed — payment recorded',
            self::STATUS_REJECTED => 'Rejected — returned to creator',
            self::STATUS_MERGED => 'Merged into another PRA',
            default => $this->current_sequence
                ? 'Step ' . $this->current_sequence . ' — '
                    . app(\App\Services\PraStageResolver::class)
                        ->definitionFor((int) $this->current_sequence)['label']
                : ucfirst(str_replace('_', ' ', (string) $this->status)),
        };

        return [
            'total' => 0, 'approved' => 0, 'rejected' => 0, 'pending' => 0,
            'has_check' => false, 'check_done' => false, 'check_pending' => false,
            'stage' => $this->current_sequence ? 'step_' . $this->current_sequence : 'done',
            'state' => $this->status,
            'label' => $label,
            'has_flow' => true,
        ];
    }

    public function items()
    {
        return $this->hasMany(PaymentRequestItem::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function checkedBy()
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function approvals()
    {
        return $this->hasMany(PraApproval::class);
    }

    /**
     * Flow 2 only: the optional primary assignee per step, keyed by sequence.
     * Empty for every legacy PRA and for any departmental PRA whose creator
     * left routing fully automatic. Informational — see PraStageAssignment.
     */
    public function stageAssignments()
    {
        return $this->hasMany(PraStageAssignment::class);
    }

    /**
     * The highest approval cycle number for this PRA (0 when it has never been
     * sent for approval). Each "resubmit" increments the cycle.
     */
    public function currentCycle(): int
    {
        return (int) $this->approvals->max('cycle');
    }

    /**
     * Approval rows for the current (latest) cycle only. Older cycles remain in
     * the table as audit history but are excluded here.
     */
    public function currentApprovals()
    {
        $cycle = $this->currentCycle();

        return $this->approvals->where('cycle', $cycle)->values();
    }

    /**
     * The current-cycle "Checker" row, or null when the PRA has no check step.
     * By design there is at most one checker per cycle.
     */
    public function currentCheckApproval(): ?PraApproval
    {
        return $this->currentApprovals()
            ->firstWhere('stage', PraApproval::STAGE_CHECK);
    }

    /**
     * The current-cycle approver rows (excludes the check step). Legacy PRAs
     * created before the sequential flow have their approver rows here too, as
     * `stage` defaults to 'approve'.
     */
    public function currentApproveApprovals()
    {
        return $this->currentApprovals()
            ->where('stage', PraApproval::STAGE_APPROVE)
            ->values();
    }

    /**
     * Progress snapshot for the current cycle, aware of the sequential
     * Check -> Approve stages. The `total` / `approved` / `pending` counts refer
     * to the approver (approve) stage so existing approver-panel views keep
     * working; separate `check_*` keys describe the optional check step.
     *
     * State machine (current cycle):
     *   any rejected                         -> rejected
     *   checker row still pending            -> pending_check
     *   approver rows not all approved       -> pending_approval
     *   all approvers approved (checker ok)  -> approved
     *   checker-only, checker approved       -> approved
     *
     * @return array<string, mixed>
     */
    public function approvalProgress(): array
    {
        $current = $this->currentApprovals();

        if ($current->isEmpty()) {
            return [
                'total' => 0, 'approved' => 0, 'rejected' => 0, 'pending' => 0,
                'has_check' => false, 'check_done' => false, 'check_pending' => false,
                'stage' => 'none', 'state' => 'none',
                'label' => 'Not sent for approval', 'has_flow' => false,
            ];
        }

        $check = $current->firstWhere('stage', PraApproval::STAGE_CHECK);
        $approveRows = $current->where('stage', PraApproval::STAGE_APPROVE)->values();

        $total = $approveRows->count();
        $approved = $approveRows->where('status', PraApproval::STATUS_APPROVED)->count();
        $pending = $approveRows->where('status', PraApproval::STATUS_PENDING)->count();
        $rejected = $current->where('status', PraApproval::STATUS_REJECTED)->count();

        $hasCheck = (bool) $check;
        $checkDone = $check && $check->isApproved();
        $checkPending = $check && $check->isPending();

        if ($rejected > 0) {
            $state = self::STATUS_REJECTED;
            $stage = 'done';
            $label = 'Rejected';
        } elseif ($checkPending) {
            $state = self::STATUS_PENDING_CHECK;
            $stage = PraApproval::STAGE_CHECK;
            $label = 'Pending Check';
        } elseif ($total > 0 && $approved < $total) {
            $state = self::STATUS_PENDING_APPROVAL;
            $stage = PraApproval::STAGE_APPROVE;
            $label = 'Pending Approval (' . $approved . ' of ' . $total . ' approved)';
        } else {
            // All approvers approved, or a checker-only PRA whose checker approved.
            $state = self::STATUS_APPROVED;
            $stage = 'done';
            $label = 'Approved';
        }

        return [
            'total' => $total,
            'approved' => $approved,
            'rejected' => $rejected,
            'pending' => $pending,
            'has_check' => $hasCheck,
            'check_done' => $checkDone,
            'check_pending' => $checkPending,
            'stage' => $stage,
            'state' => $state,
            'label' => $label,
            'has_flow' => true,
        ];
    }
}
