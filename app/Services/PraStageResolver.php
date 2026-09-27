<?php

namespace App\Services;

use App\Models\Buyer;
use App\Models\PaymentRequest;
use App\Models\PraApproval;
use App\Models\PaymentRequestItem;
use App\Models\User;
use App\Support\DepartmentRoles;
use App\Support\PraStageActors;
use Illuminate\Support\Collection;

/**
 * WHO MAY ACT AT STEP N of a flow-2 PRA. Pure lookup: reads nothing but the
 * PRA and the user table, writes nothing, decides nothing about state.
 *
 * The 6-step departmental chain:
 *
 *   1  Supply Chain   creates      (no row here — see below)
 *   2  Merchant       Team  check      buyer-scoped
 *   3  Merchant       Head  approve    buyer-scoped
 *   4  Accounts       Team  check      NOT buyer-scoped
 *   5  Accounts       Head  approve    NOT buyer-scoped
 *   6  Commercial     Head  complete   NOT buyer-scoped
 *
 * Step 1 has no entry: the creator is recorded on payment_requests itself, and
 * nobody "acts" on a step they authored.
 *
 * THIS CLASS IS THE SEAM. Every question of the form "is this person allowed to
 * touch this PRA right now" goes through resolve() / mayAct(). When Team Head
 * eventually becomes per-department (the deferred buyer_department_admins
 * pivot), this file changes and nothing else does.
 *
 * IT REUSES, IT DOES NOT REINVENT:
 *   - role + is_department_admin, queried the same way
 *     BuyerController::merchantDepartmentAdmins() already queries it;
 *   - buyers.department_admin_id for "the Merchant Head of THIS buyer";
 *   - User::hasBuyerAccess(), the existing buyer predicate, for scoping.
 * There is deliberately no second comparison written here.
 */
class PraStageResolver
{
    public const SEQ_MERCHANT_CHECK = 2;
    public const SEQ_MERCHANT_APPROVE = 3;
    public const SEQ_ACCOUNTS_CHECK = 4;
    public const SEQ_ACCOUNTS_APPROVE = 5;
    public const SEQ_COMMERCIAL_COMPLETE = 6;

    public const FIRST_SEQUENCE = self::SEQ_MERCHANT_CHECK;
    public const LAST_SEQUENCE = self::SEQ_COMMERCIAL_COMPLETE;

    /**
     * DEPARTMENTS WHOSE PRA STEPS ARE CONFINED TO THE PRA'S BUYER.
     *
     * Merchandising only, and that is a BUSINESS DECISION recorded here rather
     * than an accident of implementation. Merchandising is organised by buyer,
     * so a merchant should only check a PRA for a buyer they work on. Accounts
     * and Commercial process payments for the whole factory and are deliberately
     * NOT confined — a company-wide payments desk narrowed to one buyer would
     * simply stop seeing most of its work.
     *
     * To change it, add the department KEY here. Nothing else needs editing:
     * every buyer-scoping branch in this file reads this constant.
     *
     * @var list<string>
     */
    public const BUYER_SCOPED_DEPARTMENTS = ['merchandising'];

    /**
     * Named, NON-FATAL routing conditions. These do not block the step; they
     * explain a routing decision the reader would otherwise find surprising.
     */
    public const NOTE_UNTAGGED_PRA = 'untagged_pra';

    /**
     * @var array<int, array{department:string, actor_kind:string, stage:string, label:string}>
     */
    private const STEPS = [
        self::SEQ_MERCHANT_CHECK => [
            'department' => 'merchandising',
            'actor_kind' => PraApproval::ACTOR_TEAM,
            'stage' => PraApproval::STAGE_CHECK,
            'label' => 'Merchant Team Check',
        ],
        self::SEQ_MERCHANT_APPROVE => [
            'department' => 'merchandising',
            'actor_kind' => PraApproval::ACTOR_HEAD,
            'stage' => PraApproval::STAGE_APPROVE,
            'label' => 'Merchant Head Approval',
        ],
        self::SEQ_ACCOUNTS_CHECK => [
            'department' => 'accounts',
            'actor_kind' => PraApproval::ACTOR_TEAM,
            'stage' => PraApproval::STAGE_CHECK,
            'label' => 'Accounts Team Check',
        ],
        self::SEQ_ACCOUNTS_APPROVE => [
            'department' => 'accounts',
            'actor_kind' => PraApproval::ACTOR_HEAD,
            'stage' => PraApproval::STAGE_APPROVE,
            'label' => 'Accounts Head Approval',
        ],
        self::SEQ_COMMERCIAL_COMPLETE => [
            'department' => 'commercial',
            'actor_kind' => PraApproval::ACTOR_HEAD,
            'stage' => PraApproval::STAGE_COMPLETE,
            'label' => 'Commercial Head Completion',
        ],
    ];

