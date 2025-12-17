<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shipment extends Model
{
    protected $table = 'shipments';

    /**
     * Mass assignable fields
     */
    protected $fillable = [
        'order_id',
        'ship_mode',
        'container_type',
        'container_number',
        'bl_awb_no',
        'vessel_name',
        'etd',
        'eta',
        'ata',
        'status',
        'remarks',
    ];

    /**
     * Date casting
     */
    protected $casts = [
        'etd' => 'date',
        'eta' => 'date',
        'ata' => 'date',
    ];

    /**
     * Relationship: Shipment belongs to Order
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
