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
        // Historical Last Addition / Last Consumption dates for an item
        // carried over from the Excel sheets. A FALLBACK only: the Stock
        // Report shows the real transaction-derived date whenever one
        // exists and reads these solely when it does not. See the
        // migration that adds them for why the derived columns cannot be
        // written to directly.
        'last_addition_reference',
        'last_consumption_reference',
        'safety_stock_qty',
        'reorder_level',
        'lead_time_days',
        'is_active',
        'remarks',
        'created_by',
    ];

    /**
     * The SKU is NOT fillable: no form, import or mass update can set it.
     * It is given once on create (StockItemSku::next) and changed only by an
     * Admin correction (StockItemSku::correct), which raises this flag.
     */
    public static bool $skuCorrection = false;

    protected static function booted(): void
    {
        // Auto-generated on create, race-safe (see StockItemSku).
        static::creating(function (StockItem $item) {
            if (blank($item->sku)) {
                $item->sku = app(\App\Services\StockItemSku::class)->next($item);
            }
        });

        // Never changes once set — a rename, a category move or a format
        // change leave it alone. Only the Admin correction gets through.
        static::updating(function (StockItem $item) {
            if ($item->isDirty('sku') && $item->getOriginal('sku') !== null && ! static::$skuCorrection) {
                $item->sku = $item->getOriginal('sku');
            }
        });
    }

    protected $casts = [
        'opening_qty' => 'decimal:4',
        'opening_as_on' => 'date',
        'unit_price' => 'decimal:4',
        'last_addition_reference' => 'date',
        'last_consumption_reference' => 'date',
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
