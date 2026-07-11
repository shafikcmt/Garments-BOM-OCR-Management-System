<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockPurchase extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_item_id',
        'challan_no',
        'purchase_date',
        'qty',
        'unit_price',
        'supplier_name',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'qty' => 'decimal:4',
        'unit_price' => 'decimal:4',
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
