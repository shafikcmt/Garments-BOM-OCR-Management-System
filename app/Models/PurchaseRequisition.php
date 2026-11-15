<?php

namespace App\Models;

use App\Services\RequisitionReviewChain;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * General Stock (module A) — a Purchase Requisition document.
 *
 * Replaces the hand-kept "Month_Of_<Month>.xlsx" workbook: one row here is one
 * sheet of that workbook, and its lines are the sheet's item rows.
 *
 * A single-item and a multi-item requisition are the same record with a
 * different `mode`, so both appear in one list and one report.
 *
 * SCOPE — GENERAL STOCK ONLY. This requisition workflow (department level
 * with its department-head check, and the final / merged level with its
 * configurable signature boxes) covers General Stock consumables and nothing
 * else. Buyer / Style-wise stock requisition is a SEPARATE future module with
 * its own permissions and, most likely, its own tables — the existing
 * MaterialRequisition under Buyer / Style Stock is unrelated to this class.
 * Do not extend this flow, its statuses, its signature blocks or its
 * permissions to buyer / style stock without a new task that says so.
 */
class PurchaseRequisition extends Model
{
    use HasFactory;

    /** The quick one-line form, for an urgent requirement. */
    public const MODE_SINGLE = 'single';

    /** The full form with dynamic item rows. */
    public const MODE_MULTI = 'multi';

