<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentRequest extends Model
{
    use HasFactory;

    // Existing statuses ('preview', 'draft') are unchanged. These are the
    // additional states used by the digital approval flow.
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
     * Progress snapshot for the current cycle:
     * total / approved / rejected / pending counts, a derived state and a
     * human-friendly label. Relies on the `approvals` relation being loaded.
     *
     * @return array{total:int, approved:int, rejected:int, pending:int, state:string, label:string, has_flow:bool}
     */
    public function approvalProgress(): array
    {
        $current = $this->currentApprovals();
        $total = $current->count();
        $approved = $current->where('status', PraApproval::STATUS_APPROVED)->count();
        $rejected = $current->where('status', PraApproval::STATUS_REJECTED)->count();
        $pending = $current->where('status', PraApproval::STATUS_PENDING)->count();

        if ($total === 0) {
            return [
                'total' => 0, 'approved' => 0, 'rejected' => 0, 'pending' => 0,
                'state' => 'none', 'label' => 'Not sent for approval', 'has_flow' => false,
            ];
        }

        if ($rejected > 0) {
            $state = self::STATUS_REJECTED;
            $label = 'Rejected';
        } elseif ($approved === $total) {
            $state = self::STATUS_APPROVED;
            $label = 'Approved';
        } else {
            $state = self::STATUS_PENDING_APPROVAL;
            $label = 'Pending Approval (' . $approved . ' of ' . $total . ' approved)';
        }

        return [
            'total' => $total,
            'approved' => $approved,
            'rejected' => $rejected,
            'pending' => $pending,
            'state' => $state,
            'label' => $label,
            'has_flow' => true,
        ];
    }
}
