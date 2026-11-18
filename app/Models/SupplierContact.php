<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierContact extends Model
{
    public const TYPES = ['general' => 'General', 'sales' => 'Sales', 'accounts' => 'Accounts', 'shipping' => 'Shipping', 'technical' => 'Technical'];

    protected $fillable = ['supplier_id', 'name', 'designation', 'email', 'phone', 'contact_type', 'is_primary', 'status'];

    protected $casts = ['is_primary' => 'boolean'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}