    /**
     * Monthly: received by the Store and merged with the month's other sheets
     * into one Final Requisition. Individual: a one-off, received by the Store
     * and NOT merged — Finance & Commercial Head and Operation Head approve it.
     */
    public const TYPE_MONTHLY = 'monthly';
    public const TYPE_INDIVIDUAL = 'individual';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_STORE_REVIEWED = 'store_reviewed';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PURCHASE_ACTION_TAKEN = 'purchase_action_taken';
    /** Closed for good (Step 3c). Not counted anywhere, never edited again. */
    public const STATUS_CANCELLED = 'cancelled';

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
        'requisition_type',
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
        'review_level',
        'section_checked_by',
        'section_checked_at',
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
        'section_checked_at' => 'datetime',
        'store_ack_at' => 'datetime',
        'accounts_ack_at' => 'datetime',
        'checked_at' => 'datetime',
        'approved_at' => 'datetime',
        // Written only by RequisitionHeaderResolver::snapshot() — deliberately
        // not fillable, so no form can post one.
        'header_snapshot' => 'array',
        'header_snapshot_at' => 'datetime',
        'workflow_version' => 'integer',
        'current_stage' => 'integer',
        // Written only by the merge — not fillable.
        'merged_at' => 'datetime',
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
            self::STATUS_CANCELLED => 'Cancelled',
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
        return $this->status === self::STATUS_DRAFT
            || $this->rejectedByDepartmentHead()
            || $this->returnedToRaiser();
    }

    /**
     * A stage-engine sheet sent all the way back to the person who raised it
     * (current stage 1, "Prepared"): theirs to amend and resubmit or cancel.
     */
    public function returnedToRaiser(): bool
    {
        return $this->usesStageEngine()
            && (int) $this->current_stage === 1
            && ! in_array($this->status, [self::STATUS_DRAFT, self::STATUS_CANCELLED], true);
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
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

    /** @return array<string, string> type => label */
    public static function typeLabels(): array
    {
        return [
            self::TYPE_MONTHLY => 'Monthly',
            self::TYPE_INDIVIDUAL => 'Individual',
        ];
    }

    /**
     * The item lines in the order a Final Requisition reads on screen: by
     * category (largest spend first, as the Category Summary), each category
     * closed by a "sum" entry. A department's sheet keeps its written order.
     *
     * @return list<array<string, mixed>>
     */
    public function printEntries(): array
    {
        $entries = [];

        if ($this->isDepartmentLevel()) {
            foreach ($this->items as $line) {
                $entries[] = ['line' => $line];
            }

            return $entries;
        }

        $groups = \App\Support\CategoryGroups::of(
            $this->items,
            fn ($l) => optional($l->itemCategory)->name,
            fn ($l) => $l->amount,
            fn ($l) => $l->displayName(),
        );

        foreach ($groups as $category => $group) {
            foreach ($group as $line) {
                $entries[] = ['line' => $line];
            }
            $entries[] = [
                'sum' => $category,
                'qty' => $group->sum('qty_requested'),
                'tbp' => $group->sum('to_be_procured_qty'),
                'amount' => $group->sum('amount'),
            ];
        }

        return $entries;
    }

    /** A one-off sheet the Store receives but does not merge. */
    public function isIndividual(): bool
    {
        return $this->requisition_type === self::TYPE_INDIVIDUAL;
    }

    /**
     * Which set of approval boxes this sheet follows (Admin > Requisition
     * Signatures): its level, or "individual" for an individual sheet.
     */
    public function signatureLevel(): string
    {
        return $this->isIndividual()
            ? RequisitionSignatureBlock::LEVEL_INDIVIDUAL
            : ($this->level ?: self::LEVEL_FINAL);
    }

    /** The boxes a sheet of this level and type follows, before it exists. */
    public static function signatureLevelFor(string $level, ?string $type): string
    {
        return $level === self::LEVEL_DEPARTMENT && $type === self::TYPE_INDIVIDUAL
            ? RequisitionSignatureBlock::LEVEL_INDIVIDUAL
            : $level;
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
    | The head's step — section head or department head
    |--------------------------------------------------------------------------
    |
    | department_review_status says WHETHER a head holds the sheet (pending /
    | approved / rejected); review_level says WHICH head ('section' or
    | 'department'). RequisitionReviewChain decides the level; nothing here
    | does.
    */

    /** Submitted and waiting for the head at review_level. */
    public function awaitingDepartmentHead(): bool
    {
        return $this->status === self::STATUS_SUBMITTED
            && $this->department_review_status === self::DEPT_REVIEW_PENDING;
    }

    /** Sent back by a head; the requester may amend and resubmit. */
    public function rejectedByDepartmentHead(): bool
    {
        return $this->status === self::STATUS_SUBMITTED
            && $this->department_review_status === self::DEPT_REVIEW_REJECTED;
    }

    /**
     * Whether a head's step stands between this requisition and the Store.
     * False for a final-level sheet, for a department with nothing
     * configured, and for anything raised before the step existed (NULL).
     */
    public function heldByDepartmentHead(): bool
    {
        return in_array($this->department_review_status, [self::DEPT_REVIEW_PENDING, self::DEPT_REVIEW_REJECTED], true);
    }

    /** "Section Head" / "Department Head" — whoever the sheet is with or came back from. */
    public function reviewLevelLabel(): string
    {
        return RequisitionReviewChain::levelLabel($this->review_level ?: RequisitionReviewChain::LEVEL_DEPARTMENT);
    }

    /**
     * The head who decided on this sheet — the section head's stamp when the
     * check was at section level, else the department head's. One check is
     * made per sheet, so exactly one pair is ever written; this hides which.
     */
    public function headCheckedBy(): ?User
    {
        return $this->review_level === RequisitionReviewChain::LEVEL_SECTION
            ? $this->sectionCheckedBy
            : $this->departmentCheckedBy;
    }

    public function headCheckedAt(): ?Carbon
    {
        return $this->review_level === RequisitionReviewChain::LEVEL_SECTION
            ? $this->section_checked_at
            : $this->department_checked_at;
    }

    /**
     * What a head's decision writes: the stamp pair for the level the sheet
     * is at, the other pair untouched. Used by approve and return alike so
     * the two can never stamp different columns.
     *
     * @return array<string, mixed>
     */
    public function headDecisionStamp(?User $by): array
    {
        return $this->review_level === RequisitionReviewChain::LEVEL_SECTION
            ? ['section_checked_by' => $by?->id, 'section_checked_at' => now()]
            : ['department_checked_by' => $by?->id, 'department_checked_at' => now()];
    }

    /**
     * The columns a fresh submission clears: every head stamp and note, so a
     * returned sheet that is amended and sent again carries nothing from the
     * round before. The status and level themselves come from
     * RequisitionReviewChain.
     *
     * @return array<string, null>
     */
    public static function clearedHeadStamps(): array
    {
        return [
            'department_checked_by' => null,
            'department_checked_at' => null,
            'section_checked_by' => null,
            'section_checked_at' => null,
            'department_review_note' => null,
        ];
    }

    /**
     * Whether this user is the head who decides on this requisition at its
     * CURRENT level.
     *
     * The configured section head while the sheet is with the section; the
     * configured department head while it is with the department; plus
     * Admin and Management, who can already do everything the Store can and
     * are the fallback when a head is away. Never the requester on their own
     * sheet — the chain skipped any step they would have held.
     */
    public function isDepartmentHeadFor(?User $user): bool
    {
        if (! $user || ! $this->isDepartmentLevel()) {
            return false;
        }

        if ($user->hasAnyRole(['admin', 'management'])) {
            return true;
        }

        if ($this->review_level === RequisitionReviewChain::LEVEL_SECTION) {
            return $this->indent_section_id !== null
                && in_array($this->indent_section_id, RequisitionDepartmentHead::sectionsHeadedBy($user), true);
        }

        return in_array(
            RequisitionDepartmentHead::normalizeKey($this->department_role),
            RequisitionDepartmentHead::departmentsHeadedBy($user),
            true
        );
    }

    public function departmentLabel(): string
    {
        return RequisitionDepartmentHead::departmentLabel($this->department_role);
    }

    public function departmentCheckedBy()
    {
        return $this->belongsTo(User::class, 'department_checked_by');
    }

    public function sectionCheckedBy()
    {
        return $this->belongsTo(User::class, 'section_checked_by');
    }

    /**
     * Department requisitions waiting on this user as a head — of the section
     * the sheet is with, or of the department it is with: the Review queue.
     * Admin and Management see every department's.
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
        $sections = RequisitionDepartmentHead::sectionsHeadedBy($user);

        if ($departments === [] && $sections === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($departments, $sections) {
            // whereRaw('1 = 0') seeds an OR chain that is otherwise empty.
            $q->whereRaw('1 = 0');

            if ($sections !== []) {
                $q->orWhere(fn (Builder $s) => $s
                    ->where('review_level', RequisitionReviewChain::LEVEL_SECTION)
                    ->whereIn('indent_section_id', $sections));
            }

            if ($departments !== []) {
                $q->orWhere(fn (Builder $d) => $d
                    ->where('review_level', RequisitionReviewChain::LEVEL_DEPARTMENT)
                    ->whereIn('department_role', $departments));
            }
        });
    }

    /**
     * How many sheets wait on this user as a head — of a section or of a
     * department — or NULL when they head neither, which is what hides the
     * Department Review tab.
     */
    public static function departmentReviewCountFor(?User $user): ?int
    {
        if (! $user) {
            return null;
        }

        $isHead = $user->hasAnyRole(['admin', 'management'])
            || RequisitionDepartmentHead::departmentsHeadedBy($user) !== []
            || RequisitionDepartmentHead::sectionsHeadedBy($user) !== [];

        return $isHead ? self::query()->awaitingDepartmentHead($user)->count() : null;
    }

    /** The Requested tab's badge: department sheets waiting for the Store. */
    public static function awaitingStoreCountFor(?User $user): int
    {
        return $user && self::seesEveryRequisition($user)
            ? self::query()->fromDepartments()->where('status', self::STATUS_SUBMITTED)->count()
            : 0;
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
        // A stage-engine sheet is edited by its raiser (draft or returned), and
        // approvers change quantities at their own stage. On top of that the
        // Store may correct a DEPARTMENT's sheet once it has reached the Store
        // and until it is merged into a Final one.
        if ($this->usesStageEngine()) {
            return $this->isDepartmentLevel()
                && $this->merged_into_id === null
                && ($this->awaitingStoreReceipt() || $this->status === self::STATUS_STORE_REVIEWED);
        }

        return in_array($this->status, [
            self::STATUS_SUBMITTED,
            self::STATUS_STORE_REVIEWED,
        ], true);
    }

    public function items()
    {
        return $this->hasMany(PurchaseRequisitionItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /** On the stage engine (RequisitionWorkflow), rather than the old flow. */
    public function usesStageEngine(): bool
    {
        return $this->workflow_version !== null;
    }

    /*
    |--------------------------------------------------------------------------
    | The merge (Step 4)
    |--------------------------------------------------------------------------
    |
    | A department sheet ends at "Received by Store". The Store then merges
    | received sheets into its own final Purchase Requisition, which is the
    | one that goes through Store Head, Finance & Commercial and
    | Operation Head. merged_into_id links the two.
    */

    /** The final sheet this department sheet was merged into. */
    public function mergedInto()
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    /** The department sheets merged into this final sheet. */
    public function mergedSources()
    {
        return $this->hasMany(self::class, 'merged_into_id')->orderBy('requisition_no');
    }

    public function mergedBy()
    {
        return $this->belongsTo(User::class, 'merged_by');
    }

    public function isMerged(): bool
    {
        return $this->merged_into_id !== null;
    }

    /** Received by the Store and not yet in a final sheet. */
    public function isReadyToMerge(): bool
    {
        return $this->isDepartmentLevel()
            && ! $this->isIndividual()
            && $this->status === self::STATUS_STORE_REVIEWED
            && $this->merged_into_id === null;
    }

    public function scopeReadyToMerge(Builder $query): Builder
    {
        return $query->where('level', self::LEVEL_DEPARTMENT)
            ->where('requisition_type', '<>', self::TYPE_INDIVIDUAL)
            ->where('status', self::STATUS_STORE_REVIEWED)
            ->whereNull('merged_into_id');
    }

    /**
     * Individual sheets waiting at the Store's Received step, not merged —
     * what the Store Head / Store Admin can merge into one (mergeIndividual).
     */
    public function scopeIndividualAtStore(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->where($table.'.level', self::LEVEL_DEPARTMENT)
            ->where($table.'.requisition_type', self::TYPE_INDIVIDUAL)
            ->where($table.'.status', self::STATUS_SUBMITTED)
            ->whereNull($table.'.merged_into_id')
            ->whereHas('stages', fn ($s) => $s
                ->where('purchase_requisition_stages.status', PurchaseRequisitionStage::STATUS_PENDING)
                ->where('purchase_requisition_stages.stamp_source', RequisitionSignatureBlock::STAMP_STORE_HEAD)
                ->whereColumn('purchase_requisition_stages.sequence', $table.'.current_stage'));
    }

    /**
     * Who may merge: whoever raises the final sheet (the Store side) and may
     * create requisitions — the same pair that opens the entry form for a
     * final sheet.
     */
    public static function canMerge(?User $user): bool
    {
        return $user !== null
            && self::levelFor($user) === self::LEVEL_FINAL
            && ($user->can('store.requisition.create') || $user->can('store.requisition.final'));
    }

    /** Every cycle's approval plan. */
    public function stages()
    {
        return $this->hasMany(PurchaseRequisitionStage::class)->orderBy('cycle')->orderBy('sequence');
    }

    /** The sheet's history, newest first. */
    public function actions()
    {
        return $this->hasMany(PurchaseRequisitionAction::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    /** Quantities changed by approvers at their stage (Step 3d), oldest first. */
    public function qtyChanges()
    {
        return $this->hasMany(PurchaseRequisitionQtyChange::class)->orderBy('id');
    }

    /**
     * Who may delete this sheet. A DRAFT: its raiser or Admin. A submitted (or
     * cancelled) sheet: Admin only, with a reason — for clearing test or
     * duplicate entries. Never once it is approved or acted on, nor while a
     * Final requisition holds it (delete or release that first). The history
     * row survives the sheet. Everyone else cancels instead, so it stays on
     * record (canBeCancelledBy).
     */
    public function canBeDeletedBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($this->status === self::STATUS_DRAFT) {
            return $user->hasRole('admin') || $this->isRaisedBy($user);
        }

        // Admin may remove a sheet at any stage, so requisitions raised only to
        // test the workflow can be cleared out. A reason is required (see
        // deleteNeedsReason) and the history row survives the sheet.
        return $user->hasRole('admin');
    }

    /**
     * Who may cancel this sheet — close it for good, with a reason. Its
     * raiser while it is theirs (a draft, or sent back to them); Admin at any
     * point before it is approved. An approved sheet is not cancelled here.
     */
    public function canBeCancelledBy(?User $user): bool
    {
        if (! $user || in_array($this->status, [self::STATUS_CANCELLED, self::STATUS_APPROVED, self::STATUS_PURCHASE_ACTION_TAKEN], true)) {
            return false;
        }

        if ($user->hasRole('admin')) {
            return true;
        }

        return $this->isRaisedBy($user) && $this->isEditable();
    }

    public function deleteNeedsReason(): bool
    {
        return $this->status !== self::STATUS_DRAFT;
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

    /**
     * Who asked for this sheet, as the lists print it: the Indent Person
     * picked on the form, else the account that raised it. A department
     * form with a head never sets an Indent Person, so without the fallback
     * every such row read "—".
     */
    public function requesterName(): ?string
    {
        return $this->indentPerson?->name ?: $this->createdBy?->name;
    }

    /**
     * "Requested By" as the lists print it: always the HEAD the sheet goes to
     * — its section head when the section has one, else its department head —
     * the same person the document prints under "Checked by (Department
     * Head)". The account that typed the sheet is "Raised By".
     *
     * A Final Requisition (the Store's own) has no department head, and a
     * department with no head set falls back to requesterName().
     */
    public function requestedByName(): ?string
    {
        if (! $this->isDepartmentLevel()) {
            return $this->requesterName();
        }

        // One lookup per section / department / raiser on a page of rows.
        static $heads = [];
        // (Keyed by the running application too, so one test's rows never answer another's.)
        $key = spl_object_id(app()).'|'.$this->indent_section_id.'|'.$this->department_role.'|'.$this->created_by;

        if (! array_key_exists($key, $heads)) {
            $first = \App\Services\RequisitionReviewChain::stepsForRequisition($this)[0] ?? null;
            $head = $first['head'] ?? RequisitionDepartmentHead::headOf($this->department_role);
            $heads[$key] = $head?->name;
        }

        return $heads[$key] ?: $this->requesterName();
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

    /**
     * Sheets whose quantities count as demand — the Stock Summary and the
     * Monthly Requisition report both read this, so the two can never
     * disagree on what "requested" means.
     *
     * Counted: anything past Draft that is either a final sheet, or a
     * department sheet whose head step is approved or never applied (NULL —
     * a department with no head, or a sheet from before the step existed).
     * Not counted: drafts, sheets still with a head, and sheets a head
     * returned — nobody has accepted those figures yet.
     *
     * A department sheet MERGED into a final sheet is counted through that
     * final sheet, once it is submitted; while the final sheet is a draft,
     * or sent back to the Store to amend, the department sheet counts on
     * its own. A department sheet a
     * hand-typed final sheet repeats is handled line by line by
     * PurchaseRequisitionItem::notRepeatedOnFinalSheet().
     */
    public function scopeCountedInTotals(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query
            ->whereNotIn($table.'.status', [self::STATUS_DRAFT, self::STATUS_CANCELLED])
            ->where(fn (Builder $q) => $q->where($table.'.level', self::LEVEL_FINAL)
                ->orWhereNull($table.'.department_review_status')
                ->orWhere($table.'.department_review_status', self::DEPT_REVIEW_APPROVED))
            // A stage-engine sheet sent back to its raiser is not demand until
            // it is resubmitted. Grouped, so the OR cannot escape the rest.
            ->where(fn (Builder $q) => $q->whereNull($table.'.workflow_version')
                ->orWhereNull($table.'.current_stage')
                ->orWhere($table.'.current_stage', '<>', 1))
            ->where(fn (Builder $q) => $q->whereNull($table.'.merged_into_id')
                ->orWhereHas('mergedInto', fn (Builder $f) => $f->where(fn (Builder $w) => $w
                    ->where('status', self::STATUS_DRAFT)
                    ->orWhere(fn (Builder $r) => $r->whereNotNull('workflow_version')->where('current_stage', 1)))));
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
        // True on either flow: the sheet is with the Store. On the stage
        // engine the Store acts through its Store Head stage, not the old
        // Received button — the controller and the buttons check that.
        return $this->status === self::STATUS_SUBMITTED && ! $this->heldByDepartmentHead();
    }

    /**
     * Whether this user raised the sheet. The one who raised it may not also
     * mark it Received or Approve it — not even Admin — or a single person
     * could carry a requisition from request to approved spend alone.
     * Compared as integers: the driver may hand created_by back as a string.
     */
    public function isRaisedBy(?User $user): bool
    {
        return $user !== null && $this->created_by !== null && (int) $this->created_by === (int) $user->id;
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
        if ($this->returnedToRaiser() && ! $this->rejectedByDepartmentHead()) {
            return 'Returned to Requester';
        }

        if ($this->isMerged() && $this->status === self::STATUS_STORE_REVIEWED) {
            return $this->isIndividual() ? 'Merged' : 'Merged into Final';
        }

        // A final sheet on its way through the approvals names the stage it
        // waits at — "With HR Head" — not the status its Store Head stage
        // happened to write ("Received by Store").
        if ((! $this->isDepartmentLevel() || $this->isIndividual()) && $this->usesStageEngine() && (int) $this->current_stage > 1
            && in_array($this->status, [self::STATUS_SUBMITTED, self::STATUS_STORE_REVIEWED], true)) {
            $stage = $this->relationLoaded('stages')
                ? $this->stages->where('sequence', $this->current_stage)->sortByDesc('cycle')->first()
                : $this->stages()->where('sequence', $this->current_stage)->reorder()->orderByDesc('cycle')->first();

            // An individual sheet's head and Store steps read as they do on any
            // department sheet ("With Production Head", "Sent to Store").
            if ($stage && $this->isIndividual() && in_array($stage->stamp_source, [RequisitionSignatureBlock::STAMP_DEPARTMENT_HEAD, RequisitionSignatureBlock::STAMP_STORE_HEAD], true)) {
                $stage = null;
            }

            if ($stage) {
                return 'With '.(trim((string) $stage->title) ?: $stage->label);
            }
        }

        return match ($this->status) {
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_SUBMITTED => match ($this->department_review_status) {
                self::DEPT_REVIEW_PENDING => 'With '.$this->reviewLevelLabel(),
                self::DEPT_REVIEW_REJECTED => 'Returned by '.$this->reviewLevelLabel(),
                default => 'Sent to Store',
            },
            self::STATUS_STORE_REVIEWED => 'Received by Store',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_PURCHASE_ACTION_TAKEN => 'Purchase Action Taken',
            self::STATUS_CANCELLED => 'Cancelled',
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

        if (self::seesWholeCompanyIncludingDrafts($user)) {
            return $query;
        }

        // A head also sees what their department, or their section, raised —
        // they cannot check a sheet they cannot open. Still nobody else's:
        // the head of Merchandising does not see Commercial's, and the head
        // of Cutting does not see Finishing's.
        $departments = RequisitionDepartmentHead::departmentsHeadedBy($user);
        $sections = RequisitionDepartmentHead::sectionsHeadedBy($user);
        $storeDesk = self::worksStoreDesk($user);

        return $query->where(function (Builder $q) use ($user, $departments, $sections, $storeDesk) {
            $q->where('created_by', $user->id);

            // The Store's side by permission: every sheet that has reached
            // the Store. Grouped, so its ORs cannot escape the closure.
            if ($storeDesk) {
                $q->orWhere(fn (Builder $s) => $s->reachedStore());
            }

            if ($departments !== []) {
                $q->orWhere(fn (Builder $d) => $d
                    ->where('level', self::LEVEL_DEPARTMENT)
                    ->where('status', '<>', self::STATUS_DRAFT)
                    ->whereIn('department_role', $departments));
            }

            if ($sections !== []) {
                $q->orWhere(fn (Builder $s) => $s
                    ->where('level', self::LEVEL_DEPARTMENT)
                    ->where('status', '<>', self::STATUS_DRAFT)
                    ->whereIn('indent_section_id', $sections));
            }

            // Anyone named on a stage of the sheet's approval plan (HR Audit,
            // Finance…) must be able to open what they are asked to approve.
            $q->orWhereHas('stages', fn (Builder $st) => $st->where('assignee_user_id', $user->id));
        });
    }

    /** The single-record form of visibleTo(): may this user open this sheet? */
    public function isVisibleTo(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if (self::seesWholeCompanyIncludingDrafts($user) || $this->created_by === $user->id) {
            return true;
        }

        if (self::worksStoreDesk($user) && $this->hasReachedStore()) {
            return true;
        }

        if ($this->usesStageEngine() && $this->stages()->where('assignee_user_id', $user->id)->exists()) {
            return true;
        }

        if (! $this->isDepartmentLevel() || $this->status === self::STATUS_DRAFT) {
            return false;
        }

        return in_array(RequisitionDepartmentHead::normalizeKey($this->department_role), RequisitionDepartmentHead::departmentsHeadedBy($user), true)
            || ($this->indent_section_id !== null
                && in_array($this->indent_section_id, RequisitionDepartmentHead::sectionsHeadedBy($user), true));
    }

    /**
     * Admin and Management read every sheet, including other people's drafts
     * and sheets still with their section head.
     *
     * The Store side (store role, or store.requisition.final) does NOT. A
     * requisition is the Store's business only once it has been submitted and,
     * for a department sheet, accepted by its section head (reachedStore());
     * before that it belongs to whoever raised it. The Store still sees its own
     * drafts (created_by) and anything it is named on.
     */
    public static function seesWholeCompanyIncludingDrafts(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'management']);
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
        // store.requisition.final: Admin ticks it per user on Admin > Users, so a
        // person on another role (a General Stock clerk, say) can prepare the Final
        // Requisition. It makes them Store side for requisitions: they raise the final
        // sheet, see the Requested queue and every requisition, and merge.
        return $user->hasAnyRole(['store', 'admin', 'management'])
            || $user->can('store.requisition.final');
    }

    /**
     * Who may open the Category Summary and its PDF / Excel: the Store's side of
     * requisitions, or anyone Admin ticked store.category_summary.view for (who can
     * open Purchase Requisition at all). That permission opens the summary and nothing
     * else of the Store's desk.
     */
    public static function canViewCategorySummary(?User $user): bool
    {
        return $user !== null
            && (self::worksStoreDesk($user) || $user->can('store.category_summary.view'));
    }

    /**
     * Whether this user works the Store's side of requisitions: the Store
     * roles above, anyone granted store.requisition.review (the Store's
     * Received step) — a Store clerk on another role, for example — or the
     * Store department head.
     *
     * By permission, not role: grants here are per user. A user on the
     * permission alone sees what has reached the Store (see visibleTo()),
     * never another person's draft; the Store roles keep seeing everything.
     */
    public static function worksStoreDesk(?User $user): bool
    {
        return $user !== null
            && (self::seesEveryRequisition($user)
                || $user->can('store.requisition.review')
                || self::isStoreHead($user));
    }

    /**
     * Who may merge Individual requisitions of one section: the Store Head on
     * Admin > Departments, or a Store Admin (a Store user marked Department
     * Admin on Users & Roles — shown as "Store Head" on their profile).
     */
    public static function canMergeIndividual(?User $user): bool
    {
        return self::isStoreHead($user) || StockItem::isStoreAdmin($user);
    }

    /** The Store department head on Admin > Departments — Store, whatever their role. */
    public static function isStoreHead(?User $user): bool
    {
        return $user !== null
            && (int) \App\Models\Department::where('key', 'store')->value('head_user_id') === (int) $user->id;
    }

    /**
     * Not a draft, and — for a department sheet — past its head (or it has
     * no head step). What the Store has in front of it.
     */
    public function scopeReachedStore(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query
            ->where($table.'.status', '<>', self::STATUS_DRAFT)
            ->where(fn (Builder $q) => $q->where($table.'.level', self::LEVEL_FINAL)
                ->orWhereNull($table.'.department_review_status')
                ->orWhere($table.'.department_review_status', self::DEPT_REVIEW_APPROVED));
    }

    /** The single-record form of reachedStore(). */
    public function hasReachedStore(): bool
    {
        return $this->status !== self::STATUS_DRAFT
            && (! $this->isDepartmentLevel()
                || $this->department_review_status === null
                || $this->department_review_status === self::DEPT_REVIEW_APPROVED);
    }

    /**
     * What the main list is called for this viewer. The list itself is
     * visibleTo()-scoped, so for a department user or a head it holds only
     * their own sheets and their queue — calling that "All Requisitions"
     * would promise the whole company's.
     */
    public static function listLabelFor(?User $user): string
    {
        return self::worksStoreDesk($user) ? 'All Requisitions' : 'My Requisitions';
    }
}