    /**
     * @return list<int>
     */
    public function sequences(): array
    {
        return array_keys(self::STEPS);
    }

    /**
     * @return array{department:string, actor_kind:string, stage:string, label:string}
     */
    public function definitionFor(int $sequence): array
    {
        if (! isset(self::STEPS[$sequence])) {
            throw new \InvalidArgumentException("Unknown PRA stage sequence: {$sequence}");
        }

        return self::STEPS[$sequence];
    }

    /**
     * The step after this one, or null when the chain is finished.
     */
    public function nextSequence(int $sequence): ?int
    {
        $all = $this->sequences();
        $position = array_search($sequence, $all, true);

        if ($position === false) {
            throw new \InvalidArgumentException("Unknown PRA stage sequence: {$sequence}");
        }

        return $all[$position + 1] ?? null;
    }

    public function isBuyerScoped(int $sequence): bool
    {
        return in_array(
            $this->definitionFor($sequence)['department'],
            self::BUYER_SCOPED_DEPARTMENTS,
            true
        );
    }

    /**
     * Everyone who may act at this step of this PRA.
     *
     * ANY ONE OF THEM SATISFIES THE STEP. A department with three Heads does not
     * need three approvals — the first to act carries it. That matches the
     * parallel semantic the legacy flow already uses and is the only reading
     * that survives a head being on leave.
     */
    public function resolve(PaymentRequest $paymentRequest, int $sequence): PraStageActors
    {
        // Buyers are read only when the step is scoped, exactly as before — an
        // unscoped step never touched the items relation and still does not.
        return $this->resolveForBuyers(
            $sequence,
            $this->isBuyerScoped($sequence) ? $this->buyerIdsFor($paymentRequest) : []
        );
    }

    /**
     * The same answer for a PRA that does not exist yet.
     *
     * resolve() reads the buyers off the PRA's saved items; the create-preview
     * screen has no saved PRA, only the booking POs about to become one. This is
     * the body of resolve() with the buyer lookup lifted out — resolve() calls
     * it, so there is still one routing rule, not two.
     *
     * ELIGIBILITY ONLY. Who was pointed at (the primary assignee) is not this
     * class's question — PraWorkflow attaches that on top of what is returned
     * here, and never narrows it.
     *
     * @param  list<int>  $buyerIds
     */
    public function resolveForBuyers(int $sequence, array $buyerIds): PraStageActors
    {
        $definition = $this->definitionFor($sequence);
        $department = $definition['department'];
        $isHead = $definition['actor_kind'] === PraApproval::ACTOR_HEAD;
        $buyerScoped = $this->isBuyerScoped($sequence);
        // Normalised to the shape buyerIdsFor() produces — sorted, unique ints —
        // so a caller passing raw ids gets the same PraStageActors as one that
        // went through resolve().
        $buyerIds = $buyerScoped
            ? collect($buyerIds)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all()
            : [];

        // WHO HOLDS THE POST AT ALL, before any buyer restriction. This must be
        // computed unscoped: "nobody is a Merchant Head" and "no Merchant Head
        // covers this buyer" both end as an empty set, and they are different
        // problems with different fixes. Narrowing first would collapse them.
        $inPost = $isHead
            ? $this->departmentHeads($department)
            : $this->teamMembersOf($department);

        $postIsFilled = $inPost->isNotEmpty();

        $users = $inPost;

        if ($buyerScoped && $postIsFilled) {
            $users = $isHead
                // A head's claim on a PRA is ownership of its buyer, which is
                // recorded on the buyer, not on the user.
                ? $this->restrictHeadsToBuyers($inPost, $buyerIds)
                : $this->confineToBuyers($inPost, $buyerIds);
        }

        return new PraStageActors(
            sequence: $sequence,
            label: $definition['label'],
            department: $department,
            departmentLabel: DepartmentRoles::labelOf($department) ?? $department,
            actorKind: $definition['actor_kind'],
            stage: $definition['stage'],
            users: $users->values(),
            buyerScoped: $buyerScoped,
            buyerIds: $buyerIds,
            note: ($buyerScoped && $buyerIds === []) ? self::NOTE_UNTAGGED_PRA : null,
            postIsFilled: $postIsFilled,
        );
    }

