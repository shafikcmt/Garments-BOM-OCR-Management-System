<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Vendor-item link: which vendor supplies which stock item, with item-level MOQ and lead time. */
class SupplierItem extends Model
{
    protected $fillable = ['supplier_id', 'stock_item_id', 'item_type', 'item_name', 'supplier_item_code', 'lead_time_days', 'moq', 'is_preferred', 'status'];

    protected $casts = ['moq' => 'decimal:3', 'is_preferred' => 'boolean'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function stockItem()
    {
        return $this->belongsTo(StockItem::class);
    }
}
