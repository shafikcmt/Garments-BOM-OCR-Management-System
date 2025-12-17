<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $table = 'payments';

    /**
     * Mass assignable fields
     */
    protected $fillable = [
        'order_id',
        'payment_term',
        'document_no',
        'amount',
        'status',
        'paid_at',
    ];

    /**
     * Date casting
     */
    protected $casts = [
        'paid_at' => 'datetime',
    ];

    /**
     * Relationship: Payment belongs to Order
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
