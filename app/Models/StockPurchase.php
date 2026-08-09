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
        'rv_no',
        // Challan Date — the supplier's document date, and what the Consumable
        // Stock Report keys its Addition totals and last-addition date on.
        'purchase_date',
        // RCV Date — when the goods physically reached the store.
        'rcv_date',
        'qty',
        'unit_price',
        'supplier_name',
        'general_stock_supplier_id',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'rcv_date' => 'date',
        'qty' => 'decimal:4',
        'unit_price' => 'decimal:4',
    ];

    /**
     * Total Value on the Excel "Purchase" sheet. Derived, never stored, so it
     * can never drift out of step with qty × unit price.
     */
    public function getTotalValueAttribute(): float
    {
        return (float) $this->qty * (float) ($this->unit_price ?? 0);
    }

    /** "Month" on the Excel sheet — derived from the challan date. */
    public function getMonthLabelAttribute(): ?string
    {
        return $this->purchase_date?->format('M-y');
    }

    public function stockItem()
    {
        return $this->belongsTo(StockItem::class);
    }

    /**
     * General Stock's own supplier list — deliberately not the Buyer/Style
     * `Supplier` model, which is a separate list for a separate purpose.
     */
    public function generalStockSupplier()
    {
        return $this->belongsTo(GeneralStockSupplier::class, 'general_stock_supplier_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
