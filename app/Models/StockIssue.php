<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockIssue extends Model
{
    use HasFactory;

    /**
     * `is_stock_item` and `item_description` are deliberately NOT fillable.
     * General Stock has one issue type — every issue is against an item in the
     * master — so is_stock_item keeps its database default of true and
     * item_description stays null. The columns remain only so the older
     * ['is_stock_item','issue_date'] index and any historical row stay valid.
     *
     * `buyer_name` / `style_name` are likewise not fillable: buyer- and
     * style-wise stock is a separate module and has no place on a general
     * store issue.
     */
    protected $fillable = [
        'stock_item_id',
        'requisition_no',
        'requisition_type',
        'issue_date',
        'qty',
        'issued_to',
        'department',
        'indent_section_id',
        'indent_person_id',
        'issue_approver_id',
        'item_category_id',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'is_stock_item' => 'boolean',
        'issue_date' => 'date',
        'qty' => 'decimal:4',
    ];

    /** Requisition Type values used on the reference Consumption sheet. */
    public const REQUISITION_TYPES = ['New', 'Replace'];

    /** "Month" on the Excel sheet — derived from the issue date. */
    public function getMonthLabelAttribute(): ?string
    {
        return $this->issue_date?->format('M-y');
    }

    public function stockItem()
    {
        return $this->belongsTo(StockItem::class);
    }

    public function indentSection()
    {
        return $this->belongsTo(IndentSection::class);
    }

    public function indentPerson()
    {
        return $this->belongsTo(IndentPerson::class);
    }

    public function approver()
    {
        return $this->belongsTo(IssueApprover::class, 'issue_approver_id');
    }

    public function itemCategory()
    {
        return $this->belongsTo(ItemCategory::class, 'item_category_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
