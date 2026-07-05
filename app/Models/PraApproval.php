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

    protected $fillable = [
        'payment_request_id',
        'approver_id',
        'cycle',
        'status',
        'comment',
        'acted_at',
    ];

    protected $casts = [
        'cycle' => 'integer',
        'acted_at' => 'datetime',
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
}
