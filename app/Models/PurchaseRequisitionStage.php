<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One stage of a requisition's approval plan, for one cycle. Written by
 * App\Services\RequisitionWorkflow. See migration 2026_10_01_000002.
 */
class PurchaseRequisitionStage extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'purchase_requisition_id',
        'cycle',
        'sequence',
        'label',
        'title',
        'stamp_source',
        'assignee_type',
        'assignee_user_id',
        'assignee_name',
        'status',
        'acted_by',
        'acted_by_name',
        'acted_at',
        'comment',
    ];

    protected $casts = [
        'cycle' => 'integer',
        'sequence' => 'integer',
        'acted_at' => 'datetime',
    ];

    public function requisition()
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'acted_by');
    }

    /** "Checked by (HR Head)" — how the stage reads on screen and in history. */
    public function displayLabel(): string
    {
        return trim($this->label.($this->title ? ' ('.$this->title.')' : ''));
    }
}
