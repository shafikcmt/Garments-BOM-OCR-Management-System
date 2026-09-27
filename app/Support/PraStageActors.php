<?php

namespace App\Support;

use App\Models\PraApproval;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The answer to "who may act at step N of this PRA", and — just as important —
 * whether anybody can.
 *
 * WHY THIS IS NOT JUST A COLLECTION OF USERS. An empty result has two entirely
 * different meanings and the caller must not be allowed to confuse them:
 *
 *   nobody holds the post   The department has no Head assigned at all. This is
 *                           a CONFIGURATION GAP, and per the agreed rule the
 *                           workflow blocks and says so plainly rather than
 *                           skipping the step or quietly falling back to admin.
 *                           problem() carries the sentence to show.
 *   nobody is in scope      People hold the post, but none of them can reach
 *                           this PRA's buyer. Same empty collection, different
 *                           cause, different message.
 *
 * A bare Collection would render both as "nothing to do here", which is how a
 * required approval step silently disappears.
 */
final class PraStageActors
{
    /**
     * @param  Collection<int, User>  $users  Everyone who may act, in name order.
     * @param  list<int>  $buyerIds  The PRA's buyers; empty when it carries none.
     * @param  string|null  $note  A named, non-fatal routing condition — see
     *                             PraStageResolver::NOTE_* — or null.
     * @param  User|null  $assignee  The PRIMARY ASSIGNEE: the one eligible person
     *                               the creator pointed at. Informational only —
     *                               they are in $users like everyone else and
     *                               $users is never narrowed to them. Attached by
     *                               PraWorkflow, never by the resolver, which
     *                               answers only "who is eligible".
     * @param  User|null  $unavailableAssignee  Somebody WAS pointed at but is no
     *                               longer eligible (role removed, buyer access
     *                               withdrawn, deactivated). Kept so the screen can
     *                               say so; routing falls back to $users as if
     *                               nobody had been named.
     */
    public function __construct(
        public readonly int $sequence,
        public readonly string $label,
        public readonly string $department,
        public readonly string $departmentLabel,
        public readonly string $actorKind,
        public readonly string $stage,
        public readonly Collection $users,
        public readonly bool $buyerScoped,
        public readonly array $buyerIds = [],
        public readonly ?string $note = null,
        public readonly bool $postIsFilled = true,
        public readonly ?User $assignee = null,
        public readonly ?User $unavailableAssignee = null,
    ) {
    }

    /**
     * The same answer with the named person attached — as the primary assignee
     * if they are still eligible, as unavailable if they are not.
     *
     * A NEW INSTANCE, and $users is copied across unchanged. That is the whole
     * "team-wide visibility" guarantee in one line: attaching an assignee can
     * add information to a stage, never take an actor away from it.
     */
    public function withAssignment(?User $named): self
    {
        $eligible = $named !== null && $this->includes($named);

        return new self(
            sequence: $this->sequence,
            label: $this->label,
            department: $this->department,
            departmentLabel: $this->departmentLabel,
            actorKind: $this->actorKind,
            stage: $this->stage,
            users: $this->users,
            buyerScoped: $this->buyerScoped,
            buyerIds: $this->buyerIds,
            note: $this->note,
            postIsFilled: $this->postIsFilled,
            assignee: $eligible ? $named : null,
            unavailableAssignee: ($named !== null && ! $eligible) ? $named : null,
        );
    }

    /**
     * Whoever was pointed at, eligible or not — what the decision row snapshots.
     */
    public function namedAssignee(): ?User
    {
        return $this->assignee ?? $this->unavailableAssignee;
    }

    public function assigneeId(): ?int
    {
        return $this->assignee ? (int) $this->assignee->id : null;
    }

    public function isAssignee(User|int $user): bool
    {
        $id = $user instanceof User ? (int) $user->id : $user;

        return $this->assigneeId() !== null && $this->assigneeId() === $id;
    }

    /**
     * $users with the primary assignee moved to the front — for screens that
     * list who can act. Same members, same count; only the order differs.
     *
     * @return Collection<int, User>
     */
    public function usersAssigneeFirst(): Collection
    {
        $assigneeId = $this->assigneeId();

        if ($assigneeId === null) {
            return $this->users;
        }

        return $this->users
            ->sortBy(fn (User $user) => (int) $user->id === $assigneeId ? 0 : 1)
            ->values();
    }

    /**
     * Whether this step can proceed: somebody is both in post and in scope.
     */
    public function isActionable(): bool
    {
        return $this->users->isNotEmpty();
    }

    /**
     * The blocking sentence to show a user, or null when the step is fine.
     *
     * Written for non-technical office staff, and it always names the fix —
     * "ask a Super Admin" — because the person who hits this wall is never the
     * person who can climb it.
     */
    public function problem(): ?string
    {
        if ($this->isActionable()) {
            return null;
        }

        $tier = $this->actorKind === PraApproval::ACTOR_HEAD ? 'Head' : 'Team member';

        if (! $this->postIsFilled) {
            return 'This PRA cannot proceed — no ' . $this->departmentLabel . ' ' . $tier
                . ' is configured. Ask a Super Admin to assign one.';
        }

        return 'This PRA cannot proceed — no ' . $this->departmentLabel . ' ' . $tier
            . ' has access to this buyer. Ask a Super Admin to review their buyer access.';
    }

    public function includes(User|int $user): bool
    {
        $id = $user instanceof User ? $user->id : $user;

        return $this->users->contains(fn (User $candidate) => $candidate->id === $id);
    }

    /** @return list<int> */
    public function userIds(): array
    {
        return $this->users->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }
}
