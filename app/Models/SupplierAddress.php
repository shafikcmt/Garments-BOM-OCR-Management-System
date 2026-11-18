<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierAddress extends Model
{
    public const TYPES = ['registered' => 'Registered', 'office' => 'Office', 'factory' => 'Factory', 'warehouse' => 'Warehouse'];

    protected $fillable = ['supplier_id', 'address_type', 'address', 'city', 'state', 'postal_code', 'country', 'is_primary'];

    protected $casts = ['is_primary' => 'boolean'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}
