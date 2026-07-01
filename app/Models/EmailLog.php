<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmailLog extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'payment_request_id',
        'booking_po_id',
        'recipients',
        'cc',
        'subject',
        'body',
        'sent_by',
        'status',
        'error',
    ];

    public function paymentRequest()
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    public function bookingPo()
    {
        return $this->belongsTo(BookingPo::class);
    }

    public function sentBy()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /**
     * A log entry may be removed (soft-deleted) by an admin or by the user
     * who originally sent it. Everyone else has view/forward/reply only.
     */
    public function canBeDeletedBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $user->hasRole('admin') || (int) $this->sent_by === (int) $user->id;
    }
}
