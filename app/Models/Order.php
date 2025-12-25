<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'buyer_name',
        'division',
        'season_name',
        'order_status',
        'order_category',
        'product_type',
        'style_name',
        'po_number',
        'description',
        'wash_type',
        'order_qty',
        'sewing_qty',
        'smv',
        'total_minutes',
        'fob',
        'sales_value',
        'gm',
        'destination',
        'pcd',
        'x_fty',
        'x_country',
        'original_x_fty',
        'original_x_country',
        'shipment_status',
        'fabric_booking_status',
        'remarks',
        'status',
        'created_by',
        'approved_by',
    ];

    protected $casts = [
        'pcd' => 'date',
        'x_fty' => 'date',
        'x_country' => 'date',
        'original_x_fty' => 'date',
        'original_x_country' => 'date',
        'smv' => 'decimal:2',
        'total_minutes' => 'decimal:2',
        'fob' => 'decimal:2',
        'sales_value' => 'decimal:2',
        'gm' => 'decimal:2',
    ];

    // Relationships
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // Model events for auto-calculation
    protected static function booted()
    {
        static::creating(function ($order) {
            $order->total_minutes = ($order->order_qty * $order->smv);
            $order->sales_value = ($order->order_qty * $order->fob);

            if ($order->x_fty) {
                $order->pcd = now()->parse($order->x_fty)->subDays(45)->format('d-m-Y');
                $order->x_country = now()->parse($order->x_fty)->addDays(2)->format('d-m-Y');
            }

            if ($order->original_x_fty) {
                $order->original_x_country = now()->parse($order->original_x_fty)->addDays(2)->format('d-m-Y');
            }
        });

        static::updating(function ($order) {
            $order->total_minutes = ($order->order_qty * $order->smv);
            $order->sales_value = ($order->order_qty * $order->fob);

            if ($order->x_fty) {
                $order->pcd = now()->parse($order->x_fty)->subDays(45)->format('d-m-Y');
                $order->x_country = now()->parse($order->x_fty)->addDays(2)->format('d-m-Y');
            }

            if ($order->original_x_fty) {
                $order->original_x_country = now()->parse($order->original_x_fty)->addDays(2)->format('d-m-Y');
            }
        });
    }
}
