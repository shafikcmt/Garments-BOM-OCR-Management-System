<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * General Stock purchasing: the Internal PO. The number printed on it
 * (PO-26-27/T-080) is what Store picks on Record Receiving, so what arrives
 * is checked against what was ordered.
 */
class GsPurchaseOrder extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_CHECKED = 'checked';
    public const STATUS_ISSUED = 'issued';
    public const STATUS_PARTIAL = 'partially_received';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'gs_purchase_orders';

    protected $fillable = [
        'po_no', 'po_date', 'status', 'gs_comparative_statement_id', 'gs_cs_supplier_id',
        'general_stock_supplier_id', 'supplier_name', 'supplier_address', 'supplier_phone',
        'reference_no', 'bin_number', 'invoice_to', 'consignee', 'dispatched_through', 'destination',
        'payment_terms', 'delivery_terms', 'remarks', 'total_amount',
        'checked_by', 'checked_at', 'authorised_by', 'authorised_at', 'cancel_reason', 'created_by',
    ];

    protected $casts = [
        'po_date' => 'date',
        'total_amount' => 'decimal:2',
        'checked_at' => 'datetime',
        'authorised_at' => 'datetime',
    ];

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_CHECKED => 'Checked',
            self::STATUS_ISSUED => 'Issued',
            self::STATUS_PARTIAL => 'Partly Received',
            self::STATUS_RECEIVED => 'Received',
            self::STATUS_CANCELLED => 'Cancelled',
        ];
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? $this->status;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /** Draft or Checked: made, but not yet authorised, so Store cannot receive against it. */
    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_CHECKED], true);
    }

    /** Store may receive against a PO that is issued and not yet fully received. */
    public function isReceivable(): bool
    {
        return in_array($this->status, [self::STATUS_ISSUED, self::STATUS_PARTIAL], true);
    }

    public function scopeReceivable(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_ISSUED, self::STATUS_PARTIAL]);
    }

    public function items()
    {
        return $this->hasMany(GsPurchaseOrderItem::class, 'gs_purchase_order_id')->orderBy('sort_order')->orderBy('id');
    }

    public function statement()
    {
        return $this->belongsTo(GsComparativeStatement::class, 'gs_comparative_statement_id');
    }

    public function supplier()
    {
        return $this->belongsTo(GeneralStockSupplier::class, 'general_stock_supplier_id')->withTrashed();
    }

    public function checkedBy()
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    public function authorisedBy()
    {
        return $this->belongsTo(User::class, 'authorised_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receivings()
    {
        return $this->hasMany(StockPurchase::class, 'gs_purchase_order_id');
    }
}
