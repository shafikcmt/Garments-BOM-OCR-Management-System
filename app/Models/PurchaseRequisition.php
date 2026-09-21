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

    /**
     * Which sheet this is. A DEPARTMENT requisition is raised by a department
     * for its own needs and passes its department head before the Store; a
     * FINAL one is the merged Purchase Requisition the Store raises itself.
     * See migration 2026_09_21_000001.
     */
    public const LEVEL_DEPARTMENT = 'department';
    public const LEVEL_FINAL = 'final';

    /**
     * The Department Head's decision on a department requisition. Sits beside
     * `status`, never inside it: a Submitted sheet is "with the department
     * head" while this is pending and "with the Store" once approved.
     */
    public const DEPT_REVIEW_PENDING = 'pending';
    public const DEPT_REVIEW_APPROVED = 'approved';
    public const DEPT_REVIEW_REJECTED = 'rejected';

    protected $fillable = [
        'requisition_no',
        'requisition_date',
        'mode',
        'level',
        'department_role',
        'unit_name',
        'indent_section_id',
        'indent_person_id',
        'contact',
        'status',
        'submitted_at',
        'department_review_status',
        'department_checked_by',
        'department_checked_at',
        'department_review_note',
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
        'department_checked_at' => 'datetime',
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
        // A sheet the department head sent back is the requester's again:
        // nobody downstream has accepted its figures, and the whole point of
        // returning it is that they fix it and submit it once more.
        return $this->status === self::STATUS_DRAFT || $this->rejectedByDepartmentHead();
    }

    /** @return array<string, string> level => label */
    public static function levelLabels(): array
    {
        return RequisitionSignatureBlock::levelLabels();
    }

    public function isDepartmentLevel(): bool
    {
        return $this->level === self::LEVEL_DEPARTMENT;
    }

    /**
     * The level a requisition raised by this user gets. Store-side users
     * raise the merged, final sheet; everyone else raises their department's.
     * The same rule the backfill migration applied to existing rows.
     */
    public static function levelFor(?User $user): string
    {
        return $user && self::seesEveryRequisition($user) ? self::LEVEL_FINAL : self::LEVEL_DEPARTMENT;
    }

    /*
    |--------------------------------------------------------------------------
    | The Department Head's step
    |--------------------------------------------------------------------------
    */

    /** Submitted and waiting for the department head's decision. */
    public function awaitingDepartmentHead(): bool
    {
        return $this->status === self::STATUS_SUBMITTED
            && $this->department_review_status === self::DEPT_REVIEW_PENDING;
    }

    /** Sent back by the department head; the requester may amend and resubmit. */
    public function rejectedByDepartmentHead(): bool
    {
        return $this->status === self::STATUS_SUBMITTED
            && $this->department_review_status === self::DEPT_REVIEW_REJECTED;
    }

    /**
     * Whether the department head's step stands between this requisition and
     * the Store. False for a final-level sheet, for a department with no head
     * configured, and for anything raised before the step existed (NULL).
     */
    public function heldByDepartmentHead(): bool
    {
        return in_array($this->department_review_status, [self::DEPT_REVIEW_PENDING, self::DEPT_REVIEW_REJECTED], true);
    }

    /**
     * Whether this user is the head who decides on this requisition.
     *
     * The configured head of the requisition's department, plus Admin and
     * Management, who can already do everything the Store can and are the
     * fallback when a head is away. Never the requester on their own sheet
     * unless they ARE the configured head — a one-person department signs
     * both boxes and that is the paper form's rule too.
     */
    public function isDepartmentHeadFor(?User $user): bool
    {
        if (! $user || ! $this->isDepartmentLevel()) {
            return false;
        }

        if ($user->hasAnyRole(['admin', 'management'])) {
            return true;
        }

        return in_array($this->department_role, RequisitionDepartmentHead::departmentsHeadedBy($user), true);
    }

    public function departmentLabel(): string
    {
        return RequisitionDepartmentHead::departmentLabel($this->department_role);
    }

    public function departmentCheckedBy()
    {
        return $this->belongsTo(User::class, 'department_checked_by');
    }

    /**
     * Department requisitions waiting on this user as department head: the
     * Department Review queue. Admin and Management see every department's.
     */
    public function scopeAwaitingDepartmentHead(Builder $query, ?User $user): Builder
    {
        $query->where('level', self::LEVEL_DEPARTMENT)
            ->where('status', self::STATUS_SUBMITTED)
            ->where('department_review_status', self::DEPT_REVIEW_PENDING);

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasAnyRole(['admin', 'management'])) {
            return $query;
        }

        $departments = RequisitionDepartmentHead::departmentsHeadedBy($user);

        return $departments === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('department_role', $departments);
    }

    /**
     * Whether the STORE may still correct this requisition.
     *
     * Deliberately separate from isEditable(), which is left exactly as it was:
     * that one answers "may the person who raised it still change it", and its
     * draft-only rule is what stops a requester quietly rewriting numbers
     * somebody else has already read. Loosening it would take that guarantee
     * from every requisition in the system.
     *
     * This is the other question — "may the Store fix it on the way through" —
     * and it is open only across the review stage: a department's sheet arrives
     * Submitted, the Store may correct it while it is Submitted or Store
     * Reviewed, and it shuts again at Approved. After approval the sheet has
     * been signed off; editing it then would leave a signed document
     * describing figures nobody signed.
     *
     * Holding the window open is not by itself permission to use it — the
     * caller still checks store.requisition.edit / store.edit, the correction
     * permission every other General Stock screen uses.
     */
    public function isOpenToStoreCorrection(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUBMITTED,
            self::STATUS_STORE_REVIEWED,
        ], true);
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

    /*
    |--------------------------------------------------------------------------
    | The Store's review of a department's request
    |--------------------------------------------------------------------------
    |
    | No new statuses and no new columns: the flow already had store_reviewed
    | and approved, and store_ack_by/at and approved_by/at were already on the
    | table. What was missing was only the two ACTIONS that move a requisition
    | along them, which is what these answer.
    */

    /**
     * With the Store and not yet acknowledged. A department sheet still held
     * by its department head has not reached the Store yet, so it is not.
     */
    public function awaitingStoreReceipt(): bool
    {
        return $this->status === self::STATUS_SUBMITTED && ! $this->heldByDepartmentHead();
    }

    /** Acknowledged by the Store and not yet approved. */
    public function awaitingApproval(): bool
    {
        return $this->status === self::STATUS_STORE_REVIEWED;
    }

    /**
     * Where a requisition has got to, said the way the department that raised
     * it would say it. The internal status names describe the STORE's workflow
     * — "Store Reviewed" means nothing to the merchandiser who sent it — so
     * this is what their own screen shows.
     */
    public function progressLabel(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_SUBMITTED => match ($this->department_review_status) {
                self::DEPT_REVIEW_PENDING => 'With Department Head',
                self::DEPT_REVIEW_REJECTED => 'Returned by Department Head',
                default => 'Sent to Store',
            },
            self::STATUS_STORE_REVIEWED => 'Received by Store',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_PURCHASE_ACTION_TAKEN => 'Purchase Action Taken',
            default => $this->statusLabel(),
        };
    }

    /**
     * Requisitions a DEPARTMENT sent to the Store — the Store's review queue.
     *
     * "Sent to the Store" needs no column of its own: it is a requisition that
     * has been submitted (drafts are still being typed) and whose creator is
     * not Store, Admin or Management. A requisition the Store raised for itself
     * is its own business and does not belong in a queue of incoming requests.
     *
     * Users are matched by ROLE because that is how this project models a
     * department — there is no department column on users.
     */
    public function scopeFromDepartments(Builder $query): Builder
    {
        return $query
            ->where('status', '<>', self::STATUS_DRAFT)
            ->whereNotNull('created_by')
            ->whereDoesntHave('createdBy', fn ($u) => $u->whereHas(
                'roles',
                fn ($r) => $r->whereIn('name', ['store', 'admin', 'management'])
            ))
            // A sheet still with its department head — pending or sent back —
            // has not been sent to the Store yet. NULL is a sheet from before
            // the head step existed, or from a department with no head, and
            // those reach the Store directly as they always did.
            ->where(fn ($q) => $q->whereNull('department_review_status')
                ->orWhere('department_review_status', self::DEPT_REVIEW_APPROVED));
    }

    /**
     * What this user is allowed to see.
     *
     * The Store team, Admin and Management keep the whole company's
     * requisitions, exactly as they always have — this narrows nothing for
     * anyone who has access today.
     *
     * Everyone else sees only what they raised themselves. That is the case
     * created by the permission route: a Merchandising, Commercial or Accounts
     * user can be granted store.requisition.view / .create and raise their own
     * requisitions, and they should find their own on the list, not the whole
     * factory's.
     *
     * There is no `department` column to scope by — on a requisition the
     * requesting department is indent_section_id, which is chosen per
     * requisition rather than being a property of the user, and users carry
     * their department as a ROLE. So "their own" is created_by, which is
     * already stamped on every requisition at save.
     *
     * A guest (no user) sees nothing rather than everything: whereRaw('1 = 0')
     * keeps this a query the caller can keep chaining onto.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if (self::seesEveryRequisition($user)) {
            return $query;
        }

        // A department head also sees what their department raised — they
        // cannot check a sheet they cannot open. Still nobody else's
        // department: the head of Merchandising does not see Commercial's.
        $departments = RequisitionDepartmentHead::departmentsHeadedBy($user);

        return $query->where(function (Builder $q) use ($user, $departments) {
            $q->where('created_by', $user->id);

            if ($departments !== []) {
                $q->orWhere(fn (Builder $d) => $d
                    ->where('level', self::LEVEL_DEPARTMENT)
                    ->where('status', '<>', self::STATUS_DRAFT)
                    ->whereIn('department_role', $departments));
            }
        });
    }

    /** The single-record form of visibleTo(): may this user open this sheet? */
    public function isVisibleTo(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if (self::seesEveryRequisition($user) || $this->created_by === $user->id) {
            return true;
        }

        return $this->isDepartmentLevel()
            && $this->status !== self::STATUS_DRAFT
            && in_array($this->department_role, RequisitionDepartmentHead::departmentsHeadedBy($user), true);
    }

    /**
     * Whether this user reads the whole company's requisitions.
     *
     * The same three roles the route guards list first, asked the same way, so
     * a change to one is an obvious change to the other. Admin also passes
     * through Gate::before on any permission, but the role check here is what
     * keeps this readable next to $secRequisition in routes/store.php.
     */
    public static function seesEveryRequisition(User $user): bool
    {
        return $user->hasAnyRole(['store', 'admin', 'management']);
    }
}
