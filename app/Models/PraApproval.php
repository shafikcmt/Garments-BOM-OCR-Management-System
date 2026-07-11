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

    protected $fillable = [
        'payment_request_id',
        'approver_id',
        'cycle',
        'stage',
        'status',
        'comment',
        'signature_path',
        'acted_at',
    ];

    protected $casts = [
        'cycle' => 'integer',
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
