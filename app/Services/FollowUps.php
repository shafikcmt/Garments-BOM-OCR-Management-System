<?php

namespace App\Services;

use App\Mail\FollowUpDigestMail;
use App\Models\AppNotification;
use App\Models\AppSetting;
use App\Models\FollowUpTask;
use App\Models\MotherStyle;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Follow-up tasks: creating one for a style, finishing it, and the daily reminder.
 *
 * The rules live here so the screens and the scheduled reminder cannot drift.
 */
class FollowUps
{
    /** Roles a task can be given to. */
    public const ASSIGNEE_ROLES = ['merchant', 'supply_chain', 'account', 'commercial', 'admin'];

    public const KEY_EMAIL = 'followup_email_enabled';

    public const KEY_ESCALATE_DAYS = 'followup_escalate_after_days';

    public const DEFAULT_ESCALATE_DAYS = 3;

    /** Daily e-mail of overdue tasks. Off until Admin switches it on (mail may not be set up). */
    public static function emailEnabled(): bool
    {
        return (bool) (int) AppSetting::get(self::KEY_EMAIL, 0);
    }

    /** Days overdue after which the supervisor is told; 0 switches escalation off. */
    public static function escalateAfterDays(): int
    {
        return max(0, (int) AppSetting::get(self::KEY_ESCALATE_DAYS, self::DEFAULT_ESCALATE_DAYS));
    }

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
                $this->email($user, 'Follow-ups need your attention', 'You have ' . implode(' and ', $parts) . ' follow-up(s).', $tasks, $today, false);
                $reminded++;
            });

        return $reminded;
    }

    /**
     * Tell the supervisor about tasks that stayed overdue for escalateAfterDays
     * or more. Each task is escalated once (escalated_at); the supervisor gets one
     * message per run. The supervisors are Admin, Management of the buyer and a
     * Merchandising department head, never the person who owns the task.
     *
     * @return int number of tasks escalated
     */
    public function escalateOverdue(?Carbon $today = null): int
    {
        $days = self::escalateAfterDays();
        if ($days < 1) {
            return 0;
        }
        $today ??= Carbon::today();

        $tasks = FollowUpTask::open()->whereNull('escalated_at')->whereDate('due_date', '<=', $today->copy()->subDays($days))
            ->with(['assignee:id,name', 'motherStyle:id,style_no'])->get();
        if ($tasks->isEmpty()) {
            return 0;
        }

        $perSupervisor = [];
        foreach ($tasks as $task) {
            foreach ($this->supervisorsFor((int) $task->buyer_id)->reject(fn (User $u) => $u->id === (int) $task->assigned_to) as $sup) {
                $perSupervisor[$sup->id]['user'] = $sup;
                $perSupervisor[$sup->id]['tasks'][] = $task;
            }
        }

        foreach ($perSupervisor as $row) {
            $list = collect($row['tasks']);
            $names = $list->map(fn ($t) => $t->assignee?->name)->filter()->unique()->take(3)->implode(', ');
            $message = $list->count() . ' task(s) are ' . $days . '+ days overdue (' . $names . ').';
            $this->notify($row['user'], null, 'followup_escalation', 'Overdue tasks escalated', $message);
            $this->email($row['user'], 'Overdue tasks escalated', $message, $list, $today, true);
        }

        // Escalated once, even when nobody could be told, so it is not retried every morning.
        FollowUpTask::whereIn('id', $tasks->pluck('id'))->update(['escalated_at' => now()]);

        return $tasks->count();
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    public function supervisorsFor(int $buyerId)
    {
        $roles = array_values(array_intersect(['admin', 'management', 'merchant'], \Spatie\Permission\Models\Role::pluck('name')->all()));
        if ($roles === []) {
            return collect();
        }

        return User::role($roles)
            ->where('status', 1)->get()
            ->filter(fn (User $u) => $u->hasRole('admin') || (($u->hasRole('management') || ($u->hasRole('merchant') && $u->is_department_admin)) && $u->hasBuyerAccess($buyerId)))
            ->values();
    }

    /** A failed e-mail must never stop the in-app message or the rest of the run. */
    private function email(User $to, string $heading, string $intro, $tasks, Carbon $today, bool $withOwner): void
    {
        if (! self::emailEnabled() || ! filled($to->email)) {
            return;
        }
        $tasks = collect($tasks);
        $lines = $tasks->map(fn ($t) => [
            'title' => $t->title,
            'due' => $t->due_date->format('d M Y'),
            'who' => $withOwner ? $t->assignee?->name : null,
            'days_late' => max(0, (int) $t->due_date->diffInDays($today, false)),
        ])->values()->all();

        try {
            Mail::to($to->email)->send(new FollowUpDigestMail($heading, $intro, $lines, route('follow_ups.index')));
        } catch (\Throwable $e) {
            Log::warning('Follow-up e-mail failed for user ' . $to->id . ': ' . $e->getMessage());
        }
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
