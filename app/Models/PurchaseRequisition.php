<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * General Stock (module A) — a Purchase Requisition document.
 *
 * Replaces the hand-kept "Month_Of_<Month>.xlsx" workbook: one row here is one
 * sheet of that workbook, and its lines are the sheet's item rows.
 *
 * A single-item and a multi-item requisition are the same record with a
 * different `mode`, so both appear in one list and one report.
 */
class PurchaseRequisition extends Model
{
    use HasFactory;

    /** The quick one-line form, for an urgent requirement. */
    public const MODE_SINGLE = 'single';

    /** The full form with dynamic item rows. */
    public const MODE_MULTI = 'multi';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_STORE_REVIEWED = 'store_reviewed';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PURCHASE_ACTION_TAKEN = 'purchase_action_taken';

    /** Workflow order, earliest first — also the order shown in the UI. */
    public const STATUS_FLOW = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_STORE_REVIEWED,
        self::STATUS_APPROVED,
        self::STATUS_PURCHASE_ACTION_TAKEN,
    ];

    protected $fillable = [
        'requisition_no',
        'requisition_date',
        'mode',
        'unit_name',
        'indent_section_id',
        'indent_person_id',
        'contact',
        'status',
        'submitted_at',
        'store_ack_by',
        'store_ack_at',
        'accounts_ack_by',
        'accounts_ack_at',
        'checked_by',
        'checked_at',
        'approved_by',
        'approved_at',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'requisition_date' => 'date',
        'submitted_at' => 'datetime',
        'store_ack_at' => 'datetime',
        'accounts_ack_at' => 'datetime',
        'checked_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    /** @return array<string, string> status => label */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_SUBMITTED => 'Submitted',
            self::STATUS_STORE_REVIEWED => 'Store Reviewed',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_PURCHASE_ACTION_TAKEN => 'Purchase Action Taken',
        ];
    }

    /** @return array<string, string> mode => label */
    public static function modeLabels(): array
    {
        return [
            self::MODE_SINGLE => 'Single Item',
            self::MODE_MULTI => 'Multiple Items',
        ];
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? $this->status;
    }

    /**
     * Only a Draft may still be changed by the person who raised it. Once it is
     * Submitted the numbers on it have been read by someone else, so a change
     * is a correction and goes through the permission-gated actions instead.
     */
    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function items()
    {
        return $this->hasMany(PurchaseRequisitionItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function indentSection()
    {
        return $this->belongsTo(IndentSection::class);
    }

    public function indentPerson()
    {
        return $this->belongsTo(IndentPerson::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function storeAckBy()
    {
        return $this->belongsTo(User::class, 'store_ack_by');
    }

    public function accountsAckBy()
    {
        return $this->belongsTo(User::class, 'accounts_ack_by');
    }

    public function checkedBy()
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Requisitions raised in the given "YYYY-MM". */
    public function scopeForMonth(Builder $query, string $month): Builder
    {
        [$year, $m] = array_pad(explode('-', $month), 2, null);

        return $query->whereYear('requisition_date', $year)->whereMonth('requisition_date', $m);
    }
}
