<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * General Stock (module A) — one item line of a Purchase Requisition.
 *
 * The *_snapshot fields are frozen copies of numbers that GeneralStockReportService
 * derives live. They are stored, not re-derived, because a requisition is a
 * document: it has to keep showing the stock position that justified it on the
 * day it was raised. See the migration for the full reasoning.
 */
class PurchaseRequisitionItem extends Model
{
    use HasFactory;

    public const TYPE_REPLACE = 'replace';
    public const TYPE_NEW = 'new';

    protected $fillable = [
        'purchase_requisition_id',
        'stock_item_id',
        'item_category_id',
        'sort_order',
        'uom_snapshot',
        'specification',
        'type',
        'user_dept',
        'qty_requested',
        'stock_in_hand_snapshot',
        'safety_stock_snapshot',
        'consumption_last_month_snapshot',
        'last_purchase_qty_snapshot',
        'last_purchase_rate_snapshot',
        'last_purchase_date_snapshot',
        'to_be_procured_qty',
        'rate_appx',
        'amount',
        'store_pending_qty',
        'accounts_pending_qty',
        'remarks',
    ];

    protected $casts = [
        'qty_requested' => 'decimal:4',
        'stock_in_hand_snapshot' => 'decimal:4',
        'safety_stock_snapshot' => 'decimal:4',
        'consumption_last_month_snapshot' => 'decimal:4',
        'last_purchase_qty_snapshot' => 'decimal:4',
        'last_purchase_rate_snapshot' => 'decimal:4',
        'last_purchase_date_snapshot' => 'date',
        'to_be_procured_qty' => 'decimal:4',
        'rate_appx' => 'decimal:4',
        'amount' => 'decimal:4',
        'store_pending_qty' => 'decimal:4',
        'accounts_pending_qty' => 'decimal:4',
        'sort_order' => 'integer',
    ];

    /** @return array<string, string> type => label */
    public static function typeLabels(): array
    {
        return [
            self::TYPE_REPLACE => 'Replace',
            self::TYPE_NEW => 'New',
        ];
    }

    public function requisition()
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    public function stockItem()
    {
        return $this->belongsTo(StockItem::class);
    }

    public function itemCategory()
    {
        return $this->belongsTo(ItemCategory::class);
    }
}
