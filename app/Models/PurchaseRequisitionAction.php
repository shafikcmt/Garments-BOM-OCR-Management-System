<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One entry in a purchase requisition's history. Append-only: written by
 * App\Services\RequisitionHistory, never edited. See migration
 * 2026_09_28_000001.
 */
class PurchaseRequisitionAction extends Model
{
    public const UPDATED_AT = null;

    /** The day history started being recorded; anything earlier was backfilled from stamps. */
    public const RECORDED_FROM = '2026-09-28';

    public const CREATED = 'created';
    public const SUBMITTED = 'submitted';
    public const RESUBMITTED = 'resubmitted';
    public const HEAD_APPROVED = 'head_approved';
    public const HEAD_RETURNED = 'head_returned';
    public const RECEIVED = 'received';
    public const APPROVED = 'approved';
    public const CORRECTED = 'corrected';
    public const DELETED = 'deleted';
    /** A stage of the stage engine other than the head check and the final approval. */
    public const STAGE_APPROVED = 'stage_approved';
    /** Sent back to the previous stage (Step 3c). */
    public const RETURNED = 'returned';
    /** Closed for good (Step 3c). */
    public const CANCELLED = 'cancelled';
    /** An approver changed quantities at their stage (Step 3d); meta.lines lists them. */
    public const QTY_CHANGED = 'qty_changed';
    /** The head the sheet waits for added items to it; meta.lines lists them as "added". */
    public const ITEMS_ADDED = 'items_added';

    /** Step 4: a department sheet went into a final sheet (or a final sheet was made from some). */
    public const MERGED = 'merged';

    /** Step 4: the final sheet was deleted or cancelled, so the department sheet is free again. */
    public const MERGE_RELEASED = 'merge_released';

    public const STAGE_RAISER = 'raiser';
    public const STAGE_SECTION_HEAD = 'section_head';
    public const STAGE_DEPARTMENT_HEAD = 'department_head';
    public const STAGE_STORE = 'store';
    public const STAGE_FINAL_APPROVAL = 'final_approval';
    public const STAGE_ADMIN = 'admin';
    /** A stage-engine approver named on the plan (HR Audit, Finance…); meta.stage_label says which. */
    public const STAGE_APPROVER = 'approver';

    protected $fillable = [
        'purchase_requisition_id',
        'requisition_no',
        'action',
        'stage',
        'from_status',
        'to_status',
        'user_id',
        'user_name',
        'comment',
        'cycle',
        'meta',
        'created_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'cycle' => 'integer',
        'created_at' => 'datetime',
    ];

    public function requisition()
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The action as the history SHOWS it. The Store team's receipt of a
     * department sheet is recorded as 'received' now, but sheets handled
     * before that rule were written as 'stage_approved' at the Store's stage;
     * both read as Received. Display only — the stored action is untouched.
     */
    public function displayAction(bool $departmentSheet): string
    {
        return $departmentSheet && $this->action === self::STAGE_APPROVED && $this->stage === self::STAGE_STORE
            ? self::RECEIVED
            : $this->action;
    }

    public function actionLabel(bool $departmentSheet = false): string
    {
        return [
            self::CREATED => 'Saved as Draft',
            self::SUBMITTED => 'Submitted',
            self::RESUBMITTED => 'Resubmitted',
            self::HEAD_APPROVED => 'Checked by Head',
            self::HEAD_RETURNED => 'Returned by Head',
            self::RECEIVED => 'Received by Store',
            self::APPROVED => 'Approved',
            self::CORRECTED => 'Corrected by Store',
            self::DELETED => 'Deleted',
            self::STAGE_APPROVED => 'Stage Approved',
            self::RETURNED => 'Returned',
            self::CANCELLED => 'Cancelled',
            self::QTY_CHANGED => 'Quantity Changed',
            self::ITEMS_ADDED => 'Items Added',
            self::MERGED => 'Merged',
            self::MERGE_RELEASED => 'Released from Final',
        ][$this->displayAction($departmentSheet)] ?? ucfirst(str_replace('_', ' ', $this->action));
    }

    public function stageLabel(): ?string
    {
        // A stage-engine row names its own stage, as set on the signature box.
        if (! empty($this->meta['stage_label'])) {
            return $this->meta['stage_label'];
        }

        return [
            self::STAGE_RAISER => 'Requester',
            self::STAGE_SECTION_HEAD => 'Section Head',
            self::STAGE_DEPARTMENT_HEAD => 'Department Head',
            self::STAGE_STORE => 'Store',
            self::STAGE_FINAL_APPROVAL => 'Final Approval',
            self::STAGE_ADMIN => 'Admin',
        ][$this->stage] ?? null;
    }

    /** The name as it was when the action was taken; still there after the user is removed. */
    public function actorName(): string
    {
        return $this->user_name ?: ($this->user?->name ?? '—');
    }

    public function isBackfilled(): bool
    {
        return (bool) ($this->meta['backfilled'] ?? false);
    }
}
