<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierBusinessUnit extends Model
{
    protected $fillable = ['supplier_id', 'business_unit_id', 'status', 'effective_date', 'expiry_date'];

    protected $casts = ['effective_date' => 'date', 'expiry_date' => 'date'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function businessUnit()
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    /** Active today: status active and inside the effective / expiry window. */
    public function isCurrent(): bool
    {
        $today = now()->startOfDay();

        return $this->status === 'active'
            && ($this->effective_date === null || $this->effective_date->lte($today))
            && ($this->expiry_date === null || $this->expiry_date->gte($today));
    }
}
