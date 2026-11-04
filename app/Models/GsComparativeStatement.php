<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * General Stock purchasing: a Comparative Statement (CS).
 *
 * Compares the suppliers' rates for approved Purchase Requisition lines and
 * records which supplier is chosen for each line. An approved CS is what an
 * Internal PO is made from. See migration 2026_11_05_000001.
 *
 * Sign-off boxes follow the printed form: Prepared (Jr. Executive - SCM),
 * Checked (Manager - SCM), Approved By.
 */
class GsComparativeStatement extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_CHECKED = 'checked';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'gs_comparative_statements';

    protected $fillable = [
        'cs_no', 'cs_date', 'status',
        'form_code', 'form_issue_date', 'form_version_no', 'form_revision_no',
        'recommendation', 'approver_note', 'remarks',
        'prepared_by', 'prepared_at', 'checked_by', 'checked_at', 'approved_by', 'approved_at',
        'return_note', 'returned_by', 'returned_at', 'cancel_reason', 'created_by',
    ];

    protected $casts = [
        'cs_date' => 'date',
        'prepared_at' => 'datetime',
        'checked_at' => 'datetime',
        'approved_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_SUBMITTED => 'Waiting for Check',
            self::STATUS_CHECKED => 'Waiting for Approval',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_CANCELLED => 'Cancelled',
        ];
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? $this->status;
    }

    /** Only a draft (new, or sent back) may be edited. */
    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function suppliers()
    {
        return $this->hasMany(GsCsSupplier::class, 'gs_comparative_statement_id')->orderBy('sort_order')->orderBy('id');
    }

    public function items()
    {
        return $this->hasMany(GsCsItem::class, 'gs_comparative_statement_id')->orderBy('sort_order')->orderBy('id');
    }

    public function purchaseOrders()
    {
        return $this->hasMany(GsPurchaseOrder::class, 'gs_comparative_statement_id');
    }

    public function preparedBy()
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function checkedBy()
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function returnedBy()
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        return $status ? $query->where('status', $status) : $query;
    }

    /**
     * What one supplier's column adds up to, over every line it quoted.
     * Needs items.quotes loaded.
     */
    public function totalFor(GsCsSupplier $supplier): float
    {
        $total = 0.0;

        foreach ($this->items as $item) {
            $price = $item->quoteFor($supplier->id)?->unit_price;

            if ($price !== null) {
                $total += (float) $item->qty * (float) $price;
            }
        }

        return round($total, 2);
    }

    /** What the chosen supplier of every line comes to in all. Needs items.quotes loaded. */
    public function chosenTotal(): float
    {
        $total = 0.0;

        foreach ($this->items as $item) {
            $price = $item->selectedQuote()?->unit_price;

            if ($price !== null) {
                $total += (float) $item->qty * (float) $price;
            }
        }

        return round($total, 2);
    }
}
