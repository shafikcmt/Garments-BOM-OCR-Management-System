<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierAuditLog extends Model
{
    protected $fillable = [
        'supplier_id', 'entity', 'entity_id', 'action', 'field', 'old_value', 'new_value',
        'approval_status', 'changed_by', 'approved_by', 'approved_at', 'remarks',
    ];

    protected $casts = ['approved_at' => 'datetime'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
