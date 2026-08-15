<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * General Stock's own supplier list — the local vendors the store buys
 * consumables from.
 *
 * Deliberately NOT the Buyer/Style `Supplier` model. That one holds nominated
 * fabric and trim suppliers tied to bookings, with incoterms, ship modes and
 * tolerances; this one is a plain vendor list. They are different business
 * relationships and share no table and no data.
 */
class GeneralStockSupplier extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Name, plus the contact details the store needs to reach a vendor before
     * placing a purchase. All optional - Name is the only thing required to
     * add a supplier, and the list predates these fields.
     */
    protected $fillable = [
        'name',
        'contact_person',
        'phone',
        'email',
        'address',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function purchases()
    {
        return $this->hasMany(StockPurchase::class, 'general_stock_supplier_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Rows offered in the Record Purchase dropdown: active, alphabetical. */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('name');
    }
}
