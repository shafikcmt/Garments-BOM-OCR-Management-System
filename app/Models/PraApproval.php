<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single approver's decision for a PRA within one approval cycle.
 */
class PraApproval extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    // Sequential approval stages. Legacy rows (created before this feature) and
    // any plain approver row default to STAGE_APPROVE.
    public const STAGE_CHECK = 'check';
    public const STAGE_APPROVE = 'approve';

    // Flow 2 only — the final departmental step, which is a payment completion
    // rather than an approval. Never written by the legacy flow.
    public const STAGE_COMPLETE = 'complete';

    // Which TIER of a department acted. Null on every legacy row, because flow 1
    // routes to hand-picked individuals and has no notion of tiers.
    public const ACTOR_TEAM = 'team';
    public const ACTOR_HEAD = 'head';

    // sequence 0 marks a legacy (flow 1) row. See the migration
    // 2026_09_09_000002 for why it is 0 and not null.
    public const SEQUENCE_LEGACY = 0;

    protected $fillable = [
        'payment_request_id',
        'approver_id',
        // Snapshot of who the step was pointed at when this decision was taken.
        // Null on every legacy row and on every step nobody assigned.
        'assigned_user_id',
        'cycle',
        'sequence',
        'actor_kind',
        'department',
        'stage',
        'status',
        'comment',
        'signature_path',
        'acted_at',
    ];

    protected $casts = [
        'cycle' => 'integer',
        'sequence' => 'integer',
        'acted_at' => 'datetime',
    ];

    protected $attributes = [
        'stage' => self::STAGE_APPROVE,
    ];

    public function paymentRequest()
    {
        return $this->belongsTo(PaymentRequest::class, 'payment_request_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    /**
     * The person this step had been assigned to when the decision was taken —
     * not necessarily the one who took it. Null when the step was routed to the
     * whole eligible set with nobody singled out.
     */
    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isCheckStage(): bool
    {
        return $this->stage === self::STAGE_CHECK;
    }

    public function isApproveStage(): bool
    {
        return $this->stage === self::STAGE_APPROVE;
    }
}
