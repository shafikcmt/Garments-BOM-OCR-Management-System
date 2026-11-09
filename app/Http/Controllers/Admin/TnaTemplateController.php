<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\TnaTemplate;
use App\Models\TnaTemplateTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/** Admin > TnA Templates: Time and Action plans, entered on screen. */
class TnaTemplateController extends Controller
{
    public function index()
    {
        $templates = TnaTemplate::with('buyer')->withCount('tasks')->orderBy('name')->get();

        return view('admin.tna.index', [
            'templates' => $templates,
            'emailEnabled' => \App\Services\FollowUps::emailEnabled(),
            'escalateDays' => \App\Services\FollowUps::escalateAfterDays(),
        ]);
    }

    /** Alert settings: daily e-mail and escalation to the supervisor. */
    public function settings(Request $request)
    {
        $data = $request->validate([
            'escalate_after_days' => ['required', 'integer', 'between:0,60'],
        ], ['escalate_after_days.required' => 'Type the number of days (0 switches escalation off).']);

        \App\Models\AppSetting::put(\App\Services\FollowUps::KEY_EMAIL, $request->boolean('email_enabled') ? 1 : 0);
        \App\Models\AppSetting::put(\App\Services\FollowUps::KEY_ESCALATE_DAYS, (int) $data['escalate_after_days']);

        return back()->with('success', 'TnA alert settings saved.');
    }

    public function create()
    {
        return view('admin.tna.form', ['template' => new TnaTemplate(['is_active' => true]), 'tasks' => collect()] + $this->options());
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($data, $request) {
            $template = TnaTemplate::create($data['template'] + ['created_by' => $request->user()->id]);
            $this->syncTasks($template, $data['tasks']);
        });

        return redirect()->route('admin.tna.index')->with('success', 'TnA template saved.');
    }

    public function edit(TnaTemplate $tna)
    {
        return view('admin.tna.form', ['template' => $tna, 'tasks' => $tna->tasks] + $this->options());
    }

    public function update(Request $request, TnaTemplate $tna)
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($data, $tna) {
            $tna->update($data['template']);
            $this->syncTasks($tna, $data['tasks']);
        });

        return redirect()->route('admin.tna.index')->with('success', 'TnA template updated.');
    }

    public function destroy(TnaTemplate $tna)
    {
        // Tasks already made from it stay on people's lists.
        $tna->delete();

        return redirect()->route('admin.tna.index')->with('success', 'TnA template deleted. Tasks already made from it are kept.');
    }

    private function options(): array
    {
        return [
            'buyers' => Buyer::active()->orderBy('buyer_name')->get(),
            'roles' => Role::orderBy('name')->pluck('name'),
            'anchors' => TnaTemplateTask::ANCHORS,
        ];
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'buyer_id' => ['nullable', 'exists:buyers,id'],
            'tasks' => ['required', 'array', 'min:1', 'max:60'],
            'tasks.*.id' => ['nullable', 'integer'],
            'tasks.*.title' => ['required', 'string', 'max:160'],
            'tasks.*.anchor' => ['required', Rule::in(array_keys(TnaTemplateTask::ANCHORS))],
            'tasks.*.offset_days' => ['required', 'integer', 'between:-365,365'],
            'tasks.*.assignee_role' => ['required', 'exists:roles,name'],
            'tasks.*.is_critical' => ['nullable', 'boolean'],
        ], [
            'tasks.required' => 'Add at least one task.',
            'tasks.*.title.required' => 'Every task needs a name.',
            'tasks.*.offset_days.required' => 'Every task needs its days.',
            'tasks.*.assignee_role.required' => 'Every task needs a role.',
        ]);

        return [
            'template' => [
                'name' => $data['name'],
                'buyer_id' => $data['buyer_id'] ?? null,
                'is_active' => $request->boolean('is_active'),
                'auto_apply' => $request->boolean('auto_apply'),
            ],
            'tasks' => array_values($data['tasks']),
        ];
    }

    /** Keep task ids for tasks still listed (so made tasks stay linked), drop the removed ones. */
    private function syncTasks(TnaTemplate $template, array $tasks): void
    {
        $keep = [];
        foreach ($tasks as $position => $row) {
            $attrs = ['title' => $row['title'], 'anchor' => $row['anchor'], 'offset_days' => (int) $row['offset_days'], 'assignee_role' => $row['assignee_role'], 'is_critical' => ! empty($row['is_critical']), 'position' => $position];
            $task = ! empty($row['id']) ? $template->tasks()->whereKey($row['id'])->first() : null;
            if ($task) {
                $task->update($attrs);
            } else {
                $task = $template->tasks()->create($attrs);
            }
            $keep[] = $task->id;
        }
        $template->tasks()->whereNotIn('id', $keep)->delete();
    }
}
