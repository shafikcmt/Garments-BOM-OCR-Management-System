<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'brand',
        'size',
        'specification',
        'uom',
        // `category` is the readable snapshot of the selected master's name,
        // kept because the Stock Report, its exports, the search and the
        // category filter all read it. item_category_id is the real link.
        'category',
        'item_category_id',
        'opening_qty',
        'opening_as_on',
        // Standard price per unit. Deliberately NOT what the Stock Report's
        // Value column reads — that takes the price off the most recent
        // challan, as the Excel did. This is the master figure a later change
        // can fall back to for an item that has never been received with one.
        'unit_price',
        'safety_stock_qty',
        'reorder_level',
        'lead_time_days',
        'is_active',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'opening_qty' => 'decimal:4',
        'opening_as_on' => 'date',
        'unit_price' => 'decimal:4',
        'safety_stock_qty' => 'decimal:4',
        'reorder_level' => 'decimal:4',
        'lead_time_days' => 'integer',
        'is_active' => 'boolean',
    ];

    public function itemCategory()
    {
        return $this->belongsTo(ItemCategory::class, 'item_category_id');
    }

    public function purchases()
    {
        return $this->hasMany(StockPurchase::class);
    }

    public function issues()
    {
        return $this->hasMany(StockIssue::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
