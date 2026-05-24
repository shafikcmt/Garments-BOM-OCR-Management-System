<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentRequest extends Model
{
    use HasFactory;

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
}
