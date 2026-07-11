<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'uom',
        'category',
        'safety_stock_qty',
        'reorder_level',
        'lead_time_days',
        'is_active',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'safety_stock_qty' => 'decimal:4',
        'reorder_level' => 'decimal:4',
        'lead_time_days' => 'integer',
        'is_active' => 'boolean',
    ];

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
