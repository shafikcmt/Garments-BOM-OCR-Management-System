<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockIssue extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_item_id',
        'is_stock_item',
        'item_description',
        'requisition_no',
        'issue_date',
        'qty',
        'issued_to',
        'department',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'is_stock_item' => 'boolean',
        'issue_date' => 'date',
        'qty' => 'decimal:4',
    ];

    public function stockItem()
    {
        return $this->belongsTo(StockItem::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