    /**
     * Whether this specific user may act at this step right now.
     */
    public function mayAct(PaymentRequest $paymentRequest, int $sequence, User $user): bool
    {
        return $this->resolve($paymentRequest, $sequence)->includes($user);
    }

    /**
     * Team members: hold a role of the department and are NOT its head.
     *
     * @return Collection<int, User>
     */
    protected function teamMembersOf(string $department): Collection
    {
        return $this->departmentUsers($department)
            ->where('is_department_admin', false)
            ->orderBy('name')
            ->get();
    }

    /**
     * Everyone in the department carrying is_department_admin, unrestricted.
     *
     * @return Collection<int, User>
     */
    protected function departmentHeads(string $department): Collection
    {
        return $this->departmentUsers($department)
            ->where('is_department_admin', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * Narrow a department's heads to those who head one of these buyers, via
     * buyers.department_admin_id — the column the Buyers screen already writes.
     *
     * INTERSECTED WITH THE DEPARTMENT SET, never trusted on its own.
     * department_admin_id is a plain column and demoting someone does not clear
     * it, so a stale row would otherwise resurrect the authority of a person who
     * is no longer a head at all.
     *
     * An untagged PRA (no buyers) narrows to nobody, so it is left unnarrowed —
     * see NOTE_UNTAGGED_PRA.
     *
     * @param  Collection<int, User>  $heads
     * @param  list<int>  $buyerIds
     * @return Collection<int, User>
     */
    protected function restrictHeadsToBuyers(Collection $heads, array $buyerIds): Collection
    {
        if ($buyerIds === []) {
            return $heads;
        }

        $buyerHeadIds = Buyer::query()
            ->whereIn('id', $buyerIds)
            ->whereNotNull('department_admin_id')
            ->pluck('department_admin_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $heads->filter(
            fn (User $user) => in_array((int) $user->id, $buyerHeadIds, true)
        )->values();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    protected function departmentUsers(string $department)
    {
        $roles = DepartmentRoles::rolesFor($department);

        // An unknown department key yields no roles. whereIn on an empty array
        // compiles to a false predicate through the grammar on every driver —
        // no raw SQL, and it fails CLOSED rather than matching every user.
        return User::query()
            ->when($roles === [], fn ($query) => $query->whereIn('id', []))
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $roles));
    }

    /**
     * Keep only users who may reach at least ONE of the PRA's buyers.
     *
     * ANY, not all, mirroring PaymentRequestController::authorizePaymentRequest()
     * — a PRA spanning two buyers is reachable by someone who holds either.
     *
     * hasBuyerAccess() is the existing predicate and is used here in its correct
     * direction: asking whether one person may reach one buyer, which is the lock
     * it was written for, not filtering a list of records by it.
     *
     * @param  Collection<int, User>  $users
     * @param  list<int>  $buyerIds
     * @return Collection<int, User>
     */
    protected function confineToBuyers(Collection $users, array $buyerIds): Collection
    {
        // No buyer on the PRA: hasBuyerAccess(null) is true for everyone, which
        // is the existing "untagged record belongs to no buyer's team" rule. The
        // step routes to the whole department rather than blocking, and the
        // caller is told via NOTE_UNTAGGED_PRA so the UI can say so.
        $targets = $buyerIds === [] ? [null] : $buyerIds;

        return $users->filter(
            fn (User $user) => collect($targets)->contains(
                fn (?int $buyerId) => $user->hasBuyerAccess($buyerId)
            )
        );
    }

    /**
     * The buyers this PRA touches, read from its items' source BOM files.
     *
     * payment_requests.buyer_name is a DISPLAY STRING and is deliberately not
     * used — the authoritative link is items.excelFile.buyer_id, exactly as
     * PaymentRequestController::authorizePaymentRequest() reads it.
     *
     * @return list<int>
     */
    public function buyerIdsFor(PaymentRequest $paymentRequest): array
    {
        $paymentRequest->loadMissing('items.excelFile');

        return $paymentRequest->items
            ->map(fn (PaymentRequestItem $item) => $item->excelFile?->buyer_id)
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
