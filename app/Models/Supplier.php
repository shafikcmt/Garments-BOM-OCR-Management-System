<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'supplier_code',
        'supplier_name',
        'legal_name',
        'contact_person',
        'email',
        'phone',
        'address',
        'city',
        'country',
        'item_type',
        'incoterm',
        'ship_mode',
        'tolerance_percent',
        'is_active',
    ];

    protected $casts = [
        'tolerance_percent' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    public function getDisplayNameAttribute()
    {
        return $this->legal_name ?: $this->supplier_name;
    }

    public function getFullAddressAttribute()
    {
        return collect([
            $this->address,
            $this->city,
            $this->country,
        ])->filter()->implode(', ');
    }
}