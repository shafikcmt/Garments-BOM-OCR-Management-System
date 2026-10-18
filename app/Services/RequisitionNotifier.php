<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\Department;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionStage;
use App\Models\RequisitionSignatureBlock;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * In-app notifications for the requisition stage engine, written to the
 * bell's own table (app_notifications). Called by RequisitionWorkflow when a
 * sheet moves, and by the merge.
 *
 *   waiting    a sheet reached a stage — to whoever approves it
 *   returned   a sheet was sent back — to the requester, or the previous approver
 *   approved   the last stage was approved — to the requester
 *   cancelled  a sheet was closed — to the requester and whoever it waited for
 *   merged     a department sheet went into a final sheet — to its requester
 *
 * RULES
 *   - Sent after the transaction commits, so a rolled-back action sends
 *     nothing; and a failure here is logged and swallowed, never raised: a
 *     notice must not stop an approval.
 *   - The person who acted is never notified of their own action.
 *   - One "waiting" notice per user per stage per cycle while it is unread
 *     (a Store-team stage reaches every Store user once). When the stage is
 *     acted on, every user's notice for it is marked read.
 *   - A sheet that never used the stage engine sends nothing.
 */
class RequisitionNotifier
{
    public const WAITING = 'requisition_waiting';
    public const RETURNED = 'requisition_returned';
    public const APPROVED = 'requisition_approved';
    public const CANCELLED = 'requisition_cancelled';
    public const MERGED = 'requisition_merged';

    /** The bell's heading for each type — the visible label beside its icon. */
    public const TITLES = [
        self::WAITING => 'Waiting for approval',
        self::RETURNED => 'Requisition returned',
        self::APPROVED => 'Requisition approved',
        self::CANCELLED => 'Requisition cancelled',
        self::MERGED => 'Requisition merged',
    ];

    public function stageReached(PurchaseRequisition $requisition, ?PurchaseRequisitionStage $stage, ?User $actor): void
    {
        if (! $stage || ! $this->enabled($requisition)) {
            return;
        }

        $this->afterCommit(function () use ($requisition, $stage, $actor) {
            $text = $requisition->requisition_no.' is waiting for your approval ('.($stage->title ?: $stage->label).').';

            foreach ($this->approversOf($stage, $requisition, $actor) as $user) {
                $this->send($user, self::WAITING, $text, $requisition, $actor, [
                    'cycle' => $stage->cycle, 'sequence' => $stage->sequence,
                ], dedupe: true);
            }
        });
    }

    /** Sent back: to the previous approver, or the requester when no stage is left before "Prepared". */
    public function returned(PurchaseRequisition $requisition, ?PurchaseRequisitionStage $to, User $actor, string $reason): void
    {
        if (! $this->enabled($requisition)) {
            return;
        }

        $this->afterCommit(function () use ($requisition, $to, $actor, $reason) {
            $text = $requisition->requisition_no.' was returned to you by '.$actor->name.': '.$reason;
            $recipients = $to ? $this->approversOf($to, $requisition, $actor) : $this->raiserOf($requisition, $actor);

            foreach ($recipients as $user) {
                $this->send($user, self::RETURNED, $text, $requisition, $actor);
            }
        });
    }

    /** The last stage was approved: the requester hears it. */
    public function finished(PurchaseRequisition $requisition, User $actor): void
    {
        if (! $this->enabled($requisition)) {
            return;
        }

        $this->afterCommit(function () use ($requisition, $actor) {
            $text = $requisition->requisition_no.($requisition->isDepartmentLevel() ? ' was received by the Store.' : ' was approved.');

            foreach ($this->raiserOf($requisition, $actor) as $user) {
                $this->send($user, self::APPROVED, $text, $requisition, $actor);
            }
        });
    }

    /** Closed for good: the requester, and whoever the sheet was waiting for. */
    public function cancelled(PurchaseRequisition $requisition, ?PurchaseRequisitionStage $waitingAt, User $actor): void
    {
        if (! $this->enabled($requisition)) {
            return;
        }

        $this->afterCommit(function () use ($requisition, $waitingAt, $actor) {
            $text = $requisition->requisition_no.' was cancelled by '.$actor->name.'.';
            $recipients = $this->raiserOf($requisition, $actor)
                ->merge($waitingAt ? $this->approversOf($waitingAt, $requisition, $actor) : collect())
                ->unique('id');

            foreach ($recipients as $user) {
                $this->send($user, self::CANCELLED, $text, $requisition, $actor);
            }

            // Nobody has anything left to approve on it.
            $this->markRead($requisition->id);
        });
    }

    /**
     * A department sheet went into a final sheet: its requester hears it.
     * Links to their own sheet — the final one is the Store's, and may not be
     * theirs to open.
     */
    public function merged(PurchaseRequisition $source, PurchaseRequisition $final, ?User $actor): void
    {
        if (! $this->enabled($source)) {
            return;
        }

        $this->afterCommit(function () use ($source, $final, $actor) {
            $text = $source->requisition_no.' was merged into final sheet '.$final->requisition_no.'.';

            foreach ($this->raiserOf($source, $actor) as $user) {
                $this->send($user, self::MERGED, $text, $source, $actor, ['final_id' => $final->id]);
            }
        });
    }

