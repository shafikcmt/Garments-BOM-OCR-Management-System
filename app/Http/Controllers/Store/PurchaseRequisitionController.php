<?php

namespace App\Http\Controllers\Store;

use App\Exports\RequisitionDocumentExport;
use App\Http\Controllers\Concerns\AuthorizesStoreCorrections;
use App\Http\Controllers\Controller;
use App\Models\IndentPerson;
use App\Models\IndentSection;
use App\Models\ItemCategory;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionAction;
use App\Models\PurchaseRequisitionItem;
use App\Models\RequisitionDepartmentHead;
use App\Models\StockItem;
use App\Models\User;
use App\Services\DepartmentRequisitionDefaults;
use App\Services\MonthlyRequisitionReportService;
use App\Services\PurchaseRequisitionNumberGenerator;
use App\Services\PurchaseRequisitionService;
use App\Services\RequisitionHeaderResolver;
use App\Services\RequisitionHistory;
use App\Services\RequisitionReviewChain;
use App\Services\RequisitionSignatureResolver;
use App\Services\RequisitionWorkflow;
use App\Services\RequirementVsIssueService;
use App\Services\StockSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * General Stock (module A) — Purchase Requisition, replacing the hand-kept
 * "Month_Of_<Month>.xlsx" workbook.
 *
 * Stage 1: entry (Single Item / Multiple Items) and the requisition list.
 * The reports, PDF/Excel export and the approval actions are stage 2.
 *
 * Single and multi are one record type differing by `mode`, so this controller
 * serves both and they land in the same list.
 */
class PurchaseRequisitionController extends Controller
{
    use AuthorizesStoreCorrections;

    /** Section this controller belongs to, for the section-scoped correction
     *  permissions. The flat store.edit / store.delete still apply too. */
    protected string $storeSection = 'store.requisition';

    public function __construct(
        private readonly PurchaseRequisitionService $requisitions,
        private readonly PurchaseRequisitionNumberGenerator $numbers,
        private readonly RequisitionSignatureResolver $signatures,
        private readonly RequisitionHistory $history,
        private readonly StockSummaryService $stockSummary,
        private readonly RequisitionHeaderResolver $header,
        private readonly RequisitionWorkflow $workflow,
    ) {
    }

