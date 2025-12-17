<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false; // Using only created_at

    protected $table = 'audit_logs';

    /**
     * Mass assignable fields
     */
    protected $fillable = [
        'order_id',
        'field_id',
        'old_value',
        'new_value',
        'action',
        'user_id',
        'created_at',
    ];

    /**
     * Relationships
     */

    // Log belongs to an Order
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    // Log belongs to a Field
    public function field()
    {
        return $this->belongsTo(OrderField::class, 'field_id');
    }

    // Log performed by a User
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
