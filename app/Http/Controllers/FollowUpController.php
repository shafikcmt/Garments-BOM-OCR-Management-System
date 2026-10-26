<?php

namespace App\Http\Controllers;

use App\Models\FollowUpTask;
use App\Models\MotherStyle;
use App\Models\User;
use App\Services\FollowUps;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The follow-up to-do list (Time and Action): who has to chase what, by when.
 *
 * Everyone who can be given a task sees their own list. Admin and Management
 * also see all tasks. A task tied to a buyer is hidden from a user who is
 * confined to other buyers, whatever tab they are on.
 */
class FollowUpController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $canSeeAll = $user->hasAnyRole(['admin', 'management']);

        $tab = in_array($request->query('tab'), ['mine', 'created', 'all'], true) ? $request->query('tab') : 'mine';
        $tab = $tab === 'all' && ! $canSeeAll ? 'mine' : $tab;
        $showDone = $request->boolean('done');

        $tasks = FollowUpTask::with(['assignee:id,name', 'creator:id,name', 'motherStyle:id,style_no,buyer_season_id'])
            ->when($tab === 'mine', fn ($q) => $q->where('assigned_to', $user->id))
            ->when($tab === 'created', fn ($q) => $q->where('created_by', $user->id))
            ->when(! $showDone, fn ($q) => $q->open())
            ->tap(function ($q) use ($user) {
                if ($user->isBuyerConfined()) {
                    $q->where(fn ($w) => $w->whereNull('buyer_id')->orWhereIn('buyer_id', $user->merchantBuyerIds()));
                }
            })
            ->orderByRaw("case when status = 'open' then 0 else 1 end")
            ->orderBy('due_date')
            ->limit(300)
            ->get();

        $today = Carbon::today();
        $open = $tasks->filter->isOpen();

        $groups = [
            'Overdue' => $open->filter(fn ($t) => $t->due_date->lt($today))->values(),
            'Due today' => $open->filter(fn ($t) => $t->due_date->isSameDay($today))->values(),
            'Coming up' => $open->filter(fn ($t) => $t->due_date->gt($today))->values(),
            'Done' => $tasks->reject->isOpen()->values(),
        ];

        return view('follow-ups.index', compact('groups', 'tab', 'showDone', 'canSeeAll', 'today'));
    }

    public function store(Request $request, FollowUps $followUps)
    {
        $data = $request->validate([
            'mother_style_id' => ['required', 'integer', 'exists:mother_styles,id'],
            'assigned_to' => ['required', 'integer', 'exists:users,id'],
            'due_date' => ['required', 'date', 'after_or_equal:today'],
            'title' => ['nullable', 'string', 'max:160'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'assigned_to.required' => 'Choose who should follow up.',
            'due_date.required' => 'Choose the date it is due.',
            'due_date.after_or_equal' => 'The due date cannot be in the past.',
        ]);

        $user = $request->user();
        $mother = MotherStyle::findOrFail($data['mother_style_id']);
        abort_unless($user->hasBuyerAccess((int) $mother->buyer_id), 403);

        $assignee = $followUps->assigneesFor($mother)->firstWhere('id', (int) $data['assigned_to']);

        if (! $assignee) {
            return back()->withInput()->with('warning', 'That person cannot be given a follow-up for this buyer.');
        }

        try {
            $followUps->create($mother, $assignee, $user, $data['due_date'], $data['title'] ?? null, $data['note'] ?? null);
        } catch (\DomainException $e) {
            return back()->withInput()->with('warning', $e->getMessage());
        }

        return back()->with('success', 'Follow-up created for ' . $assignee->name . '.');
    }

    public function done(Request $request, FollowUpTask $task, FollowUps $followUps)
    {
        $this->authorizeChange($request, $task);
        $followUps->finish($task, $request->user());

        return back()->with('success', 'Follow-up marked done.');
    }

    public function reopen(Request $request, FollowUpTask $task, FollowUps $followUps)
    {
        $this->authorizeChange($request, $task);
        $followUps->reopen($task);

        return back()->with('success', 'Follow-up reopened.');
    }

    /** The person it is for, the person who set it, or an admin. */
    private function authorizeChange(Request $request, FollowUpTask $task): void
    {
        $user = $request->user();

        abort_unless(
            $user->hasRole('admin') || in_array($user->id, [$task->assigned_to, $task->created_by], true),
            403
        );
    }
}