    /**
     * The sheet re-read under a row lock, inside the caller's transaction.
     * A double-click's second request waits here, then finds the state
     * already moved on and is refused by the caller's check.
     */
    private function lockRequisition(PurchaseRequisition $requisition): PurchaseRequisition
    {
        return PurchaseRequisition::whereKey($requisition->getKey())->lockForUpdate()->firstOrFail();
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_keys(PurchaseRequisition::statusLabels()))],
            'section' => ['nullable', 'integer', 'exists:indent_sections,id'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $requisitions = PurchaseRequisition::query()
            // Store, Admin and Management still see the whole company. A user
            // from another department, here on store.requisition.view, sees the
            // requisitions they raised themselves.
            ->visibleTo($request->user())
            ->with(['indentSection', 'indentPerson', 'createdBy'])
            ->withCount('items')
            ->withSum('items', 'amount')
            ->when($filters['month'] ?? null, fn ($q, $month) => $q->forMonth($month))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['section'] ?? null, fn ($q, $id) => $q->where('indent_section_id', $id))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $like = '%'.$search.'%';
                // whereLike compiles to ILIKE on PostgreSQL, where a plain LIKE
                // is case-sensitive, and stays LIKE everywhere else.
                $q->where(fn ($w) => $w->whereLike('requisition_no', $like)
                    ->orWhereHas('indentPerson', fn ($p) => $p->whereLike('name', $like))
                    ->orWhereHas('items.stockItem', fn ($i) => $i->whereLike('name', $like))
                    // A line naming something not in the Item Master has no
                    // stockItem to search, so its typed name is searched too —
                    // otherwise the one item nobody can find in the master is
                    // also the one nobody can find on this screen.
                    ->orWhereHas('items', fn ($i) => $i->whereLike('custom_item_name', $like)));
            })
            ->orderByDesc('requisition_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        // The Stock Summary: only for users holding store.requirement_issue.view
        // (and Admin) — the same check as Requirement vs Issue. Nobody else's screen runs its queries. It has
        // its own filters under sum[...], separate from the list below.
        $stockSummary = null;
        $stockSummaryPage = null;

        if (RequirementVsIssueService::canView($request->user())) {
            $summaryInput = (array) $request->query('sum', []);
            $stockSummary = $this->stockSummary->build($request->user(), $summaryInput);
            $stockSummaryPage = $this->stockSummary->page(
                $stockSummary['view'] === StockSummaryService::VIEW_ISSUE ? $stockSummary['issue']['rows'] : $stockSummary['purchase']['rows'],
                (int) $request->query('sum_page', 1),
                'sum_page',
                $request->url(),
                $request->except('sum_page'),
            );
        }

        return view('store.stock.requisitions.index', [
            'requisitions' => $requisitions,
            'filters' => $filters,
            'monthOptions' => $this->monthOptions($filters['month'] ?? null),
            'sections' => IndentSection::selectable()->get(['id', 'name']),
            'statusLabels' => PurchaseRequisition::statusLabels(),
            // Drives the "Requested" tab's badge. Counted only for whoever can
            // open that queue, so a department user's screen runs no extra
            // query for a tab they never see.
            'awaitingCount' => ($request->user() && PurchaseRequisition::worksStoreDesk($request->user()))
                ? PurchaseRequisition::query()->fromDepartments()
                    ->where('status', PurchaseRequisition::STATUS_SUBMITTED)->count()
                : 0,
            // The Department Review tab and its badge, for a department head.
            // Null hides the tab: a user who heads nothing has no such queue.
            'departmentReviewCount' => $this->departmentReviewCount($request->user()),
            'stockSummary' => $stockSummary,
            'stockSummaryPage' => $stockSummaryPage,
        ]);
    }

    /**
     * The entry screen. `mode` selects which of the two forms opens; both post
     * to the same store() below.
     */
    public function create(Request $request)
    {
        $mode = $request->query('mode') === PurchaseRequisition::MODE_SINGLE
            ? PurchaseRequisition::MODE_SINGLE
            : PurchaseRequisition::MODE_MULTI;

        // What a department user's form has fixed for them — section, head,
        // contact, User Dept. — and nothing at all for the Store's own form.
        $defaults = DepartmentRequisitionDefaults::for($request->user());

        return view('store.stock.requisitions.create', $this->formData($defaults) + [
            'mode' => $mode,
            // A preview, not a reservation: whoever saves first takes it, and
            // it changes with the section, so the form recomputes it there too.
            // A department sheet previews under ITS section from the start.
            'nextNo' => $this->numbers->preview($defaults->numberSection()),
        ]);
    }

    /**
     * The Store's review queue — what the departments have sent in.
     *
     * The same list, the same filters and the same partial as index(); what
     * differs is only fromDepartments(), which narrows it to requisitions
     * raised OUTSIDE the Store. It is a separate screen rather than a filter on
     * the main list because it is a work queue: the Store opens it to find what
     * is waiting on them, not to browse.
     */
    public function requested(Request $request)
    {
        abort_unless(
            PurchaseRequisition::worksStoreDesk($request->user()),
            403,
            'Only the Store team can review incoming requisitions.'
        );

        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_keys(PurchaseRequisition::statusLabels()))],
            'section' => ['nullable', 'integer', 'exists:indent_sections,id'],
            'search' => ['nullable', 'string', 'max:100'],
            'ready' => ['nullable', 'boolean'],
        ]);

        $requisitions = PurchaseRequisition::query()
            ->fromDepartments()
            ->with('mergedInto')
            ->when($filters['ready'] ?? false, fn ($q) => $q->readyToMerge())
            ->with(['indentSection', 'indentPerson', 'createdBy'])
            ->withCount('items')
            ->withSum('items', 'amount')
            ->when($filters['month'] ?? null, fn ($q, $month) => $q->forMonth($month))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['section'] ?? null, fn ($q, $id) => $q->where('indent_section_id', $id))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $like = '%'.$search.'%';
                $q->where(fn ($w) => $w->whereLike('requisition_no', $like)
                    ->orWhereHas('indentPerson', fn ($p) => $p->whereLike('name', $like))
                    ->orWhereHas('createdBy', fn ($u) => $u->whereLike('name', $like))
                    ->orWhereHas('items.stockItem', fn ($i) => $i->whereLike('name', $like))
                    ->orWhereHas('items', fn ($i) => $i->whereLike('custom_item_name', $like)));
            })
            // Oldest first: a queue is worked from the end that has waited
            // longest, unlike the main list which reads newest first.
            ->orderByRaw("CASE WHEN status = ? THEN 0 ELSE 1 END", [PurchaseRequisition::STATUS_SUBMITTED])
            ->orderBy('requisition_date')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        return view('store.stock.requisitions.requested', [
            'requisitions' => $requisitions,
            'filters' => $filters,
            'monthOptions' => $this->monthOptions($filters['month'] ?? null),
            'sections' => IndentSection::selectable()->get(['id', 'name']),
            'statusLabels' => PurchaseRequisition::statusLabels(),
            'awaitingCount' => PurchaseRequisition::query()->fromDepartments()
                ->where('status', PurchaseRequisition::STATUS_SUBMITTED)->count(),
            // Decides whether the Edit button is drawn at all — the same
            // question authorizeEditable() asks on the way in, so nobody is
            // offered a button that would refuse them.
            'canEdit' => $this->storeCorrectionAbilities()['edit'],
            'departmentReviewCount' => $this->departmentReviewCount($request->user()),
            // Step 4: received sheets waiting to go into a final sheet.
            'canMerge' => PurchaseRequisition::canMerge($request->user()),
            'readyCount' => PurchaseRequisition::query()->fromDepartments()->readyToMerge()->count(),
        ]);
    }

    /**
     * My Approvals: the sheets waiting for this user's action, and the ones
     * sent back to them. Admin and Management have nothing to approve, so
     * they get a read-only "All Pending" tab instead — every sheet waiting at
     * any stage, limited by the same visibleTo() rule as the main list.
     *
     * The list is RequisitionWorkflow::waitingForOnce(): the same answer, from
     * the same who-can-act rule, as the sidebar badge and the Approve button.
     */
    public function myApprovals(Request $request)
    {
        $user = $request->user();
        $overview = $user->hasAnyRole(['admin', 'management']);
        $tab = ($overview && $request->query('tab') === 'all') ? 'all' : 'mine';

        $waiting = $this->workflow->waitingForOnce($user);

        $returned = PurchaseRequisition::query()
            ->where('created_by', $user->id)
            ->whereNotNull('workflow_version')
            ->where('current_stage', 1)
            ->whereNotIn('status', [PurchaseRequisition::STATUS_DRAFT, PurchaseRequisition::STATUS_CANCELLED])
            ->with('indentSection')
            ->orderByDesc('id')
            ->get();

        $returnNotes = $returned->isEmpty() ? collect() : PurchaseRequisitionAction::query()
            ->whereIn('purchase_requisition_id', $returned->pluck('id'))
            ->whereIn('action', [PurchaseRequisitionAction::RETURNED, PurchaseRequisitionAction::HEAD_RETURNED])
            ->orderBy('id')->get()->keyBy('purchase_requisition_id');

        $all = null;

        if ($tab === 'all') {
            $all = PurchaseRequisition::query()
                ->visibleTo($user)
                ->whereNotNull('workflow_version')
                ->whereNotNull('current_stage')
                ->whereNotIn('status', [PurchaseRequisition::STATUS_DRAFT, PurchaseRequisition::STATUS_CANCELLED])
                ->with(['indentSection', 'createdBy'])
                ->orderBy('submitted_at')
                ->orderBy('id')
                ->paginate(25)
                ->withQueryString();
        }

        $details = $this->workflow->waitingDetails(
            $tab === 'all' ? collect($all->items()) : $waiting->concat($returned)
        );

        return view('store.stock.requisitions.my-approvals', [
            'tab' => $tab,
            'overview' => $overview,
            'waiting' => $waiting,
            'returned' => $returned,
            'returnNotes' => $returnNotes,
            'all' => $all,
            'details' => $details,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | The head's step — section head or department head
    |--------------------------------------------------------------------------
    |
    | A department requisition passes ONE head before the Store: its section
    | head if the section has one, else its department head, else nobody.
    | RequisitionReviewChain decides which (review_level); the heads are
    | named on Admin > Settings > Departments; Admin and Management stand in
    | for any head. The decision is recorded in department_review_status
    | beside `status`, which is not touched.
    */

    /**
     * The Department Review queue — what this user's department has sent up
     * for their check. Admin and Management see every department's.
     */
    public function departmentReview(Request $request)
    {
        $user = $request->user();

        abort_if($this->departmentReviewCount($user) === null, 403,
            'Only a department head can review department requisitions.');

        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $requisitions = PurchaseRequisition::query()
            ->awaitingDepartmentHead($user)
            ->with(['indentSection', 'indentPerson', 'createdBy'])
            ->withCount('items')
            ->withSum('items', 'amount')
            ->when($filters['month'] ?? null, fn ($q, $month) => $q->forMonth($month))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $like = '%'.$search.'%';
                $q->where(fn ($w) => $w->whereLike('requisition_no', $like)
                    ->orWhereHas('createdBy', fn ($u) => $u->whereLike('name', $like))
                    ->orWhereHas('items.stockItem', fn ($i) => $i->whereLike('name', $like))
                    ->orWhereHas('items', fn ($i) => $i->whereLike('custom_item_name', $like)));
            })
            ->orderBy('requisition_date')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        return view('store.stock.requisitions.department-review', [
            'requisitions' => $requisitions,
            'filters' => $filters,
            'monthOptions' => $this->monthOptions($filters['month'] ?? null),
            'departmentReviewCount' => $this->departmentReviewCount($user),
            'awaitingCount' => PurchaseRequisition::seesEveryRequisition($user)
                ? PurchaseRequisition::query()->fromDepartments()
                    ->where('status', PurchaseRequisition::STATUS_SUBMITTED)->count()
                : 0,
        ]);
    }

    /** The head accepts the sheet; it now goes to the Store. */
    public function departmentApprove(Request $request, PurchaseRequisition $requisition)
    {
        // A stage-engine sheet's head check is its head stage.
        if ($requisition->usesStageEngine()) {
            return $this->stageApprove($request, $requisition);
        }

        $this->authorizeVisible($requisition);
        $this->authorizeDepartmentHead($requisition);

        DB::transaction(function () use ($requisition) {
            $locked = $this->lockRequisition($requisition);
            abort_unless($locked->awaitingDepartmentHead(), 422,
                'Only a requisition waiting for a head\'s check can be checked.');

            $locked->update([
                'department_review_status' => PurchaseRequisition::DEPT_REVIEW_APPROVED,
                'department_review_note' => null,
            ] + $locked->headDecisionStamp(auth()->user()));

            $this->history->record($locked, PurchaseRequisitionAction::HEAD_APPROVED, [
                'stage' => RequisitionHistory::headStage($locked),
                'from' => PurchaseRequisition::STATUS_SUBMITTED,
            ]);
        });

        return back()->with('success',
            'Requisition '.$requisition->requisition_no.' checked and sent to Store.');
    }

    /**
     * The head sends the sheet back. It stays Submitted — the requester's own
     * screen reads "Returned by Section Head" / "Returned by Department Head"
     * — and becomes editable to them again, so they can amend it and submit
     * once more. review_level is kept, so the resubmission returns to the
     * same head.
     */
    public function departmentReject(Request $request, PurchaseRequisition $requisition)
    {
        // A stage-engine sheet's head returns it like any other stage.
        if ($requisition->usesStageEngine()) {
            // Same contract as before for this form: the reason is "note".
            $request->validate(['note' => ['required', 'string', 'max:1000']], [
                'note.required' => 'Give the requester a reason so they know what to change.',
            ]);
            $request->merge(['comment' => $request->input('note')]);

            return $this->stageReturn($request, $requisition);
        }

        $this->authorizeVisible($requisition);
        $this->authorizeDepartmentHead($requisition);

        $data = $request->validate([
            'note' => ['required', 'string', 'max:1000'],
        ], [
            'note.required' => 'Give the requester a reason so they know what to change.',
        ]);

        DB::transaction(function () use ($requisition, $data) {
            $locked = $this->lockRequisition($requisition);
            abort_unless($locked->awaitingDepartmentHead(), 422,
                'Only a requisition waiting for a head\'s check can be returned.');

            $locked->update([
                'department_review_status' => PurchaseRequisition::DEPT_REVIEW_REJECTED,
                'department_review_note' => $data['note'],
            ] + $locked->headDecisionStamp(auth()->user()));

            // On the stage engine the sheet is back with its raiser: no stage
            // waits until they resubmit, which starts the next cycle.
            if ($locked->usesStageEngine()) {
                $locked->forceFill(['current_stage' => null])->save();
            }

            // The reason is kept here too: the sheet's own note is cleared
            // when it is resubmitted, the history row is not.
            $this->history->record($locked, PurchaseRequisitionAction::HEAD_RETURNED, [
                'stage' => RequisitionHistory::headStage($locked),
                'from' => PurchaseRequisition::STATUS_SUBMITTED,
                'comment' => $data['note'],
            ]);
        });

        return back()->with('success',
            'Requisition '.$requisition->requisition_no.' returned to the requester.');
    }

    /**
     * The Month filter's choices: the last 24 months, newest first, plus
     * the selected month when it is older. A select rather than the browser's
     * month box, which shows an unreadable "--------- ----" when empty.
     *
     * @return array<string, string> "Y-m" => "September 2026"
     */
    private function monthOptions(?string $selected): array
    {
        $options = [];
        $month = now()->startOfMonth();

        for ($i = 0; $i < 24; $i++) {
            $options[$month->format('Y-m')] = $month->format('F Y');
            $month = $month->copy()->subMonth();
        }

        if ($selected && ! isset($options[$selected])) {
            $options[$selected] = Carbon::createFromFormat('!Y-m', $selected)->format('F Y');
        }

        return $options;
    }

    private function authorizeDepartmentHead(PurchaseRequisition $requisition): void
    {
        // On the stage engine only the head named on the plan acts — not
        // Admin or Management, and never the raiser.
        if ($requisition->usesStageEngine()) {
            abort_unless($this->workflow->canAct($requisition, auth()->user()), 403,
                'Only the '.strtolower($requisition->reviewLevelLabel()).' of this requisition can check it.');
        }

        abort_unless($requisition->isDepartmentHeadFor(auth()->user()), 403,
            'Only the '.strtolower($requisition->reviewLevelLabel()).' of this requisition can check it.');

        abort_unless($requisition->awaitingDepartmentHead(), 422,
            'Only a requisition waiting for a head\'s check can be checked.');
    }

    /**
     * Approve the stage a stage-engine sheet waits at — the head check, the
     * Store Head, HR Audit, Finance & Commercial, Operation Head alike. Only
     * the person named on the sheet's plan; the raiser never.
     */
    public function stageApprove(Request $request, PurchaseRequisition $requisition)
    {
        $this->authorizeVisible($requisition);
        $user = $request->user();

        if ($requisition->isRaisedBy($user)) {
            return back()->with('error', 'You raised this requisition, so another person must approve it.');
        }

        abort_unless($requisition->usesStageEngine(), 422, 'This requisition follows the earlier approval steps.');
        abort_unless($this->workflow->canAct($requisition, $user), 403, 'This requisition is not waiting for your approval.');

        $data = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);

        $stageName = null;
        $storeStage = false;
        $next = null;

        DB::transaction(function () use ($requisition, $user, $data, &$stageName, &$storeStage, &$next) {
            // Re-checked under the lock: a second click finds the stage done.
            $locked = $this->lockRequisition($requisition);
            abort_unless($this->workflow->canAct($locked, $user), 422,
                'This stage has already been approved. Reload the page to see where the requisition is now.');

            $stage = $this->workflow->currentStage($locked);
            $stageName = $stage?->displayLabel();
            $storeStage = $stage?->stamp_source === \App\Models\RequisitionSignatureBlock::STAMP_STORE_HEAD;
            $next = $this->workflow->approve($locked, $user, $data['comment'] ?? null);
        });

        $message = 'Requisition '.$requisition->requisition_no.' approved'
            .($stageName ? ' at '.$stageName : '').'. '
            .($next ? 'Now with '.($next->assignee_name ?: $next->displayLabel()).'.' : 'All stages are complete.');

        // The Store taking the sheet is when it hears about lines the Item
        // Master has never carried — as the old flow's Received did.
        if ($storeStage) {
            $unknown = $requisition->items()->notInItemMaster()->count();

            return redirect()->route('store.stock.requisitions.show', $requisition)->with('success', $message
                .($unknown > 0
                    ? ' '.$unknown.' item'.($unknown === 1 ? ' is' : 's are').' not in the Item Master — see the notice below to add '.($unknown === 1 ? 'it' : 'them').'.'
                    : ''));
        }

        return back()->with('success', $message);
    }

    /**
     * Return a stage-engine sheet to the PREVIOUS stage, with a reason. From
     * the first stage after Prepared it goes back to the raiser. Only the
     * person the sheet waits for; the reason is kept in the history.
     */
    public function stageReturn(Request $request, PurchaseRequisition $requisition)
    {
        $this->authorizeVisible($requisition);
        $user = $request->user();

        abort_unless($requisition->usesStageEngine(), 422, 'This requisition follows the earlier approval steps.');
        abort_unless($this->workflow->canAct($requisition, $user), 403, 'This requisition is not waiting for you.');

        $data = $request->validate([
            'comment' => ['required', 'string', 'max:1000'],
        ], [
            'comment.required' => 'Give a reason for returning it, so they know what to change.',
        ]);

        $next = null;

        DB::transaction(function () use ($requisition, $user, $data, &$next) {
            $locked = $this->lockRequisition($requisition);
            abort_unless($this->workflow->canAct($locked, $user), 422,
                'This stage has already been acted on. Reload the page to see where the requisition is now.');

            $next = $this->workflow->returnToPrevious($locked, $user, $data['comment']);
        });

        return back()->with('success', 'Requisition '.$requisition->requisition_no.' returned to '
            .($next ? ($next->assignee_name ?: $next->displayLabel()) : 'the requester').'.');
    }

    /**
     * Step 3d — the approver the sheet waits for changes line quantities at
     * their stage: up or down, 0 for "not approved", one reason per save.
     * Approving stays a separate click, so the new totals can be read first.
     */
    public function stageQuantities(Request $request, PurchaseRequisition $requisition)
    {
        $this->authorizeVisible($requisition);
        $user = $request->user();

        abort_unless($requisition->usesStageEngine(), 422, 'This requisition follows the earlier approval steps.');
        abort_unless($this->workflow->canAct($requisition, $user), 403, 'This requisition is not waiting for you.');

        $data = $request->validate([
            'qty' => ['required', 'array', 'min:1'],
            'qty.*' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'comment' => ['required', 'string', 'max:1000'],
        ], [
            'qty.*.required' => 'Enter a quantity on every line (0 if it is not approved).',
            'qty.*.numeric' => 'Quantities must be numbers.',
            'qty.*.min' => 'A quantity cannot be below 0.',
            'comment.required' => 'Give a reason for the change.',
        ]);

        $count = 0;

        DB::transaction(function () use ($requisition, $user, $data, &$count) {
            $locked = $this->lockRequisition($requisition);
            abort_unless($this->workflow->canAct($locked, $user), 422,
                'This stage has already been acted on. Reload the page to see where the requisition is now.');

            $count = $this->workflow->changeQuantities($locked, $user, $data['qty'], $data['comment']);
        });

        return back()->with('success', $count.' quantit'.($count === 1 ? 'y' : 'ies').' changed on '
            .$requisition->requisition_no.'. Approve when ready.');
    }

    /**
     * Cancel — close the sheet for good, with a reason. Its raiser while it
     * is theirs (draft or sent back), Admin before approval. Either flow.
     * Replaces deleting a submitted sheet: a cancelled one stays on record.
     */
    public function cancel(Request $request, PurchaseRequisition $requisition)
    {
        $this->authorizeVisible($requisition);
        $user = $request->user();

        if (! $requisition->canBeCancelledBy($user)) {
            return back()->with('error', 'You cannot cancel this requisition. Its requester can while it is with them; Admin can until it is approved.');
        }

        $data = $request->validate([
            'comment' => ['required', 'string', 'max:1000'],
        ], [
            'comment.required' => 'Give a reason for cancelling it.',
        ]);

        DB::transaction(function () use ($requisition, $user, $data) {
            $locked = $this->lockRequisition($requisition);
            abort_unless($locked->canBeCancelledBy($user), 422,
                'This requisition has already moved on. Reload the page to see where it is now.');

            $this->workflow->cancel($locked, $user, $data['comment']);
            $this->releaseMerged($locked, 'Final requisition '.$locked->requisition_no.' was cancelled.');
        });

        return redirect()->route('store.stock.requisitions.show', $requisition)
            ->with('success', 'Requisition '.$requisition->requisition_no.' cancelled.');
    }

    /*
    |--------------------------------------------------------------------------
    | The merge (Step 4)
    |--------------------------------------------------------------------------
    |
    | A department sheet ends at "Received by Store". The Store picks the
    | received sheets of ONE month and merges them into its final Purchase
    | Requisition: a draft, one line per item (quantities added, the User
    | Dept. cell listing every department that asked). The Store checks it,
    | adds its own items if any, and submits — the final sheet then goes
    | Store Head → HR Audit → Finance & Commercial → Operation Head.
    |
    | One month per final sheet: the same items are requested every month,
    | and the monthly report must count each month's demand in that month.
    */

    public function merge(Request $request)
    {
        abort_unless(PurchaseRequisition::canMerge($request->user()), 403,
            'Only the Store team can merge requisitions into a final requisition.');

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ], [
            'ids.required' => 'Select at least one received requisition to merge.',
            'ids.min' => 'Select at least one received requisition to merge.',
        ]);

        $user = $request->user();

        $final = DB::transaction(function () use ($data, $user) {
            $sources = PurchaseRequisition::whereIn('id', $data['ids'])
                ->orderBy('requisition_date')->orderBy('id')
                ->lockForUpdate()->get();

            abort_unless($sources->count() === count($data['ids']), 422,
                'A selected requisition no longer exists. Reload the page and try again.');

            $notReady = $sources->reject(fn (PurchaseRequisition $r) => $r->isReadyToMerge());
            if ($notReady->isNotEmpty()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['ids' => 'Only requisitions received by Store and not yet merged can be merged: '
                    .$notReady->pluck('requisition_no')->implode(', ').'.']);
            }

            $months = $sources->map(fn (PurchaseRequisition $r) => $r->requisition_date?->format('Y-m'))->unique();
            if ($months->count() > 1) {
                throw \Illuminate\Validation\ValidationException::withMessages(['ids' => 'Merge one month at a time. The selection covers '
                    .$months->map(fn ($m) => Carbon::createFromFormat('!Y-m', $m)->format('F Y'))->implode(', ').'.']);
            }

            // Dated in the sources' month: today while that month runs, else
            // the latest source's date — so the month's report carries it.
            $latest = $sources->max('requisition_date');
            $date = now()->format('Y-m') === $months->first() ? now()->startOfDay() : Carbon::parse($latest);

            $sources->load('items', 'indentSection');
            $lines = [];

            foreach ($sources as $source) {
                $dept = $source->indentSection?->name
                    ?: ($source->department_role ? $source->departmentLabel() : null);

                foreach ($source->items as $line) {
                    if ((float) $line->qty_requested <= 0) {
                        continue; // set to 0 — not approved — on the way through
                    }

                    $lines[] = [
                        'stock_item_id' => $line->stock_item_id,
                        'custom_item_name' => $line->stock_item_id ? null : $line->custom_item_name,
                        'specification' => $line->specification,
                        'type' => $line->type,
                        'user_dept' => $line->user_dept ?: $dept,
                        'qty_requested' => (float) $line->qty_requested,
                        'rate_appx' => $line->rate_appx,
                        'remarks' => $line->remarks,
                    ];
                }
            }

            if ($lines === []) {
                throw \Illuminate\Validation\ValidationException::withMessages(['ids' => 'The selected requisitions have no quantities to merge.']);
            }

            $defaults = DepartmentRequisitionDefaults::for($user);

            $final = PurchaseRequisition::create([
                'requisition_no' => $this->numbers->next($defaults->numberSection(), $date),
                'requisition_date' => $date->toDateString(),
                'mode' => PurchaseRequisition::MODE_MULTI,
                'level' => PurchaseRequisition::LEVEL_FINAL,
                'department_role' => null,
                'unit_name' => config('stock.company_name'),
                'status' => PurchaseRequisition::STATUS_DRAFT,
                'remarks' => 'Merged from '.$sources->pluck('requisition_no')->implode(', ').'.',
                'created_by' => $user->id,
            ]);

            $this->syncItems($final, $lines, $date);

            foreach ($sources as $source) {
                $source->forceFill([
                    'merged_into_id' => $final->id,
                    'merged_at' => now(),
                    'merged_by' => $user->id,
                ])->save();

                $this->history->record($source, PurchaseRequisitionAction::MERGED, [
                    'stage' => PurchaseRequisitionAction::STAGE_STORE,
                    'from' => $source->status,
                    'comment' => 'Merged into final requisition '.$final->requisition_no.'.',
                    'meta' => ['merged_into' => $final->requisition_no],
                ]);

                app(\App\Services\RequisitionNotifier::class)->merged($source, $final, $user);
            }

            $this->history->record($final, PurchaseRequisitionAction::MERGED, [
                'stage' => PurchaseRequisitionAction::STAGE_RAISER,
                'comment' => 'Made from '.$sources->count().' department requisition'.($sources->count() === 1 ? '' : 's')
                    .': '.$sources->pluck('requisition_no')->implode(', ').'.',
                'meta' => ['sources' => $sources->pluck('requisition_no')->all()],
            ]);

            return $final;
        });

        return redirect()
            ->route('store.stock.requisitions.edit', $final)
            ->with('success', 'Final requisition '.$final->requisition_no.' created as a draft from '
                .count($data['ids']).' department requisition'.(count($data['ids']) === 1 ? '' : 's')
                .'. Check the quantities, add any Store items, then submit it for approval.');
    }

    /**
     * Free the department sheets merged into this final sheet — it is being
     * deleted or cancelled — so they can be merged again. Recorded on each.
     */
    private function releaseMerged(PurchaseRequisition $final, string $reason): void
    {
        if ($final->isDepartmentLevel()) {
            return;
        }

        PurchaseRequisition::where('merged_into_id', $final->id)->lockForUpdate()->get()
            ->each(function (PurchaseRequisition $source) use ($reason) {
                $source->forceFill(['merged_into_id' => null, 'merged_at' => null, 'merged_by' => null])->save();

                $this->history->record($source, PurchaseRequisitionAction::MERGE_RELEASED, [
                    'stage' => PurchaseRequisitionAction::STAGE_STORE,
                    'from' => $source->status,
                    'comment' => $reason.' This requisition can be merged again.',
                ]);
            });
    }

    /**
     * How many sheets wait on this user as a head — of a section or of a
     * department — or NULL when they head neither, which is what hides the
     * tab.
     */
    private function departmentReviewCount(?\App\Models\User $user): ?int
    {
        return PurchaseRequisition::departmentReviewCountFor($user);
    }

    /**
     * What the head's step is set to when a sheet is submitted: pending at
     * the first level of the chain that applies, or nothing. A final sheet
     * has no such step, and a department with no head goes straight to the
     * Store rather than waiting on nobody.
     *
     * @return array{department_review_status: ?string, review_level: ?string}
     */
    private function reviewOnSubmit(string $level, ?User $requester, ?IndentSection $section, ?string $department): array
    {
        if ($level !== PurchaseRequisition::LEVEL_DEPARTMENT) {
            return ['department_review_status' => null, 'review_level' => null];
        }

        return RequisitionReviewChain::onSubmit($requester, $section, $department);
    }

    /**
     * "Received" — the Store has seen the request.
     *
     * Moves Submitted to Store Reviewed and stamps the existing store_ack_by /
     * store_ack_at columns, which is what the printed sheet's store signature
     * box has always meant. Gated on store.requisition.review, the permission
     * the seeder already grants to store, admin and management.
     */
    public function markReceived(Request $request, PurchaseRequisition $requisition)
    {
        $this->authorizeTransition('store.requisition.review');

        // Refused with a message the user actually sees: the app's 403 page
        // prints a fixed text and would hide the reason.
        if ($requisition->isRaisedBy(auth()->user())) {
            return back()->with('error', 'You raised this requisition, so another person must mark it received.');
        }

        // The old flow's step. A stage-engine sheet is taken by its Store
        // Head stage instead (stageApprove).
        abort_if($requisition->usesStageEngine(), 422, 'This requisition is approved stage by stage. Use Approve at the Store Head stage.');

        $data = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);

        DB::transaction(function () use ($requisition, $data) {
            $locked = $this->lockRequisition($requisition);
            abort_unless($locked->awaitingStoreReceipt(), 422,
                'Only a requisition that has been submitted and not yet received can be marked received.');

            $locked->update([
                'status' => PurchaseRequisition::STATUS_STORE_REVIEWED,
                'store_ack_by' => auth()->id(),
                'store_ack_at' => now(),
            ]);

            $this->history->record($locked, PurchaseRequisitionAction::RECEIVED, [
                'stage' => PurchaseRequisitionAction::STAGE_STORE,
                'from' => PurchaseRequisition::STATUS_SUBMITTED,
                'comment' => $data['comment'] ?? null,
            ]);
        });

        // Lines naming something the Item Master has never carried. The
        // Store is the one who decides whether it should, so the moment they
        // take the sheet is the moment to say so — the show screen carries
        // the per-item action.
        $unknown = $requisition->items()->notInItemMaster()->count();

        return redirect()
            ->route('store.stock.requisitions.show', $requisition)
            ->with('success', 'Requisition '.$requisition->requisition_no.' marked as received by Store.'
                .($unknown > 0
                    ? ' '.$unknown.' item'.($unknown === 1 ? ' is' : 's are').' not in the Item Master — see the notice below to add '.($unknown === 1 ? 'it' : 'them').'.'
                    : ''));
    }

    /**
     * "Approve" — the request is accepted and may go to procurement.
     *
     * Moves Store Reviewed to Approved and stamps approved_by / approved_at.
     *
     * Gated on store.requisition.approve, which PurchaseRequisitionPermissionSeeder
     * deliberately gives to admin and management but NOT to store: whoever
     * raises a requisition must not be the one approving the spend, which is
     * the whole point of the separate signature boxes on the paper form. That
     * rule is left exactly as it was found.
     */
    public function approve(Request $request, PurchaseRequisition $requisition)
    {
        $this->authorizeTransition('store.requisition.approve');

        if ($requisition->isRaisedBy(auth()->user())) {
            return back()->with('error', 'You raised this requisition, so another person must approve it.');
        }

        abort_if($requisition->usesStageEngine(), 422, 'This requisition is approved stage by stage. Use Approve at the current stage.');

        $data = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);

        DB::transaction(function () use ($requisition, $data) {
            $locked = $this->lockRequisition($requisition);
            abort_unless($locked->awaitingApproval(), 422,
                'Only a requisition the Store has received can be approved.');

            $locked->update([
                'status' => PurchaseRequisition::STATUS_APPROVED,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]);

            $this->history->record($locked, PurchaseRequisitionAction::APPROVED, [
                'stage' => PurchaseRequisitionAction::STAGE_FINAL_APPROVAL,
                'from' => PurchaseRequisition::STATUS_STORE_REVIEWED,
                'comment' => $data['comment'] ?? null,
            ]);
        });

        return back()->with('success',
            'Requisition '.$requisition->requisition_no.' approved.');
    }

    /**
     * Asks the workflow permission the same way the route guards do — role OR
     * permission — so a change to one is readable next to the other.
     */
    private function authorizeTransition(string $permission): void
    {
        $user = auth()->user();

        abort_unless($user && $user->can($permission), 403,
            'You do not have permission to take this action on a requisition.');
    }

    public function store(Request $request)
    {
        $defaults = DepartmentRequisitionDefaults::for($request->user());
        $data = $this->applyDefaults($this->validated($request), $defaults);

        $requisition = DB::transaction(function () use ($data, $defaults) {
            // Optional fields are absent from the validated array when they are
            // not posted at all, so every read of one has to tolerate a missing
            // key, not just an empty value.
            $section = ($data['indent_section_id'] ?? null)
                ? IndentSection::find($data['indent_section_id'])
                : null;

            $date = Carbon::parse($data['requisition_date']);

            // A number typed on the form wins — it lets a paper document that
            // already carries a number be entered as-is. Otherwise the
            // allocator takes the next one for this section and month — the
            // chosen section for the Store, the user's own for a department.
            //
            // The key may be ABSENT, not merely empty: the form drops the
            // field's name when the operator never edited the suggestion, so
            // that an untouched preview cannot bypass the allocator.
            $number = ($data['requisition_no'] ?? null)
                ?: $this->numbers->next($defaults->numberSection() ?? $section?->name, $date);

            // Which sheet this is, and whose department: decided by who is
            // raising it, and fixed at creation so a later change of role
            // does not move the sheet to a different head's queue.
            $level = $defaults->level;
            $department = $defaults->department;
            $submitting = $data['action'] === 'submit';

            // Which head (if any) the sheet stops at. Decided against the
            // requester and their section NOW; a returned sheet finds its
            // way back through the chain again on resubmit.
            $review = $submitting
                ? $this->reviewOnSubmit($level, auth()->user(), $section ?: $defaults->section, $department)
                : ['department_review_status' => null, 'review_level' => null];

            $requisition = PurchaseRequisition::create([
                'requisition_no' => $number,
                'requisition_date' => $data['requisition_date'],
                'mode' => $data['mode'],
                'level' => $level,
                'department_role' => $department,
                'unit_name' => $data['unit_name'] ?? config('stock.company_name'),
                'indent_section_id' => $data['indent_section_id'] ?? null,
                'indent_person_id' => $data['indent_person_id'] ?? null,
                'contact' => $data['contact'] ?? null,
                'status' => $submitting
                    ? PurchaseRequisition::STATUS_SUBMITTED
                    : PurchaseRequisition::STATUS_DRAFT,
                'submitted_at' => $submitting ? now() : null,
                'department_review_status' => $review['department_review_status'],
                'review_level' => $review['review_level'],
                'remarks' => $data['remarks'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $this->syncItems($requisition, $data['items'], $date);

            if ($submitting) {
                // Every new submission goes onto the stage engine. Refuses,
                // rolling the whole save back, if an approver is not set or
                // the raiser holds a stage.
                $this->workflow->start($requisition);
                $this->header->snapshot($requisition);
            }

            $this->history->record($requisition, $submitting
                ? PurchaseRequisitionAction::SUBMITTED
                : PurchaseRequisitionAction::CREATED, [
                    'stage' => PurchaseRequisitionAction::STAGE_RAISER,
                ]);

            return $requisition;
        });

        return redirect()
            ->route('store.stock.requisitions.show', $requisition)
            ->with('success', $this->savedMessage($requisition));
    }

    /**
     * Says where the sheet went, not just that it saved: a department user
     * needs to know whether it is now with their head or with the Store.
     */
    private function savedMessage(PurchaseRequisition $requisition): string
    {
        $no = 'Requisition '.$requisition->requisition_no;

        if ($requisition->status !== PurchaseRequisition::STATUS_SUBMITTED) {
            return $no.' saved as draft.';
        }

        if (! $requisition->awaitingDepartmentHead()) {
            return $no.' submitted to Store.';
        }

        // "…to the Cutting section head" / "…to the Production department head".
        $with = $requisition->review_level === RequisitionReviewChain::LEVEL_SECTION
            ? ($requisition->indentSection?->name ?: $requisition->departmentLabel()).' section head'
            : $requisition->departmentLabel().' department head';

        return $no.' submitted to the '.$with.' for checking.';
    }

    /**
     * Refuse a requisition this user is not allowed to see.
     *
     * The list is scoped by visibleTo(), but a scoped user could otherwise
     * still reach any requisition by typing its id into the address bar —
     * hiding a row from a listing is not a guard. Called by every action that
     * takes a single requisition.
     *
     * 404 rather than 403: to someone who may not see it, a requisition raised
     * by another department does not exist, and a 403 would confirm that the
     * number is real.
     */
    private function authorizeVisible(PurchaseRequisition $requisition): void
    {
        abort_unless($requisition->isVisibleTo(auth()->user()), 404);
    }

    public function show(PurchaseRequisition $requisition)
    {
        $this->authorizeVisible($requisition);

        $requisition->load([
            'items.stockItem', 'items.itemCategory',
            'indentSection', 'indentPerson', 'createdBy',
            'departmentCheckedBy', 'sectionCheckedBy', 'storeAckBy', 'accountsAckBy', 'checkedBy', 'approvedBy',
            'actions.user', 'mergedInto', 'mergedSources.indentSection',
        ]);

        $user = auth()->user();

        return view('store.stock.requisitions.show', [
            'requisition' => $requisition,
            'signatures' => $this->signatures->forRequisition($requisition),
            'stageTracker' => $this->workflow->tracker($requisition),
            // Lines the Store may want to add to the Item Master. Offered only
            // to whoever reads every requisition AND may create items — the
            // same permission the Item Master screen's own Add button asks —
            // never to the department that raised the sheet.
            'promotableLines' => ($user && PurchaseRequisition::seesEveryRequisition($user) && $user->can('store.items.create'))
                ? $requisition->items->filter(fn ($i) => $i->isCustomItem())->values()
                : collect(),
            'departmentHeadName' => $requisition->isDepartmentLevel()
                ? RequisitionDepartmentHead::headOf($requisition->department_role)?->name
                : null,
            'statusLabels' => PurchaseRequisition::statusLabels(),
            'typeLabels' => PurchaseRequisitionItem::typeLabels(),
            'canDelete' => $requisition->canBeDeletedBy($user),
            // Stock in Hand and Safety Stock are the Store's figures: shown
            // only to whoever works the Store's side, the same rule the entry
            // form uses to hide its stock chips from a department user.
            'showStockFigures' => $user && PurchaseRequisition::seesEveryRequisition($user),
            // Step 3d: what each changed line was first requested at this
            // round, and whether this viewer may change quantities now (the
            // person the sheet waits for, at their stage).
            'originalQty' => $this->workflow->originalQuantities($requisition),
            'canChangeQty' => $this->workflow->canAct($requisition, $user),
            // Whether this viewer holds the correction permission AND reads the
            // whole company's requisitions — the Store-correction half of
            // authorizeEditable(), asked the same way so the button and the
            // action agree.
            'canCorrect' => auth()->user()
                && PurchaseRequisition::seesEveryRequisition(auth()->user())
                && $this->storeCorrectionAbilities()['edit'],
        ]);
    }

    /**
     * This one requisition as a printable document.
     *
     * Read-only, and built from the same service and the same table partial as
     * the monthly report, so a requisition printed on its own carries exactly
     * the figures it will carry in the month it belongs to.
     */
    public function pdf(PurchaseRequisition $requisition, MonthlyRequisitionReportService $report)
    {
        $this->authorizeVisible($requisition);

        $data = $this->documentData($requisition, $report);

        // Landscape A4: the sheet is 21 columns wide and will not fit portrait.
        return \Barryvdh\DomPDF\Facade\Pdf::loadView('store.stock.requisitions.document-pdf', $data)
            ->setPaper('a4', 'landscape')
            ->download($this->documentFilename($requisition).'.pdf');
    }

    /** The same document as a single-sheet workbook. */
    public function excel(PurchaseRequisition $requisition, MonthlyRequisitionReportService $report)
    {
        $this->authorizeVisible($requisition);

        $data = $this->documentData($requisition, $report);

        return Excel::download(
            new RequisitionDocumentExport($requisition, $data['rows'], $data['title'], $data['signatures'], $data['departmentHeadName']),
            $this->documentFilename($requisition).'.xlsx',
        );
    }

    /**
     * Only a Draft can be reopened. Once submitted the figures on it have been
     * read by someone else, so a change is a correction — that is stage 2 and
     * is permission-gated.
     */
    /**
     * May this user open the edit form on this requisition?
     *
     * Two separate doors, and a user needs only one of them:
     *
     *   1. It is still a DRAFT — isEditable(), unchanged, the requester's own
     *      window.
     *   2. The Store is correcting a department's sheet on the way through —
     *      open across Submitted and Store Reviewed only, and only for someone
     *      who holds the correction permission every other General Stock screen
     *      uses. This is what lets Store fix a quantity before printing it
     *      rather than sending the sheet back.
     *
     * Refusals name which rule stopped them, because "no longer be edited" on
     * an approved requisition and "not your requisition to correct" are
     * different problems with different next steps.
     */
    private function authorizeEditable(PurchaseRequisition $requisition): void
    {
        // A stage-engine sheet is edited by its raiser alone (draft, or sent
        // back to them); approvers change quantities at their own stage.
        if ($requisition->usesStageEngine() && $requisition->isEditable()) {
            abort_unless($requisition->isRaisedBy(auth()->user()), 403,
                'Only the person who raised this requisition can edit it.');

            return;
        }

        if ($requisition->isEditable()) {
            return;
        }

        $user = auth()->user();

        $storeMayCorrect = $user
            && PurchaseRequisition::seesEveryRequisition($user)
            && $requisition->isOpenToStoreCorrection()
            && $user->canAny($this->correctionPermissions(self::PERMISSION_EDIT));

        abort_unless($storeMayCorrect, 403, $requisition->isOpenToStoreCorrection()
            ? 'Correcting a submitted requisition is done by the Store team. Ask them to amend it.'
            : 'This requisition has been approved and can no longer be edited.');
    }

    public function edit(PurchaseRequisition $requisition)
    {
        $this->authorizeVisible($requisition);
        $this->authorizeEditable($requisition);

        $requisition->load('items.stockItem', 'createdBy.requisitionSection');

        return view('store.stock.requisitions.create', $this->formData(DepartmentRequisitionDefaults::for(auth()->user(), $requisition), $requisition) + [
            'mode' => $requisition->mode,
            'requisition' => $requisition,
            'nextNo' => $requisition->requisition_no,
        ]);
    }

    public function update(Request $request, PurchaseRequisition $requisition)
    {
        $this->authorizeVisible($requisition);
        $this->authorizeEditable($requisition);

        $defaults = DepartmentRequisitionDefaults::for($request->user(), $requisition);
        $data = $this->applyDefaults($this->validated($request, $requisition), $defaults);

        // A Store correction, as opposed to the requester finishing their own
        // draft. Decided BEFORE the update, because the status it turns on is
        // the one this save is about to overwrite.
        $isStoreCorrection = ! $requisition->isEditable();

        // Where a (re)submission goes. A returned sheet goes back to the head
        // who returned it while that step still applies; a draft, or a sheet
        // whose head has since changed, goes to the first step that does. A
        // Store correction, or a save as draft, has no step to set.
        $review = (! $isStoreCorrection && $data['action'] === 'submit')
            ? RequisitionReviewChain::onResubmit($requisition)
            : ['department_review_status' => null, 'review_level' => null];

        DB::transaction(function () use ($data, $requisition, $isStoreCorrection, $review) {
            // Re-checked under the lock: a second click of Submit finds the
            // sheet already submitted and is refused instead of saving twice.
            $locked = $this->lockRequisition($requisition);
            abort_unless($isStoreCorrection ? $locked->isOpenToStoreCorrection() : $locked->isEditable(), 422,
                'This requisition has already moved on. Reload the page to see where it is now.');

            $fromStatus = $requisition->status;
            // Sent back by a head (old or new flow) or by any later stage.
            $wasReturned = $requisition->rejectedByDepartmentHead() || $requisition->returnedToRaiser();
            $linesBefore = $isStoreCorrection
                ? $this->history->snapshotLines($requisition->items()->get())
                : [];

            $requisition->update([
                'requisition_no' => ($data['requisition_no'] ?? null) ?: $requisition->requisition_no,
                'requisition_date' => $data['requisition_date'],
                'mode' => $data['mode'],
                'unit_name' => $data['unit_name'] ?? $requisition->unit_name,
                'indent_section_id' => $data['indent_section_id'] ?? null,
                'indent_person_id' => $data['indent_person_id'] ?? null,
                'contact' => $data['contact'] ?? null,

                // A Store correction KEEPS the stage the requisition is at.
                // Without this a sheet the Store had already marked Received
                // would be knocked back to Submitted by the act of fixing a
                // quantity on it — or, on Save Draft, all the way back to
                // Draft and out of the review queue altogether.
                'status' => $isStoreCorrection
                    ? $requisition->status
                    : ($data['action'] === 'submit'
                        ? PurchaseRequisition::STATUS_SUBMITTED
                        : PurchaseRequisition::STATUS_DRAFT),
                'submitted_at' => $isStoreCorrection
                    ? $requisition->submitted_at
                    : ($data['action'] === 'submit' ? now() : null),

                // The requester submitting (or resubmitting a returned sheet)
                // starts the head's step afresh, at the level the chain picks;
                // a draft has no step yet; a Store correction leaves the
                // decision, its level and its stamps exactly as they are.
                'department_review_status' => $isStoreCorrection
                    ? $requisition->department_review_status
                    : $review['department_review_status'],
                'review_level' => $isStoreCorrection
                    ? $requisition->review_level
                    : $review['review_level'],
            ] + ($isStoreCorrection ? [] : PurchaseRequisition::clearedHeadStamps()) + [

                // Who corrected it and when. checked_by / checked_at already
                // existed on this table, already print as "Checked By" in the
                // document's signature block, and were written by nothing —
                // which is exactly this. created_by is untouched, so the sheet
                // still belongs to the department that raised it.
                'checked_by' => $isStoreCorrection ? auth()->id() : $requisition->checked_by,
                'checked_at' => $isStoreCorrection ? now() : $requisition->checked_at,

                'remarks' => $data['remarks'] ?? null,
            ]);

            // Lines are updated in place — see syncItems() — so a line keeps its
            // id across edits for the quantity log and merge links to hang on.
            $this->syncItems($requisition, $data['items'], Carbon::parse($data['requisition_date']));

            if ($isStoreCorrection) {
                $this->history->record($requisition, PurchaseRequisitionAction::CORRECTED, [
                    'stage' => PurchaseRequisitionAction::STAGE_STORE,
                    'from' => $fromStatus,
                    'meta' => ['lines' => $this->history->diffLines(
                        $linesBefore,
                        $this->history->snapshotLines($requisition->items()->get())
                    )],
                ]);
            } elseif ($data['action'] === 'submit') {
                // A draft, or a stage-engine sheet sent back to its raiser,
                // starts (again) on the stage engine — a new cycle. An old-flow
                // sheet returned by its head finishes on the old flow.
                if ($fromStatus === PurchaseRequisition::STATUS_DRAFT || $requisition->usesStageEngine()) {
                    $this->workflow->start($requisition);
                }

                // Every (re)submit refreshes the header snapshot; a Store
                // correction (the branch above) never does.
                $this->header->snapshot($requisition);

                $this->history->record($requisition, $wasReturned
                    ? PurchaseRequisitionAction::RESUBMITTED
                    : PurchaseRequisitionAction::SUBMITTED, [
                        'stage' => PurchaseRequisitionAction::STAGE_RAISER,
                        'from' => $fromStatus,
                    ]);
            }
        });

        return redirect()
            ->route('store.stock.requisitions.show', $requisition)
            ->with('success', $isStoreCorrection
                // Says what actually happened: the sheet stayed where it was in
                // the flow and is still the department's, which is the part a
                // Store user needs to be sure of after correcting someone
                // else's request.
                ? 'Requisition '.$requisition->requisition_no.' corrected by Store. It stays at "'
                    .$requisition->fresh()->progressLabel().'".'
                : $this->savedMessage($requisition->fresh()));
    }

    /**
     * A draft: its raiser or Admin. Anything submitted: Admin only, with a
     * reason. See PurchaseRequisition::canBeDeletedBy(). The history row is
     * written first and survives the sheet (its foreign key nulls out).
     */
    public function destroy(Request $request, PurchaseRequisition $requisition)
    {
        $this->authorizeVisible($requisition);

        // Refused with a message on the page: the 403 page would hide why.
        if (! $requisition->canBeDeletedBy(auth()->user())) {
            return back()->with('error', $requisition->status === PurchaseRequisition::STATUS_DRAFT
                ? 'Only the person who raised this draft, or Admin, can delete it.'
                : 'A submitted requisition cannot be deleted. Cancel it instead, so it stays on record.');
        }

        $data = $request->validate([
            'comment' => [$requisition->deleteNeedsReason() ? 'required' : 'nullable', 'string', 'max:1000'],
        ], [
            'comment.required' => 'Give a reason for deleting a submitted requisition.',
        ]);

        $no = $requisition->requisition_no;

        DB::transaction(function () use ($requisition, $data) {
            $locked = $this->lockRequisition($requisition);

            $this->history->record($locked, PurchaseRequisitionAction::DELETED, [
                'stage' => auth()->user()->hasRole('admin')
                    ? PurchaseRequisitionAction::STAGE_ADMIN
                    : PurchaseRequisitionAction::STAGE_RAISER,
                'from' => $locked->status,
                'to' => null,
                'comment' => $data['comment'] ?? null,
                'meta' => ['lines' => array_values($this->history->snapshotLines($locked->items()->get()))],
            ]);

            $this->releaseMerged($locked, 'Final requisition '.$locked->requisition_no.' was deleted.');

            $locked->delete();
        });

        return redirect()
            ->route('store.stock.requisitions.index')
            ->with('success', 'Requisition '.$no.' deleted.');
    }

    /**
     * Live auto-fill for one item, called as soon as it is chosen on the form.
     *
     * Read-only. Everything here comes from PurchaseRequisitionService, which in
     * turn reads the Stock Report's own service, so the figures always agree
     * with what the Stock Report would print.
     */
    public function itemSnapshot(Request $request, StockItem $stockItem): JsonResponse
    {
        $on = $request->filled('on')
            ? Carbon::parse($request->query('on'))
            : Carbon::now();

        $snapshot = $this->requisitions->snapshot($stockItem->id, $on);

        // array_merge, not "+": the union operator keeps the LEFT side's value
        // for a duplicate key, which would send the raw timestamp instead of
        // the display date the form puts straight into a read-only box.
        $payload = array_merge($snapshot, [
            'uom' => $stockItem->uom,
            // The item master's merged Brand/Specification field. The line's
            // own Specification stays editable — this only pre-fills it.
            'specification' => $stockItem->brand,
            'item_category_id' => $stockItem->item_category_id,
            'last_purchase_date' => $snapshot['last_purchase_date']
                ? Carbon::parse($snapshot['last_purchase_date'])->format('d-M-y')
                : null,
        ]);

        // A department user gets the item master's descriptive fields — Uom,
        // Specification, Category — and none of the Store's figures. Hiding
        // the chips on their form is not enough when the numbers still travel
        // in the response; they are stripped here. The server re-reads the
        // full snapshot when the line is saved, so the frozen figures the
        // Store later sees are unaffected.
        $user = $request->user();
        if (! ($user && PurchaseRequisition::seesEveryRequisition($user))) {
            $payload = array_intersect_key($payload, array_flip(['uom', 'specification', 'item_category_id']));
        }

        return response()->json($payload);
    }

    /** The next Requisition No for a section, so the form can update it live. */
    public function nextNumber(Request $request): JsonResponse
    {
        // A department user's number is fixed to their own section; the
        // Store's follows whichever section it picked on the form.
        $section = DepartmentRequisitionDefaults::for($request->user())->numberSection()
            ?? ($request->filled('section')
                ? IndentSection::find($request->query('section'))?->name
                : null);

        $on = $request->filled('on') ? Carbon::parse($request->query('on')) : Carbon::now();

        return response()->json(['requisition_no' => $this->numbers->preview($section, $on)]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Shared payload for the single-requisition PDF and Excel, so the two can
     * never drift apart.
     *
     * @return array<string, mixed>
     */
    private function documentData(PurchaseRequisition $requisition, MonthlyRequisitionReportService $report): array
    {
        $requisition->load([
            'items.stockItem', 'items.itemCategory',
            'indentSection', 'indentPerson', 'createdBy',
            'departmentCheckedBy', 'sectionCheckedBy', 'storeAckBy', 'accountsAckBy', 'checkedBy', 'approvedBy',
        ]);

        $rows = $report->requisitionRows($requisition);

        // Stock in Hand and Safety Stock are the Store's figures. For anyone
        // else they are blanked, not dropped, so the printed sheet keeps its
        // exact 21-column layout.
        $user = auth()->user();
        if (! ($user && PurchaseRequisition::seesEveryRequisition($user))) {
            $rows = $rows->map(fn ($row) => array_merge($row, ['stock_in_hand' => null, 'safety_stock' => null]));
        }

        return [
            'requisition' => $requisition,
            'rows' => $rows,
            'title' => 'Purchase Requisition',
            // The signature footer, from Admin > Settings — the same boxes the
            // screen shows, so PDF, Excel and screen cannot disagree.
            'signatures' => $this->signatures->forRequisition($requisition),
            // "Name of User" on a department sheet is its head, not a pick
            // from the indent-person list. A value fallback only — the
            // layout is untouched.
            'departmentHeadName' => $requisition->isDepartmentLevel()
                ? RequisitionDepartmentHead::headOf($requisition->department_role)?->name
                : null,
        ];
    }

    /** A Requisition No carries slashes, so it cannot go into a filename as typed. */
    private function documentFilename(PurchaseRequisition $requisition): string
    {
        return 'Purchase-Requisition-'.Str::slug($requisition->requisition_no ?: (string) $requisition->id);
    }

    /** @return array<string, mixed> */
    private function formData(DepartmentRequisitionDefaults $defaults, ?PurchaseRequisition $requisition = null): array
    {
        $user = auth()->user();

        // Anything that would stop this sheet being submitted — an approver
        // Admin has not set, or the raiser holding a stage — said BEFORE the
        // form is filled. Not for an old-flow sheet being corrected: the
        // stage engine does not apply to it.
        $onEngine = ! $requisition || $requisition->status === PurchaseRequisition::STATUS_DRAFT || $requisition->usesStageEngine();
        $workflowProblems = $onEngine
            ? $this->workflow->problems($defaults->level, $requisition?->createdBy ?? $user, $defaults->section, $defaults->department)
            : [];

        return [
            'items' => $this->requisitions->selectableItems(),
            'categories' => ItemCategory::selectable()->get(['id', 'name']),
            'sections' => IndentSection::selectable()->get(['id', 'name']),
            'persons' => IndentPerson::selectable()->get(['id', 'name']),
            'typeLabels' => PurchaseRequisitionItem::typeLabels(),
            'modeLabels' => PurchaseRequisition::modeLabels(),
            'unitName' => config('stock.company_name'),
            'defaults' => $defaults,
            'workflowProblems' => $workflowProblems,
            // Stock, last purchase, rate and amount are the Store's own
            // knowledge. Decided by WHO is looking, not by the sheet's level:
            // a Store user correcting a department's sheet still sees them.
            'hideFigures' => ! ($user && PurchaseRequisition::seesEveryRequisition($user)),
        ];
    }

    /**
     * Overwrite, on a department-level sheet, every field the form has fixed
     * — whatever was posted. The screen locks these boxes, but a locked box
     * is a courtesy; this is the rule.
     *
     *   Section       the user's requisition section, when one is set
     *   Contact       the department head's email, when a head is configured
     *   User Dept.    the section (or department) on every line
     *   Rate Appx.    dropped for anyone outside the Store, so the line takes
     *                 the last purchase rate the way a blank rate always has
     *
     * A final-level sheet passes through untouched.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyDefaults(array $data, DepartmentRequisitionDefaults $defaults): array
    {
        if (! $defaults->isDepartment()) {
            return $data;
        }

        if ($defaults->fixesSection()) {
            $data['indent_section_id'] = $defaults->section->id;
        }

        if ($defaults->fixesHead()) {
            $data['contact'] = $defaults->contact();
            // Requested By is the head, shown by name; the master list of
            // indent persons is not written to for it.
            $data['indent_person_id'] = null;
        }

        $user = auth()->user();
        $storeSide = $user && PurchaseRequisition::seesEveryRequisition($user);
        $userDept = $defaults->userDept();

        foreach ($data['items'] as $i => $line) {
            if ($userDept !== null) {
                $data['items'][$i]['user_dept'] = $userDept;
            }
            if (! $storeSide) {
                $data['items'][$i]['rate_appx'] = null;
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?PurchaseRequisition $existing = null): array
    {
        $unique = 'unique:purchase_requisitions,requisition_no'.($existing ? ','.$existing->id : '');

        $this->dropBlankLines($request);

        $data = $request->validate([
            'action' => ['required', 'in:draft,submit'],
            'mode' => ['required', 'in:'.PurchaseRequisition::MODE_SINGLE.','.PurchaseRequisition::MODE_MULTI],
            'requisition_no' => ['nullable', 'string', 'max:100', $unique],
            'requisition_date' => ['required', 'date'],
            'unit_name' => ['nullable', 'string', 'max:255'],
            'indent_section_id' => ['nullable', 'integer', 'exists:indent_sections,id'],
            'indent_person_id' => ['nullable', 'integer', 'exists:indent_persons,id'],
            'contact' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:2000'],

            'items' => ['required', 'array', 'min:1'],

            // A line names EITHER a master item or a typed one, never neither.
            // required_without on the item is what refuses an empty line —
            // one rule, so an empty line gets one message, not two. nullable
            // is what lets the unused half of the pair arrive blank, which it
            // always does — the form posts both fields on every row.
            // A saved line's own id, posted back on edit. Only matched against
            // THIS sheet's lines in syncItems(), so a foreign id does nothing.
            'items.*.id' => ['nullable', 'integer'],
            'items.*.stock_item_id' => ['nullable', 'required_without:items.*.custom_item_name', 'integer', 'exists:stock_items,id'],
            'items.*.custom_item_name' => ['nullable', 'string', 'max:255'],
            'items.*.specification' => ['nullable', 'string', 'max:255'],
            'items.*.type' => ['nullable', 'in:'.PurchaseRequisitionItem::TYPE_REPLACE.','.PurchaseRequisitionItem::TYPE_NEW],
            'items.*.user_dept' => ['nullable', 'string', 'max:255'],
            'items.*.qty_requested' => ['required', 'numeric', 'min:0.0001'],
            'items.*.rate_appx' => ['nullable', 'numeric', 'min:0'],
            'items.*.remarks' => ['nullable', 'string', 'max:1000'],
        ], [
            'items.required' => 'Add at least one item to the requisition.',
            'items.min' => 'Add at least one item to the requisition.',
            'requisition_no.unique' => 'That Requisition No is already used by another requisition.',
            'items.*.stock_item_id.required_without' => 'Select the :attribute, or tick "Not in Item Master" and type its name.',
            'items.*.qty_requested.min' => 'The :attribute must be more than 0.',
        ], $this->lineAttributes($request));

        // Single mode is one line by definition. Enforced server-side so a
        // replayed or hand-built request cannot slip extra lines past the
        // toggle and produce a "single" requisition with five items on it.
        if ($data['mode'] === PurchaseRequisition::MODE_SINGLE) {
            $data['items'] = array_slice(array_values($data['items']), 0, 1);
        }

        return $data;
    }

    /**
     * Silently drop a line with nothing on it — no item, no typed name, no qty,
     * no remarks — before validating, on Submit and Save as Draft alike. The
     * page does the same, so "line 2" reads the same on both. A partly filled
     * line is kept and validated as ever; if every line was blank, nothing is
     * left and 'items' fails with "Add at least one item".
     */
    private function dropBlankLines(Request $request): void
    {
        $items = $request->input('items');

        if (! is_array($items)) {
            return;
        }

        $blank = fn ($v) => trim((string) $v) === '';

        $kept = array_values(array_filter($items, fn ($line) => ! is_array($line) || ! (
            $blank($line['stock_item_id'] ?? null)
            && $blank($line['custom_item_name'] ?? null)
            && $blank($line['qty_requested'] ?? null)
            && $blank($line['remarks'] ?? null)
        )));

        $request->merge(['items' => $kept]);
    }

    /**
     * Validation attribute names that point at a line number, so an error reads
     * "The item on line 3 field is required" rather than naming items.2.
     *
     * @return array<string, string>
     */
    private function lineAttributes(Request $request): array
    {
        $attributes = [
            'indent_section_id' => 'section',
            'indent_person_id' => 'requested by',
            'requisition_no' => 'requisition no',
        ];

        foreach (array_keys((array) $request->input('items', [])) as $index) {
            $line = ((int) $index) + 1;
            $attributes['items.'.$index.'.stock_item_id'] = 'item on line '.$line;
            $attributes['items.'.$index.'.custom_item_name'] = 'item name on line '.$line;
            $attributes['items.'.$index.'.qty_requested'] = 'qty requested on line '.$line;
            $attributes['items.'.$index.'.rate_appx'] = 'rate on line '.$line;
        }

        return $attributes;
    }

    /**
     * Write the item lines, freezing the derived figures onto each one.
     *
     * The snapshot is taken HERE rather than trusted from the form: the browser
     * showed those numbers to help the requester decide, but what gets stored
     * has to be what the server can vouch for on the requisition date.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function syncItems(PurchaseRequisition $requisition, array $lines, Carbon $on): void
    {
        // The form may carry a line per department; the DOCUMENT carries a line
        // per item, the way the company's requisition workbook has always been
        // written. Merging here means every figure below is read once for one
        // item, and the same stock can no longer be promised to two
        // departments. The departments themselves survive, gathered into the
        // merged line's User Dept./ Section cell.
        $lines = $this->requisitions->mergeLines(array_values($lines));

        // Custom lines carry no id, so they are filtered out here rather than
        // being looked up as item 0. They get the empty snapshot below, which
        // is the honest answer: there is no master record to read stock,
        // safety or last purchase from.
        $itemIds = array_values(array_filter(array_map(
            fn ($line) => $this->requisitions->lineItemId($line),
            $lines
        )));

        $snapshots = $this->requisitions->snapshots($itemIds, $on);
        $stockItems = StockItem::whereIn('id', $itemIds)->get()->keyBy('id');

        // IN PLACE, not delete-and-recreate. A saved line is updated when the
        // form posts it back with its id AND it still names the same item; a
        // line whose item was changed is a different line, so it is replaced.
        // A posted line with no usable id reuses an unclaimed saved line of
        // the same item. Everything left over is inserted; saved lines nobody
        // claimed are deleted. Every figure is recalculated exactly as before.
        $existing = $requisition->exists ? $requisition->items()->get()->keyBy('id') : collect();
        $itemKey = fn ($stockItemId, $customName) => $stockItemId
            ? 'id:'.(int) $stockItemId
            : 'custom:'.mb_strtolower(trim((string) $customName));
        $claimed = [];

        foreach ($lines as $index => $line) {
            $itemId = $this->requisitions->lineItemId($line);
            $stockItem = $itemId ? $stockItems->get($itemId) : null;
            $snapshot = $itemId ? ($snapshots[$itemId] ?? []) : [];

            $qty = (float) $line['qty_requested'];
            $toBeProcured = $this->requisitions->toBeProcured($qty, $snapshot['stock_in_hand'] ?? null);

            // Rate defaults to the last purchase rate but is editable, so an
            // expected price rise can be planned for.
            $typed = $line['rate_appx'] ?? null;
            $rate = ($typed !== null && $typed !== '')
                ? (float) $typed
                : ($snapshot['last_purchase_rate'] ?? null);

            $attributes = [
                'stock_item_id' => $itemId,
                // Only set when there is no master item; the two are never both
                // filled, so a line always has exactly one name.
                'custom_item_name' => $itemId ? null : ($line['custom_item_name'] ?? null),
                'item_category_id' => $stockItem?->item_category_id,
                'sort_order' => $index,
                'uom_snapshot' => $stockItem?->uom,
                'specification' => $line['specification'] ?? $stockItem?->brand,
                'type' => $line['type'] ?? null,
                'user_dept' => $line['user_dept'] ?? null,
                'qty_requested' => $qty,
                'stock_in_hand_snapshot' => $snapshot['stock_in_hand'] ?? null,
                'safety_stock_snapshot' => $snapshot['safety_stock'] ?? null,
                'consumption_last_month_snapshot' => $snapshot['consumption_last_month'] ?? null,
                'last_purchase_qty_snapshot' => $snapshot['last_purchase_qty'] ?? null,
                'last_purchase_rate_snapshot' => $snapshot['last_purchase_rate'] ?? null,
                'last_purchase_date_snapshot' => $snapshot['last_purchase_date'] ?? null,
                'to_be_procured_qty' => $toBeProcured,
                'rate_appx' => $rate,
                'amount' => $rate !== null ? $toBeProcured * $rate : 0,
                'remarks' => $line['remarks'] ?? null,
            ];

            $key = $itemKey($itemId, $attributes['custom_item_name']);
            $sameItem = fn ($saved) => ! isset($claimed[$saved->id])
                && $itemKey($saved->stock_item_id, $saved->custom_item_name) === $key;

            $posted = $existing->get((int) ($line['id'] ?? 0));
            $match = ($posted && $sameItem($posted)) ? $posted : $existing->first($sameItem);

            if ($match) {
                $match->update($attributes);
                $claimed[$match->id] = true;
            } else {
                $requisition->items()->create($attributes);
            }
        }

        $unclaimed = $existing->keys()->reject(fn ($id) => isset($claimed[$id]))->all();

        if ($unclaimed !== []) {
            PurchaseRequisitionItem::whereIn('id', $unclaimed)->delete();
        }
    }
}
