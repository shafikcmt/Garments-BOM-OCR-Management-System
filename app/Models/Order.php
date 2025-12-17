<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number',
        'buyer_name',
        'season_name',
        'style_name',
        'quantity',
        'contract_number',
        'shipment_date',
        'shipment_month',
        'status',
        'created_by',
        'approved_by',
    ];

    /**
     * Relationship: Admin who created the order
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Relationship: User who approved the order
     */
    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
