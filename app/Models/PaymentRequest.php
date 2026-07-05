<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentRequest extends Model
{
    use HasFactory;

    // Existing statuses ('preview', 'draft') are unchanged. These are the
    // additional states used by the digital approval flow.
    public const STATUS_PENDING_CHECK = 'pending_check';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'request_no',
        'supplier_name',
        'buyer_name',
        'season_name',
        'total_pi_amount',
        'status',
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
        'data' => 'array',
    ];

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
