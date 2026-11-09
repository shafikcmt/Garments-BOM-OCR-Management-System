<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\Meeting;
use App\Services\Meetings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Order progress meetings: minutes and action items. Merchandising and Admin
 * record them for the buyers they hold; Management reads. Action items are
 * follow-up tasks, finished from the to-do list.
 */
class MeetingController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $buyers = $this->buyers($request);
        $buyer = $buyers->firstWhere('id', (int) $request->query('buyer'));

        $meetings = Meeting::with(['buyer:id,buyer_name', 'motherStyle:id,style_no'])
            ->withCount(['actions', 'actions as open_actions_count' => fn ($q) => $q->where('status', 'open')])
            ->whereIn('buyer_id', $buyer ? [$buyer->id] : $buyers->pluck('id'))
            ->orderByDesc('meeting_date')->orderByDesc('id')->limit(200)->get();

        return view('merchant.meetings', [
            'meetings' => $meetings, 'buyers' => $buyers, 'buyer' => $buyer,
            'canEdit' => $user->hasAnyRole(['merchant', 'admin']), 'today' => Carbon::today(),
        ]);
    }

    public function create(Request $request, Meetings $meetings)
    {
        abort_unless($request->user()->hasAnyRole(['merchant', 'admin']), 403);

        return view('merchant.meeting-form', ['buyers' => $this->buyers($request), 'people' => $meetings->assignees(), 'today' => Carbon::today()]);
    }

    public function store(Request $request, Meetings $meetings)
    {
        abort_unless($request->user()->hasAnyRole(['merchant', 'admin']), 403);
        $data = $this->validated($request);
        abort_unless($request->user()->hasBuyerAccess((int) $data['buyer_id']), 403);

        $style = $this->resolveStyle($meetings, $data);
        if ($style === false) {
            return back()->withInput()->withErrors(['style_no' => 'This buyer has no style with that number.']);
        }
        $meetingData = collect($data)->only(['buyer_id', 'title', 'meeting_date', 'attendees', 'minutes', 'next_meeting_date'])->all() + ['mother_style_id' => $style?->id];

        try {
            $meeting = $meetings->create($meetingData, $this->actions($request), $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->with('warning', $e->getMessage());
        }

        return redirect()->route('merchant.meetings.show', $meeting)->with('success', 'Meeting saved.');
    }

    public function show(Request $request, Meeting $meeting, Meetings $meetings)
    {
        abort_unless($request->user()->hasBuyerAccess((int) $meeting->buyer_id), 403);
        $meeting->load(['buyer', 'motherStyle', 'creator:id,name', 'actions.assignee:id,name']);

        return view('merchant.meeting', [
            'meeting' => $meeting, 'people' => $meetings->assignees(),
            'canEdit' => $request->user()->hasAnyRole(['merchant', 'admin']), 'today' => Carbon::today(),
        ]);
    }

    public function update(Request $request, Meeting $meeting)
    {
        $this->authorizeEdit($request, $meeting);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'meeting_date' => ['required', 'date'],
            'attendees' => ['nullable', 'string', 'max:500'],
            'minutes' => ['nullable', 'string', 'max:20000'],
            'next_meeting_date' => ['nullable', 'date', 'after_or_equal:meeting_date'],
        ], ['title.required' => 'Type a title for the meeting.', 'next_meeting_date.after_or_equal' => 'The next meeting cannot be before this one.']);
        $meeting->update($data + ['updated_by' => $request->user()->id]);

        return back()->with('success', 'Meeting updated.');
    }

    public function addActions(Request $request, Meeting $meeting, Meetings $meetings)
    {
        $this->authorizeEdit($request, $meeting);
        $request->validate($this->actionRules(), $this->actionMessages());
        $actions = $this->actions($request);
        if ($actions === []) {
            return back()->with('warning', 'Write at least one action.');
        }

        try {
            $n = $meetings->addActions($meeting, $actions, $request->user());
        } catch (\DomainException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', $n . ' action(s) added.');
    }

    public function destroy(Request $request, Meeting $meeting)
    {
        $this->authorizeEdit($request, $meeting);
        $meeting->delete();

        return redirect()->route('merchant.meetings.index')->with('success', 'Meeting deleted. Its action items stay on people\'s to-do lists.');
    }

    private function buyers(Request $request)
    {
        $user = $request->user();

        return Buyer::active()->when($user->isBuyerConfined(), fn ($q) => $q->whereIn('id', $user->merchantBuyerIds()))->orderBy('buyer_name')->get();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'buyer_id' => ['required', 'integer', 'exists:buyers,id'],
            'style_no' => ['nullable', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:160'],
            'meeting_date' => ['required', 'date'],
            'attendees' => ['nullable', 'string', 'max:500'],
            'minutes' => ['nullable', 'string', 'max:20000'],
            'next_meeting_date' => ['nullable', 'date', 'after_or_equal:meeting_date'],
        ] + $this->actionRules(), ['title.required' => 'Type a title for the meeting.', 'buyer_id.required' => 'Choose the buyer.', 'next_meeting_date.after_or_equal' => 'The next meeting cannot be before this one.'] + $this->actionMessages());
    }

    /** @return \App\Models\MotherStyle|null|false false = a style number was typed but not found */
    private function resolveStyle(Meetings $meetings, array $data)
    {
        if (trim((string) ($data['style_no'] ?? '')) === '') {
            return null;
        }

        return $meetings->styleFor((int) $data['buyer_id'], $data['style_no']) ?? false;
    }

    private function actionRules(): array
    {
        return [
            'actions' => ['nullable', 'array', 'max:30'],
            'actions.*.title' => ['nullable', 'string', 'max:160'],
            'actions.*.assigned_to' => ['nullable', 'integer'],
            'actions.*.due_date' => ['nullable', 'date'],
        ];
    }

    private function actionMessages(): array
    {
        return ['actions.*.due_date.date' => 'Every action needs a valid due date.'];
    }

    /** Action rows with a title; each then needs a person and a date. @return list<array{title: string, assigned_to: int, due_date: string}> */
    private function actions(Request $request): array
    {
        $rows = collect($request->input('actions', []))->filter(fn ($a) => trim((string) ($a['title'] ?? '')) !== '')->values();
        foreach ($rows as $i => $a) {
            if (empty($a['assigned_to']) || empty($a['due_date'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['actions' => 'Every action needs a person and a due date: "' . $a['title'] . '".']);
            }
        }

        return $rows->map(fn ($a) => ['title' => trim($a['title']), 'assigned_to' => (int) $a['assigned_to'], 'due_date' => $a['due_date']])->all();
    }

    private function authorizeEdit(Request $request, Meeting $meeting): void
    {
        abort_unless($request->user()->hasAnyRole(['merchant', 'admin']) && $request->user()->hasBuyerAccess((int) $meeting->buyer_id), 403);
    }
}
