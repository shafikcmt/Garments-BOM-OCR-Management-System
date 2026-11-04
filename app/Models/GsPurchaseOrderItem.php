<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One item line of an Internal PO. */
class GsPurchaseOrderItem extends Model
{
    protected $table = 'gs_purchase_order_items';

    protected $fillable = [
        'gs_purchase_order_id', 'gs_cs_item_id', 'purchase_requisition_item_id', 'stock_item_id',
        'item_name', 'uom', 'qty', 'rate', 'amount', 'due_on', 'remarks', 'sort_order',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'rate' => 'decimal:4',
        'amount' => 'decimal:4',
    ];

    public function order()
    {
        return $this->belongsTo(GsPurchaseOrder::class, 'gs_purchase_order_id');
    }

    public function stockItem()
    {
        return $this->belongsTo(StockItem::class);
    }

    public function receivings()
    {
        return $this->hasMany(StockPurchase::class, 'gs_purchase_order_item_id');
    }
}
