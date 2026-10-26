<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\FollowUpTask;
use App\Models\MotherStyle;
use App\Models\TnaTemplate;
use App\Models\TnaTemplateTask;
use App\Models\User;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/**
 * Applies a TnA template to a style: one follow-up task per template task,
 * due offset_days from the style's anchor date, given to someone holding the
 * task's role who may see the buyer. Applying twice adds only what is
 * missing, so it is safe to run again (and the daily auto-apply relies on it).
 */
class TnaPlans
{
    public const SOURCE = 'tna';

    /** @return array<string, Carbon|null> anchor key => date */
    public function anchorDates(MotherStyle $mother): array
    {
        $lines = $mother->orderLines()->get(['ship_date', 'created_at']);

        return [
            'ship_date' => $lines->pluck('ship_date')->filter()->min(),
            'order_received' => $lines->pluck('created_at')->filter()->min()?->copy()->startOfDay(),
        ];
    }

    /**
     * @return array{created: int, skipped: list<string>}
     */
    public function apply(MotherStyle $mother, TnaTemplate $template, ?User $by = null): array
    {
        $template->loadMissing('tasks');
        $anchors = $this->anchorDates($mother);
        $done = FollowUpTask::where('mother_style_id', $mother->id)->where('source', self::SOURCE)->get()
            ->map(fn ($t) => (int) ($t->meta['tna_task_id'] ?? 0))->filter()->all();

        $created = [];
        $skipped = [];

        foreach ($template->tasks as $task) {
            if (in_array($task->id, $done, true)) {
                continue;
            }

            $anchor = $anchors[$task->anchor] ?? null;
            if (! $anchor) {
                $skipped[] = $task->title . ': the style has no ' . mb_strtolower(TnaTemplateTask::ANCHORS[$task->anchor] ?? $task->anchor) . ' yet';
                continue;
            }

            $assignee = $this->assigneeFor($mother, $task->assignee_role, $by);
            if (! $assignee) {
                $skipped[] = $task->title . ': nobody with the role "' . str_replace('_', ' ', $task->assignee_role) . '" can see this buyer';
                continue;
            }

            $created[] = FollowUpTask::create([
                'title' => $task->title . ' — style ' . $mother->style_no,
                'note' => $task->whenLabel() . ' (TnA: ' . $template->name . ')',
                'buyer_id' => $mother->buyer_id,
                'mother_style_id' => $mother->id,
                'assigned_to' => $assignee->id,
                'created_by' => $by?->id,
                'due_date' => Carbon::parse($anchor)->addDays($task->offset_days)->toDateString(),
                'source' => self::SOURCE,
                'meta' => ['tna_template_id' => $template->id, 'tna_task_id' => $task->id],
            ]);
        }

        // One message per person, not one per task.
        collect($created)->groupBy('assigned_to')->each(function ($tasks, $userId) use ($mother, $template, $by) {
            if ($by && (int) $userId === $by->id) {
                return;
            }
            AppNotification::create([
                'user_id' => $userId,
                'actor_id' => $by?->id,
                'type' => 'followup_assigned',
                'title' => 'New TnA tasks',
                'message' => count($tasks) . ' task(s) from the "' . $template->name . '" plan for style ' . $mother->style_no . ', first due ' . $tasks->min('due_date')->format('d M Y') . '.',
                'url' => route('follow_ups.index'),
            ]);
        });

        return ['created' => count($created), 'skipped' => $skipped];
    }

    /**
     * Apply every active auto-apply template to the styles of its buyer(s)
     * that have orders. Already-made tasks are never repeated.
     *
     * @return int tasks created
     */
    public function autoApply(): int
    {
        $total = 0;

        TnaTemplate::where('is_active', true)->where('auto_apply', true)->with('tasks')->get()
            ->each(function (TnaTemplate $template) use (&$total) {
                MotherStyle::query()
                    ->when($template->buyer_id, fn ($q) => $q->where('buyer_id', $template->buyer_id))
                    ->whereHas('orderLines')
                    ->each(function (MotherStyle $mother) use ($template, &$total) {
                        $total += $this->apply($mother, $template)['created'];
                    });
            });

        return $total;
    }

    /**
     * Someone holding the role who may see the buyer: the person applying if
     * they qualify, else the one with the fewest open tasks.
     */
    private function assigneeFor(MotherStyle $mother, string $role, ?User $by): ?User
    {
        if (! Role::where('name', $role)->exists()) {
            return null;
        }

        if ($by && $by->hasRole($role) && $by->hasBuyerAccess((int) $mother->buyer_id)) {
            return $by;
        }

        return User::role($role)->where('status', 1)->withCount(['followUpTasks as open_tasks' => fn ($q) => $q->where('status', FollowUpTask::OPEN)])
            ->orderBy('open_tasks')->orderBy('id')->get()
            ->first(fn (User $u) => $u->hasBuyerAccess((int) $mother->buyer_id));
    }
}