    /**
     * A stage was acted on (approved or returned): its "waiting" notices stop
     * being news for everyone who was asked — the other Store users included.
     */
    public function stageActed(PurchaseRequisition $requisition, int $cycle, int $sequence): void
    {
        if (! $this->enabled($requisition)) {
            return;
        }

        $this->afterCommit(fn () => $this->markRead($requisition->id, $cycle, $sequence));
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function enabled(PurchaseRequisition $requisition): bool
    {
        return $requisition->usesStageEngine();
    }

    /** Runs once the outermost transaction commits (at once when there is none); never throws. */
    private function afterCommit(callable $work): void
    {
        DB::afterCommit(function () use ($work) {
            try {
                $work();
            } catch (\Throwable $e) {
                Log::error('Requisition notification failed: '.$e->getMessage(), ['exception' => $e]);
            }
        });
    }

    private function send(User $user, string $type, string $text, PurchaseRequisition $requisition, ?User $actor, array $data = [], bool $dedupe = false): void
    {
        $data = ['requisition_id' => $requisition->id] + $data;

        if ($dedupe && $this->unreadWaiting($user, $data)->exists()) {
            return;
        }

        AppNotification::create([
            'user_id' => $user->id,
            'actor_id' => $actor?->id,
            'type' => $type,
            'title' => self::TITLES[$type],
            'message' => $text,
            'url' => route('store.stock.requisitions.show', $requisition),
            'data' => $data,
        ]);
    }

    private function unreadWaiting(User $user, array $data)
    {
        return AppNotification::query()
            ->where('user_id', $user->id)
            ->where('type', self::WAITING)
            ->whereNull('read_at')
            ->where('data->requisition_id', $data['requisition_id'])
            ->where('data->cycle', $data['cycle'])
            ->where('data->sequence', $data['sequence']);
    }

    private function markRead(int $requisitionId, ?int $cycle = null, ?int $sequence = null): void
    {
        AppNotification::query()
            ->where('type', self::WAITING)
            ->whereNull('read_at')
            ->where('data->requisition_id', $requisitionId)
            ->when($cycle !== null, fn ($q) => $q->where('data->cycle', $cycle))
            ->when($sequence !== null, fn ($q) => $q->where('data->sequence', $sequence))
            ->update(['read_at' => now()]);
    }

    /** @return Collection<int, User> */
    private function raiserOf(PurchaseRequisition $requisition, ?User $actor): Collection
    {
        $raiser = $requisition->created_by ? User::find($requisition->created_by) : null;

        return $this->without(collect([$raiser])->filter(), $actor);
    }

    /**
     * Who approves a stage: the user named on it, or every Store-team user.
     * The raiser is left out (they never approve their own sheet, so it is
     * never "waiting" for them) and so is the person who acted.
     *
     * @return Collection<int, User>
     */
    private function approversOf(PurchaseRequisitionStage $stage, PurchaseRequisition $requisition, ?User $actor): Collection
    {
        // A Store team step the sender pointed at one person tells that person
        // only; undirected, it tells the whole team.
        $users = $stage->assignee_type === RequisitionSignatureBlock::ASSIGNEE_STORE_TEAM && ! $stage->assignee_user_id
            ? $this->storeTeam()
            : collect([$stage->assignee_user_id ? User::find($stage->assignee_user_id) : null])->filter();

        return $this->without($users, $actor)->reject(fn (User $u) => $requisition->isRaisedBy($u))->values();
    }

    /** Active users only, and never the one who acted. */
    private function without(Collection $users, ?User $actor): Collection
    {
        return $users
            ->filter(fn (User $u) => (int) $u->status === 1)
            ->reject(fn (User $u) => $actor !== null && $u->id === $actor->id)
            ->unique('id')
            ->values();
    }

    /**
     * Every user who acts for the Store team (RequisitionWorkflow::isStoreTeam):
     * the Store role, anyone granted the Store's Received permission, and the
     * Store department head.
     *
     * @return Collection<int, User>
     */
    public function storeTeam(): Collection
    {
        $ids = collect();

        if (Role::where('name', 'store')->where('guard_name', 'web')->exists()) {
            $ids = $ids->merge(User::role('store')->pluck('id'));
        }

        if (Permission::where('name', 'store.requisition.review')->where('guard_name', 'web')->exists()) {
            $ids = $ids->merge(User::permission('store.requisition.review')->pluck('id'));
        }

        $ids->push(Department::where('key', 'store')->value('head_user_id'));

        return User::whereIn('id', $ids->filter()->unique()->all())->get()
            ->filter(fn (User $u) => RequisitionWorkflow::isStoreTeam($u))
            ->values();
    }
}
