<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\FollowUpTask;
use App\Models\Meeting;
use App\Models\MotherStyle;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Order progress meetings and their action items.
 *
 * An action item is a follow-up task (source "meeting") given to a person with
 * a due date, so it shows in their to-do list, in the morning reminder, in the
 * overdue e-mail and, when it stays late, in the supervisor's escalation.
 */
class Meetings
{
    public const SOURCE = 'meeting';

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{title: string, assigned_to: int, due_date: string}>  $actions
     * @throws \DomainException
     */
    public function create(array $data, array $actions, User $by): Meeting
    {
        return DB::transaction(function () use ($data, $actions, $by) {
            $meeting = Meeting::create($data + ['created_by' => $by->id, 'updated_by' => $by->id]);
            $this->addActions($meeting, $actions, $by);

            return $meeting;
        });
    }

    /**
     * @param  list<array{title: string, assigned_to: int, due_date: string}>  $actions
     * @return int number of action items made
     * @throws \DomainException when someone cannot see the meeting's buyer
     */
    public function addActions(Meeting $meeting, array $actions, User $by): int
    {
        $made = [];
        foreach ($actions as $a) {
            $assignee = User::where('status', 1)->find($a['assigned_to']);
            if (! $assignee || ! $assignee->hasBuyerAccess((int) $meeting->buyer_id)) {
                throw new \DomainException(($assignee?->name ?? 'That person') . ' cannot see this buyer, so cannot be given "' . $a['title'] . '".');
            }
            $made[] = FollowUpTask::create([
                'title' => trim($a['title']),
                'note' => 'Meeting: ' . $meeting->title . ' (' . $meeting->meeting_date->format('d M Y') . ')',
                'buyer_id' => $meeting->buyer_id,
                'mother_style_id' => $meeting->mother_style_id,
                'meeting_id' => $meeting->id,
                'assigned_to' => $assignee->id,
                'created_by' => $by->id,
                'due_date' => $a['due_date'],
                'source' => self::SOURCE,
            ]);
        }

        collect($made)->groupBy('assigned_to')->each(function ($tasks, $userId) use ($meeting, $by) {
            if ((int) $userId === $by->id) {
                return;
            }
            AppNotification::create([
                'user_id' => $userId, 'actor_id' => $by->id, 'type' => 'followup_assigned', 'title' => 'Action from a meeting',
                'message' => count($tasks) . ' action(s) for you from "' . $meeting->title . '", first due ' . $tasks->min('due_date')->format('d M Y') . '.',
                'url' => route('follow_ups.index'),
            ]);
        });

        return count($made);
    }

    /** Find the buyer's style by number (a mother style), or null. */
    public function styleFor(int $buyerId, ?string $styleNo): ?MotherStyle
    {
        $styleNo = trim((string) $styleNo);

        return $styleNo === '' ? null : MotherStyle::where('buyer_id', $buyerId)->where('style_no', $styleNo)->first();
    }

    /** People who can be given an action: active holders of the follow-up roles. */
    public function assignees()
    {
        $roles = \Spatie\Permission\Models\Role::whereIn('name', FollowUps::ASSIGNEE_ROLES)->pluck('name')->all();

        return $roles === [] ? collect() : User::role($roles)->where('status', 1)->orderBy('name')->get(['id', 'name']);
    }
}
