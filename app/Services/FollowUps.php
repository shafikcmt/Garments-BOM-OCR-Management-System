<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\FollowUpTask;
use App\Models\MotherStyle;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Follow-up tasks: creating one for a style, finishing it, and the daily reminder.
 *
 * The rules live here so the screens and the scheduled reminder cannot drift.
 */
class FollowUps
{
    /** Roles a task can be given to. */
    public const ASSIGNEE_ROLES = ['merchant', 'supply_chain', 'account', 'commercial', 'admin'];

    public function __construct(private StyleRollup $rollup)
    {
    }

    /**
     * People who may be given a follow-up for this style's buyer.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function assigneesFor(MotherStyle $mother)
    {
        // Only roles that exist: User::role() throws for a name that is missing.
        $roles = \Spatie\Permission\Models\Role::whereIn('name', self::ASSIGNEE_ROLES)->pluck('name')->all();

        if ($roles === []) {
            return collect();
        }

        return User::role($roles)
            ->where('status', 1)
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user) => $user->hasBuyerAccess((int) $mother->buyer_id))
            ->values();
    }

    /**
     * Create a follow-up for a style and tell the person.
     *
     * @throws \DomainException when this person already has an open follow-up for the style
     */
    public function create(MotherStyle $mother, User $assignee, User $by, string $due, ?string $title, ?string $note): FollowUpTask
    {
        $already = FollowUpTask::open()->where('mother_style_id', $mother->id)->where('assigned_to', $assignee->id)->exists();

        if ($already) {
            throw new \DomainException($assignee->name . ' already has an open follow-up for style ' . $mother->style_no . '.');
        }

        $kpis = $this->rollup->forMother($mother)['kpis'];

        $task = FollowUpTask::create([
            'title' => $title !== null && trim($title) !== '' ? trim($title) : $this->defaultTitle($mother, $kpis),
            'note' => $note,
            'buyer_id' => $mother->buyer_id,
            'mother_style_id' => $mother->id,
            'assigned_to' => $assignee->id,
            'created_by' => $by->id,
            'due_date' => $due,
            'source' => $kpis['late'] > 0 || $kpis['due_soon'] > 0 ? 'late_material' : 'manual',
            'meta' => ['late' => $kpis['late'], 'due_soon' => $kpis['due_soon'], 'materials' => $kpis['materials']],
        ]);

        if ($assignee->id !== $by->id) {
            $this->notify($assignee, $by, 'followup_assigned', 'New follow-up',
                $by->name . ' asked you to follow up: ' . $task->title . ' (due ' . $task->due_date->format('d M Y') . ').');
        }

        return $task;
    }

    public function finish(FollowUpTask $task, User $by): void
    {
        $task->update(['status' => FollowUpTask::DONE, 'completed_at' => now(), 'completed_by' => $by->id]);

        if ($task->created_by && $task->created_by !== $by->id && ($creator = $task->creator)) {
            $this->notify($creator, $by, 'followup_done', 'Follow-up done', $by->name . ' finished: ' . $task->title . '.');
        }
    }

    public function reopen(FollowUpTask $task): void
    {
        $task->update(['status' => FollowUpTask::OPEN, 'completed_at' => null, 'completed_by' => null]);
    }

    /**
     * One reminder per person per day for tasks due today or overdue.
     *
     * @return int number of people reminded
     */
    public function sendDailyReminders(?Carbon $today = null): int
    {
        $today ??= Carbon::today();
        $reminded = 0;

        FollowUpTask::open()->whereDate('due_date', '<=', $today)->get()->groupBy('assigned_to')
            ->each(function ($tasks, $userId) use ($today, &$reminded) {
                $already = AppNotification::where('user_id', $userId)->where('type', 'followup_reminder')
                    ->whereDate('created_at', $today)->exists();

                if ($already || ! ($user = User::find($userId))) {
                    return;
                }

                $overdue = $tasks->filter(fn ($t) => $t->due_date->lt($today))->count();
                $dueToday = $tasks->count() - $overdue;

                $parts = array_filter([
                    $overdue > 0 ? $overdue . ' overdue' : null,
                    $dueToday > 0 ? $dueToday . ' due today' : null,
                ]);

                $this->notify($user, null, 'followup_reminder', 'Follow-ups need attention',
                    'You have ' . implode(' and ', $parts) . ' follow-up(s).');
                $reminded++;
            });

        return $reminded;
    }

    private function defaultTitle(MotherStyle $mother, array $kpis): string
    {
        return match (true) {
            $kpis['late'] > 0 => 'Order ' . $kpis['late'] . ' late material(s) for style ' . $mother->style_no,
            $kpis['due_soon'] > 0 => 'Order ' . $kpis['due_soon'] . ' material(s) due soon for style ' . $mother->style_no,
            default => 'Follow up style ' . $mother->style_no,
        };
    }

    private function notify(User $to, ?User $actor, string $type, string $title, string $message): void
    {
        AppNotification::create([
            'user_id' => $to->id,
            'actor_id' => $actor?->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'url' => route('follow_ups.index'),
        ]);
    }
}
