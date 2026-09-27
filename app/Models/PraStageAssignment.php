<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The optional "primary assignee" of one step of a departmental (flow-2) PRA.
 *
 * INFORMATIONAL ONLY. PraStageResolver decides who may act; this row names the
 * one eligible person the creator pointed at, so the screen can put them first
 * and the notification can address them personally. It never narrows the
 * eligible set — any other eligible team member can still carry the step.
 *
 * One row per (payment_request_id, sequence); reassigning replaces it. Written
 * only when a creator explicitly chooses someone — nothing creates these rows
 * automatically, and a step without one is routed exactly as before.
 */
class PraStageAssignment extends Model
{
    protected $fillable = [
        'payment_request_id',
        'sequence',
        'user_id',
        'assigned_by',
    ];

    protected $casts = [
        'sequence' => 'integer',
    ];

    public function paymentRequest()
    {
        return $this->belongsTo(PaymentRequest::class, 'payment_request_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
