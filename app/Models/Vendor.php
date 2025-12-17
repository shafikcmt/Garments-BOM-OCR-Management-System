<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    protected $table = 'vendors';

    /**
     * Mass assignable fields
     */
    protected $fillable = [
        'vendor_name',
        'vendor_type',
        'consolidator_name',
        'contact_email',
        'contact_phone',
        'address',
        'status',
    ];

    /**
     * Casts
     */
    protected $casts = [
        'status' => 'boolean',
    ];

    /**
     * Scope: active vendors only
     */
    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }
}
