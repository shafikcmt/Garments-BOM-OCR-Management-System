<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    /**
     * Mass assignable fields
     */
    protected $fillable = [

        // BASIC INFO
        'buyer_name',
        'division',
        'season_name',
        'order_status',
        'order_category',
        'product_type',

        // STYLE & PO
        'style_name',
        'po_number',
        'description',
        'wash_type',

        // QUANTITY
        'order_qty',
        'sewing_qty',
        'balance_to_sewing',

        // PRODUCTION METRICS
        'smv',
        'total_minutes',

        // COMMERCIAL
        'fob',
        'sales_value',
        'gm',
        'destination',

        // DATES
        'pcd',
        'x_fty',
        'x_country',
        'original_x_fty',
        'original_x_country',

        // STATUS
        'shipment_status',
        'fabric_booking_status',

        // REMARKS
        'remarks',

        // SYSTEM
        'status',
        'created_by',
        'approved_by',
    ];

    /**
     * Attribute casting
     */
    protected $casts = [
        'pcd'                 => 'date',
        'x_fty'               => 'date',
        'x_country'           => 'date',
        'original_x_fty'      => 'date',
        'original_x_country'  => 'date',

        'smv'            => 'decimal:2',
        'total_minutes'  => 'decimal:2',
        'fob'            => 'decimal:2',
        'sales_value'    => 'decimal:2',
        'gm'             => 'decimal:2',
    ];

    /**
     * Relationships
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Optional computed helpers (recommended)
     */
    public function getCalculatedBalanceToSewingAttribute()
    {
        return $this->order_qty - $this->sewing_qty;
    }

    public function getCalculatedSalesValueAttribute()
    {
        return $this->order_qty * $this->fob;
    }
}
