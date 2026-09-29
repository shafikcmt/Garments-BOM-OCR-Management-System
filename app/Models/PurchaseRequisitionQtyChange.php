<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Step 3d — one line's quantity changed by an approver at their stage.
 * See the migration for why the line itself is updated in place.
 */
class PurchaseRequisitionQtyChange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'purchase_requisition_id',
        'purchase_requisition_item_id',
        'cycle',
        'stage_sequence',
        'stage_label',
        'item_name',
        'old_qty',
        'new_qty',
        'reason',
        'user_id',
        'user_name',
    ];

    protected $casts = [
        'cycle' => 'integer',
        'stage_sequence' => 'integer',
        'old_qty' => 'decimal:4',
        'new_qty' => 'decimal:4',
    ];

    public function requisition()
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    public function item()
    {
        return $this->belongsTo(PurchaseRequisitionItem::class, 'purchase_requisition_item_id');
    }
}
