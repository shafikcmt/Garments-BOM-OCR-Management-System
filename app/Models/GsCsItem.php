<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One item line of a Comparative Statement. */
class GsCsItem extends Model
{
    protected $table = 'gs_cs_items';

    protected $fillable = [
        'gs_comparative_statement_id', 'purchase_requisition_item_id', 'stock_item_id', 'item_name', 'category',
        'uom', 'qty', 'specification', 'current_supplier', 'selected_supplier_id', 'remarks', 'sort_order',
    ];

    protected $casts = ['qty' => 'decimal:4'];

    public function statement()
    {
        return $this->belongsTo(GsComparativeStatement::class, 'gs_comparative_statement_id');
    }

    public function quotes()
    {
        return $this->hasMany(GsCsQuote::class, 'gs_cs_item_id');
    }

    public function requisitionItem()
    {
        return $this->belongsTo(PurchaseRequisitionItem::class, 'purchase_requisition_item_id');
    }

    public function quoteFor(int $supplierId): ?GsCsQuote
    {
        return $this->quotes->firstWhere('gs_cs_supplier_id', $supplierId);
    }

    /** The quote of the supplier chosen for this line, if any. */
    public function selectedQuote(): ?GsCsQuote
    {
        return $this->selected_supplier_id
            ? $this->quoteFor((int) $this->selected_supplier_id)
            : null;
    }

    /** The supplier with the lowest price on this line, or null when nobody quoted. */
    public function lowestSupplierId(): ?int
    {
        $best = $this->quotes
            ->filter(fn (GsCsQuote $q) => $q->unit_price !== null)
            ->sortBy(fn (GsCsQuote $q) => (float) $q->unit_price)
            ->first();

        return $best ? (int) $best->gs_cs_supplier_id : null;
    }
}
