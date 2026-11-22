<?php

namespace App\Http\Controllers\SupplyChain;

use App\Http\Controllers\Controller;
use App\Mail\PaymentRequestMail;
use App\Models\BookingPo;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\ExcelFile;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestItem;
use App\Models\PraApproval;
use App\Models\PraApprover;
use App\Models\User;
use App\Services\BookingPoSourceService;
use App\Services\PraApprovalService;
use App\Services\PraStageResolver;
use App\Services\PraWorkflow;
use App\Services\StyleBudgetService;
use App\Support\Concerns\ScopesToAssignedBuyers;
use App\Support\PaymentRequestSettings;
use App\Support\PraStageActors;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class PaymentRequestController extends Controller
{
    use ScopesToAssignedBuyers;

    public function __construct(
        protected BookingPoSourceService $sourceService,
        protected PraApprovalService $approvalService,
        protected StyleBudgetService $budgetService,
        protected PraWorkflow $workflow,
        protected PraStageResolver $resolver,
    ) {
    }

    /**
     * The people the creator may name for step 2 (Merchant Team Check) of a PRA
     * that is about to be created from these snapshots.
     *
     * THE DROPDOWN IS THE RESOLVER'S ANSWER, NOTHING ELSE. There is no saved PRA
     * at preview time, so the buyers are read from the snapshots' source files
     * — the same excelFile.buyer_id the resolver reads off a saved PRA's items —
     * and handed to resolveForBuyers(). Anyone not in this list cannot be
     * chosen on screen, and store() checks the posted id against the same list
     * before writing anything, so the UI and the guard cannot disagree.
     *
     * Null when the departmental flow is off: the legacy modal has its own
     * pickers and must not grow a control the legacy path never reads.
     */
    protected function stepTwoAssignees(\Illuminate\Support\Collection $snapshots): ?PraStageActors
    {
        if (! PaymentRequestSettings::departmentalFlowEnabled()) {
            return null;
        }

        $fileIds = $snapshots
            ->map(fn (array $row) => $row['excel_file_id'] ?? null)
            ->filter()
            ->unique()
            ->values();

        $buyerIds = $fileIds->isEmpty()
            ? []
            : ExcelFile::query()->whereIn('id', $fileIds->all())->whereNotNull('buyer_id')->pluck('buyer_id')->all();

        return $this->resolver->resolveForBuyers(PraStageResolver::SEQ_MERCHANT_CHECK, $buyerIds);
    }

    /*
    |--------------------------------------------------------------------------
    | Buyer access
    |--------------------------------------------------------------------------
    |
    | A PRA carries no buyer of its own. Its items do — each holds the
    | excel_file_id of the BOM line its PO came from — so a PRA is within a
    | user's buyer access when ANY of its items is. Any, not all: a PRA can
    | combine POs, and refusing the whole document because one line sits with
    | another buyer would hide work the user genuinely raised.
    |
    | NEVER matched on payment_request_items.buyer_name. It is free text copied
    | from a BOM cell a merchant can edit; the file's buyer_id is the only
    | trustworthy answer.
    */

    /**
     * Role first, then buyer — the choke point for every {paymentRequest}
     * route. REFUSES rather than hiding: a hand-made request naming another
     * buyer's PRA id must not read, email or resubmit it.
     */
    protected function authorizePaymentRequest(PaymentRequest $paymentRequest): void
    {
        abort_if(! auth()->user()?->hasRole('supply_chain'), 403);

        if (! $this->buyerScopeViewer()?->isBuyerConfined()) {
            return;
        }

        $paymentRequest->loadMissing('items.excelFile');

        $reachable = $paymentRequest->items->isEmpty()
            || $paymentRequest->items->contains(
                fn (PaymentRequestItem $item) => $this->allowsBuyerAccess(
                    $item->excelFile?->buyer_id === null ? null : (int) $item->excelFile->buyer_id
                )
            );

        abort_if(! $reachable, 403, 'This payment request belongs to a buyer outside your buyer access.');
    }

    /**
     * Refuse a PO selection that reaches past this user's buyers.
     *
     * Guards both preview and store. The pending list already hides such POs,
     * so reaching here means the id was supplied by hand — which is exactly the
     * case that must be refused rather than quietly filtered down to nothing.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $selectedIds
     */
    protected function assertBuyerAccessForBookingPoIds($selectedIds): void
    {
        if (! $this->buyerScopeViewer()?->isBuyerConfined()) {
            return;
        }

        BookingPo::query()
            ->whereIn('id', $selectedIds->all())
            ->with('excelFile')
            ->get()
            ->each(function (BookingPo $bookingPo) {
                $buyerId = $bookingPo->excelFile?->buyer_id;

                $this->assertBuyerAccess($buyerId === null ? null : (int) $buyerId);
            });
    }

    /**
     * Active users in the admin-managed PRA approver pool, for the creator's
     * "Send for approval to" selection.
     */
    protected function approverPool(): \Illuminate\Support\Collection
    {
        return PraApprover::where('is_active', true)
            ->with('user')
            ->get()
            ->map(fn (PraApprover $approver) => $approver->user)
            ->filter()
            ->sortBy('name')
            ->values();
    }

    /**
     * Active pooled users flagged as eligible checkers, for the creator's
     * "Send for check to" selection (sequential Check -> Approve flow).
     */
    protected function checkerPool(): \Illuminate\Support\Collection
    {
        return PraApprover::where('is_active', true)
            ->where('can_check', true)
            ->with('user')
            ->get()
            ->map(fn (PraApprover $approver) => $approver->user)
            ->filter()
            ->sortBy('name')
            ->values();
    }

    public function index(Request $request)
    {
        abort_if(! auth()->user()?->hasRole('supply_chain'), 403);

        [$pendingRows, $filterOptions, $kpis, $activeFilters] = $this->pendingPaymentRows($request);

        $paymentRequestsQuery = PaymentRequest::query()
            ->with(['createdBy'])
            ->latest('id');

        $this->applyBuyerScopeToPaymentRequests($paymentRequestsQuery);

        $paymentRequests = $paymentRequestsQuery
            ->paginate(20, ['*'], 'requests_page')
            ->withQueryString();

        return view('supply-chain.payment-requests.index', compact('pendingRows', 'filterOptions', 'kpis', 'activeFilters', 'paymentRequests'));
    }

    public function create(Request $request)
    {
        return $this->index($request);
    }

    public function preview(Request $request)
    {
        abort_if(! auth()->user()?->hasRole('supply_chain'), 403);

        $validated = $request->validate([
            'booking_po_ids' => ['required', 'array', 'min:1'],
            'booking_po_ids.*' => ['integer', 'exists:booking_pos,id'],
            'payment_required_date' => ['nullable', 'date'],
            'pp_free' => ['nullable', 'array'],
            'pp_free.*' => ['in:0,1'],
        ]);

        $selectedIds = collect($validated['booking_po_ids'])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $ppChoices = (array) ($validated['pp_free'] ?? []);

        $paymentRequiredInput = $validated['payment_required_date'] ?? PaymentRequestSettings::defaultPaymentRequiredDate()->toDateString();
        $snapshots = $this->snapshotsForSelectedBookingPos($selectedIds)
            ->map(fn (array $snapshot) => array_replace($snapshot, [
                'payment_required_date' => $paymentRequiredInput,
            ]))
            ->values();
        $snapshots = $this->applyPpChoices($snapshots, $ppChoices);

        if ($snapshots->isEmpty()) {
            return redirect()
                ->route('supply_chain.payment_requests.index')
                ->with('warning', 'No eligible PI received / payment pending row was selected. Please check PI Number, PI Status and Payment Status.');
        }

        $paymentRequest = new PaymentRequest([
            'request_no' => 'PREVIEW-' . now()->format('Ymd'),
            'supplier_name' => $this->uniqueText($snapshots, 'supplier_name'),
            'buyer_name' => $this->uniqueText($snapshots, 'buyer_name'),
            'season_name' => $this->uniqueText($snapshots, 'season_name'),
            'total_pi_amount' => $snapshots->sum(fn (array $row) => (float) ($row['pi_amount'] ?? 0)),
            'status' => 'preview',
            'created_by' => auth()->id(),
            'data' => [
                'source' => 'supply_chain_payment_request_preview',
            ],
            // THE PREVIEW MUST LOOK LIKE THE DOCUMENT CREATE PRA WILL PRODUCE.
            // Unstamped, this unsaved model defaults to flow 1 and the sheet
            // prints the three fixed legacy officers on a PRA that is about to
            // join the departmental chain — a document whose signature area
            // changes shape the moment the button is pressed. Same source of
            // truth as store(): the toggle decides the flow, the creator's own
            // signature fills Prepared By. Nothing is persisted here.
            'flow_version' => PaymentRequestSettings::departmentalFlowEnabled()
                ? PaymentRequest::FLOW_DEPARTMENTAL
                : PaymentRequest::FLOW_LEGACY,
            'prepared_signature_path' => PaymentRequestSettings::departmentalFlowEnabled()
                ? auth()->user()?->signature_path
                : null,
        ]);
        $paymentRequest->created_at = now();

        $approvalRows = $this->approvalRowsFromSnapshots($snapshots);
        $summary = $this->summaryFromApprovalRows($approvalRows);
        $isPreview = true;
        $bookingPoIds = $selectedIds->all();
        $approverPool = $this->approverPool();
        $checkerPool = $this->checkerPool();
        $budgetCheck = $this->budgetService->evaluate($snapshots);
        $canOverrideBudget = (bool) auth()->user()?->can('override-style-budget');
        $createOnDepartmentalFlow = PaymentRequestSettings::departmentalFlowEnabled();
        $stepTwoAssignees = $this->stepTwoAssignees($snapshots);
        $ppLines = $snapshots
            ->filter(fn (array $row) => (float) ($row['pp_qty_number'] ?? 0) > 0)
            ->map(fn (array $row) => [
                'key' => $this->ppLineKey($row),
                'po_no' => $row['po_no'] ?? '',
                'style' => $row['style_name'] ?? '',
                'item' => $row['material_description'] ?? '',
                'qty' => (float) ($row['qty'] ?? 0),
                'pp_qty' => (float) $row['pp_qty_number'],
                'rate' => (float) ($row['pi_rate'] ?? 0),
                'free' => (bool) ($row['pp_free'] ?? false),
                'amount' => (float) ($row['pi_amount'] ?? 0),
            ])->values();

        return view('supply-chain.payment-requests.show', compact('paymentRequest', 'summary', 'approvalRows', 'isPreview', 'ppLines', 'bookingPoIds', 'paymentRequiredInput', 'approverPool', 'checkerPool', 'budgetCheck', 'canOverrideBudget', 'createOnDepartmentalFlow', 'stepTwoAssignees'));
    }

    /**
     * Whether a newly created PRA joins the 6-step departmental chain.
     *
     * NOW THE FALLBACK DEFAULT, not the live answer. Ask
     * PaymentRequestSettings::departmentalFlowEnabled(), which reads the admin
     * toggle and lands here only when that setting has never been written — so
     * a database that has not seen the settings screen behaves exactly as it did
     * before the toggle existed.
     *
     * This started as a constant on the reasoning that a runtime toggle for an
     * access decision is a change with no diff and no review. That reasoning was
     * right about access and wrong about this: flow_version is stamped onto the
     * row at creation and every later decision reads the ROW, never the setting.
     * The toggle picks a default at birth; it governs nothing that already
     * exists. A genuine access toggle would still belong in a commit.
     */
    public const CREATE_ON_DEPARTMENTAL_FLOW = true;

    public function store(Request $request)
    {
        abort_if(! auth()->user()?->hasRole('supply_chain'), 403);

        $validated = $request->validate([
            'booking_po_ids' => ['required', 'array', 'min:1'],
            'booking_po_ids.*' => ['integer', 'exists:booking_pos,id'],
            'payment_required_date' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'checker_id' => ['nullable', 'integer'],
            'approver_ids' => ['nullable', 'array'],
            'approver_ids.*' => ['integer'],
            // Departmental flow only: the optional primary assignee for step 2.
            // Ignored entirely on the legacy path — see below.
            'assignee_step2' => ['nullable', 'integer'],
            // Supporting documents (PI copy, etc.) — optional.
            'pp_free' => ['nullable', 'array'],
            'pp_free.*' => ['in:0,1'],
            'documents' => ['nullable', 'array', 'max:10'],
            'documents.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,xls,xlsx,csv,doc,docx'],
        ], [
            'documents.max' => 'You can upload up to 10 documents at a time.',
            'documents.*.max' => 'Each document must be 10 MB or smaller.',
            'documents.*.mimes' => 'Documents must be PDF, image, Excel, CSV or Word files.',
        ]);

        // Only users that are currently in the active approver pool may be
        // selected — anything else is silently dropped.
        $approverUserIds = collect($validated['approver_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique();
        $approverUserIds = PraApprover::where('is_active', true)
            ->whereIn('user_id', $approverUserIds)
            ->pluck('user_id');

        // The checker must be an active pooled user flagged as a checker.
        $checkerUserId = $validated['checker_id'] ?? null;
        if ($checkerUserId) {
            $checkerUserId = PraApprover::where('is_active', true)
                ->where('can_check', true)
                ->where('user_id', (int) $checkerUserId)
                ->value('user_id');
        }

        $selectedIds = collect($validated['booking_po_ids'])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $paymentRequiredDate = $validated['payment_required_date'] ?? PaymentRequestSettings::defaultPaymentRequiredDate()->toDateString();
        $snapshots = $this->snapshotsForSelectedBookingPos($selectedIds)
            ->map(fn (array $snapshot) => array_replace($snapshot, [
                'payment_required_date' => $paymentRequiredDate,
            ]))
            ->values();
        $snapshots = $this->applyPpChoices($snapshots, (array) ($validated['pp_free'] ?? []));

        if ($snapshots->isEmpty()) {
            return back()->with('warning', 'No eligible PI received / payment pending row was selected. Please check PI Number, PI Status and Payment Status.');
        }

        // Style budget guard: hard-block when a style would exceed its budget,
        // unless an authorised user overrides with a reason (recorded + logged).
        $overrideMeta = null;
        $budgetCheck = $this->budgetService->evaluate($snapshots);

        if ($budgetCheck['exceeded']) {
            $wantsOverride = $request->boolean('budget_override');
            $canOverride = (bool) auth()->user()?->can('override-style-budget');
            $reason = trim((string) $request->input('budget_override_reason'));
            $overLines = collect($budgetCheck['lines'])->where('over', true);

            if (! ($wantsOverride && $canOverride && $reason !== '')) {
                $detail = $overLines
                    ->map(fn ($l) => $l['style'] . ' (budget $' . number_format($l['budget'], 2)
                        . ', would reach $' . number_format($l['projected'], 2)
                        . ' — over by $' . number_format($l['over_by'], 2) . ')')
                    ->implode('; ');

                $message = $canOverride
                    ? 'Style budget exceeded — ' . $detail . '. Tick the override box and give a reason to proceed.'
                    : 'Style budget exceeded — PRA blocked. ' . $detail . '. Ask an authorised user to override or update the budget.';

                return back()->with('error', $message);
            }

            $overrideMeta = [
                'by' => auth()->id(),
                'by_name' => auth()->user()?->name,
                'reason' => $reason,
                'at' => now()->toDateTimeString(),
                'lines' => $overLines->values()->all(),
            ];
            Log::warning('Style budget override on PRA creation', $overrideMeta);
        }

        // Safety guard against duplicate PRAs (race condition / direct request):
        // block any selected PO/PI already covered by a live (non-rejected) PRA.
        $activeKeySet = $this->activePraKeys()->flip();
        $duplicates = $snapshots
            ->filter(function (array $row) use ($activeKeySet) {
                $key = $this->praKey($row['po_no'] ?? null, $row['pi_number'] ?? null);

                return $key !== null && $activeKeySet->has($key);
            })
            ->map(fn (array $row) => trim(($row['po_no'] ?? '-') . ' / ' . ($row['pi_number'] ?? '-')))
            ->unique()
            ->values();

        if ($duplicates->isNotEmpty()) {
            return back()->with('warning', 'A Payment Request Approval already exists for: '
                . $duplicates->take(10)->implode(', ')
                . '. These PO/PI cannot be used to create another PRA.');
        }

        // THE PRIMARY ASSIGNEE FOR STEP 2, checked BEFORE anything is written.
        // The posted id must be in the resolver's eligible set for this PRA's
        // buyers — the same list the dropdown was built from. A miss is a stale
        // form or a hand-made request, and it is refused here rather than
        // after a PRA has been created that then cannot be assigned. Null when
        // the departmental flow is off, so the legacy path never sees this.
        $stepTwoAssignees = $this->stepTwoAssignees($snapshots);
        $assigneeStepTwo = null;

        if ($stepTwoAssignees && ! empty($validated['assignee_step2'])) {
            $assigneeStepTwo = (int) $validated['assignee_step2'];

            if (! $stepTwoAssignees->includes($assigneeStepTwo)) {
                return back()->with('warning', 'The person selected for the Merchant Team Check step is not eligible to act on this PRA. Choose someone from the list, or leave it automatic.');
            }
        }

        // Snapshot the creator's personal signature for the Prepared By box, but
        // only when the PRA actually enters an approval flow. A departmental PRA
        // ALWAYS enters one — it has no picked checker or approvers to count, so
        // the legacy test alone left every flow-2 Prepared By unsnapshotted and
        // reading the creator's LIVE signature, which a later upload would
        // silently rewrite on an already-issued document.
        $hasFlow = $checkerUserId || $approverUserIds->isNotEmpty() || PaymentRequestSettings::departmentalFlowEnabled();
        $creatorSignaturePath = $hasFlow ? (auth()->user()?->signature_path) : null;

        $paymentRequest = DB::transaction(function () use ($snapshots, $validated, $paymentRequiredDate, $approverUserIds, $checkerUserId, $creatorSignaturePath, $overrideMeta) {
            $status = $checkerUserId
                ? PaymentRequest::STATUS_PENDING_CHECK
                : ($approverUserIds->isNotEmpty() ? PaymentRequest::STATUS_PENDING_APPROVAL : 'draft');

            $paymentRequest = PaymentRequest::create([
                'request_no' => $this->nextRequestNo(),
                'supplier_name' => $this->uniqueText($snapshots, 'supplier_name'),
                'buyer_name' => $this->uniqueText($snapshots, 'buyer_name'),
                'season_name' => $this->uniqueText($snapshots, 'season_name'),
                'total_pi_amount' => $snapshots->sum(fn (array $row) => (float) ($row['pi_amount'] ?? 0)),
                'status' => $status,
                'created_by' => auth()->id(),
                'prepared_signature_path' => $creatorSignaturePath,
                'remarks' => $validated['remarks'] ?? null,
                'data' => [
                    'pi_numbers' => $snapshots->pluck('pi_number')->filter()->unique()->values()->all(),
                    'po_numbers' => $snapshots->pluck('po_no')->filter()->unique()->values()->all(),
                    'approval_filters' => $this->approvalFiltersFromSnapshots($snapshots),
                    'payment_status_summary' => $snapshots->countBy('payment_status')->all(),
                    'payment_required_date' => $paymentRequiredDate,
                    'source' => 'supply_chain_payment_request',
                    'budget_override' => $overrideMeta,
                ],
            ]);

            foreach ($snapshots as $snapshot) {
                PaymentRequestItem::create([
                    'payment_request_id' => $paymentRequest->id,
                    'booking_po_id' => $snapshot['booking_po_id'] ?? null,
                    'excel_file_id' => $snapshot['excel_file_id'] ?? null,
                    'excel_row_id' => $snapshot['excel_row_id'] ?? null,
                    'po_no' => $snapshot['po_no'] ?? null,
                    'pi_number' => $snapshot['pi_number'] ?? null,
                    'pi_status' => $snapshot['pi_status'] ?? null,
                    'pi_rate' => $snapshot['pi_rate'] ?? null,
                    'pi_amount' => $snapshot['pi_amount'] ?? null,
                    'payment_status' => $snapshot['payment_status'] ?? null,
                    'payment_required_date' => $snapshot['payment_required_date'] ?? null,
                    'supplier_name' => $snapshot['supplier_name'] ?? null,
                    'buyer_name' => $snapshot['buyer_name'] ?? null,
                    'season_name' => $snapshot['season_name'] ?? null,
                    'style_name' => $snapshot['style_name'] ?? null,
                    'material_description' => $snapshot['material_description'] ?? null,
                    'sap_code' => $snapshot['sap_code'] ?? null,
                    'material_color' => $snapshot['material_color'] ?? null,
                    'qty' => $snapshot['qty'] ?? null,
                    'delivery_term' => $snapshot['delivery_term'] ?? null,
                    'payment_term' => $snapshot['payment_term'] ?? null,
                    'ship_mode' => $snapshot['ship_mode'] ?? null,
                    'forwarder' => $snapshot['forwarder'] ?? null,
                    'committed_etd' => $snapshot['committed_etd'] ?? null,
                    'committed_eta' => $snapshot['committed_eta'] ?? null,
                    'remarks' => $snapshot['remarks'] ?? null,
                    'data' => $snapshot,
                ]);
            }

            // NEW PRAs GO ON THE DEPARTMENTAL CHAIN. The creator no longer picks
            // reviewers — routing is by department, resolved per step. The
            // legacy row-creation below is left in place and unreachable from
            // here on purpose: resubmit() still runs it for the flow-1 PRAs that
            // already exist, and deleting it would strand them.
            // THE SINGLE CONSUMPTION POINT of the toggle, and the only moment a
            // PRA's flow is decided. From here on the row's own flow_version is
            // the authority, which is why flipping this setting cannot disturb
            // anything already in flight.
            if (PaymentRequestSettings::departmentalFlowEnabled()) {
                $paymentRequest->update([
                    'flow_version' => PaymentRequest::FLOW_DEPARTMENTAL,
                    'current_sequence' => PraStageResolver::FIRST_SEQUENCE,
                    'status' => PaymentRequest::STATUS_PENDING_CHECK,
                ]);

                return $paymentRequest->fresh(['items', 'createdBy', 'approvals']);
            }

            // First cycle. The checker row (if any) gates the approver rows: the
            // approvers are created now but stay hidden until the PRA moves from
            // pending_check to pending_approval once the checker approves.
            if ($checkerUserId) {
                PraApproval::create([
                    'payment_request_id' => $paymentRequest->id,
                    'approver_id' => $checkerUserId,
                    'cycle' => 1,
                    'stage' => PraApproval::STAGE_CHECK,
                    'status' => PraApproval::STATUS_PENDING,
                ]);
            }

            foreach ($approverUserIds as $approverId) {
                PraApproval::create([
                    'payment_request_id' => $paymentRequest->id,
                    'approver_id' => $approverId,
                    'cycle' => 1,
                    'stage' => PraApproval::STAGE_APPROVE,
                    'status' => PraApproval::STATUS_PENDING,
                ]);
            }

            return $paymentRequest->fresh(['items', 'createdBy', 'approvals']);
        });

        // Save the uploaded supporting documents now that the PRA exists. Files
        // go to the private disk and are served only through an authorised route.
        $this->storeDocuments($paymentRequest, $request->file('documents', []));

        // Departmental PRAs route themselves: step 2's actors are resolved from
        // the department and the PRA's buyer, so there is no chosen recipient to
        // name and no separate scoping to apply here.
        if ($paymentRequest->isDepartmentalFlow()) {
            // The creator's optional choice, recorded only when they made one.
            // assign() re-checks eligibility against the saved PRA — belt and
            // braces with the pre-check above — and writes nothing otherwise.
            if ($assigneeStepTwo !== null) {
                $paymentRequest = $this->workflow->assign(
                    $paymentRequest, [PraStageResolver::SEQ_MERCHANT_CHECK => $assigneeStepTwo], auth()->user()
                );
            }

            $stage = $this->workflow->currentStage($paymentRequest);

            if ($stage) {
                $this->approvalService->notifyStageActors($paymentRequest, $stage);
            }

            return redirect()
                ->route('supply_chain.payment_requests.show', $paymentRequest)
                ->with('success', 'PRA ' . $paymentRequest->request_no
                    . ' created and sent to the Merchant team for checking'
                    . ($stage?->assignee ? ', assigned to ' . $stage->assignee->name : '') . '.');
        }

        // Sequential notification: notify the checker first; approvers are
        // notified only after the check step completes.
        if ($checkerUserId) {
            $checker = User::find($checkerUserId);
            if ($checker) {
                $this->approvalService->notifyCheckRequest($paymentRequest, $checker);
            }

            return redirect()
                ->route('supply_chain.payment_requests.show', $paymentRequest)
                ->with('success', 'PRA ' . $paymentRequest->request_no . ' created and sent to ' . optional($checker)->name . ' for checking.');
        }

        if ($approverUserIds->isNotEmpty()) {
            $approvers = User::whereIn('id', $approverUserIds)->get();
            $this->approvalService->notifyApprovalRequest($paymentRequest, $approvers);

            return redirect()
                ->route('supply_chain.payment_requests.show', $paymentRequest)
                ->with('success', 'PRA ' . $paymentRequest->request_no . ' created and sent to ' . $approvers->count() . ' approver(s).');
        }

        return redirect()
            ->route('supply_chain.payment_requests.show', $paymentRequest)
            ->with('success', 'Payment Request Approval created: ' . $paymentRequest->request_no);
    }

    /**
     * Store uploaded supporting documents on the private disk and record them in
     * the PRA's `data.attachments`.
     *
     * @param  array<int, \Illuminate\Http\UploadedFile>  $files
     */
    protected function storeDocuments(PaymentRequest $paymentRequest, array $files): void
    {
        $files = array_filter($files);

        if (empty($files)) {
            return;
        }

        $saved = [];

        foreach ($files as $file) {
            $saved[] = [
                'name' => $file->getClientOriginalName(),
                'path' => $file->store('pra-documents/' . $paymentRequest->id, 'local'),
                'size' => (int) $file->getSize(),
                'mime' => $file->getClientMimeType(),
                'uploaded_by' => auth()->id(),
                'uploaded_at' => now()->toDateTimeString(),
            ];
        }

        $data = (array) $paymentRequest->data;
        $data['attachments'] = array_merge((array) ($data['attachments'] ?? []), $saved);
        $paymentRequest->update(['data' => $data]);
    }

    /**
     * On resubmit: drop the documents the creator ticked and add any new ones.
     */
    protected function syncDocuments(PaymentRequest $paymentRequest, Request $request): void
    {
        $remove = collect($request->input('remove_documents', []))->map(fn ($i) => (int) $i)->all();

        if ($remove) {
            $kept = [];

            foreach ($paymentRequest->attachments() as $i => $doc) {
                if (in_array($i, $remove, true)) {
                    \Illuminate\Support\Facades\Storage::disk('local')->delete($doc['path']);

                    continue;
                }

                $kept[] = $doc;
            }

            $data = (array) $paymentRequest->data;
            $data['attachments'] = $kept;
            $paymentRequest->update(['data' => $data]);
        }

        $this->storeDocuments($paymentRequest, $request->file('documents', []));
    }

    public function show(PaymentRequest $paymentRequest)
    {
        $this->authorizePaymentRequest($paymentRequest);

        // assignedUser / stageAssignments: the "Assigned to" cue on the status
        // card. Both empty on a legacy PRA, and the eager load is a no-op there.
        $paymentRequest->load(['items', 'createdBy', 'checkedBy', 'approvedBy', 'approvals.approver', 'approvals.assignedUser', 'stageAssignments.user']);
        $approvalRows = $this->approvalRowsFromItems($paymentRequest->items);
        $summary = $this->summaryFromApprovalRows($approvalRows);
        $emailLogs = EmailLog::where('payment_request_id', $paymentRequest->id)
            ->with('sentBy')
            ->latest('id')
            ->get();
        $emailDefaults = $this->praEmailDefaults($paymentRequest, $summary);
        // approvalProgress() and currentApprovals() describe FLOW 1: a fixed set
        // of chosen approvers holding pending rows. A departmental PRA has
        // neither, so asking them would report "Not sent for approval" to a
        // creator whose PRA is halfway down the chain. Each flow answers with
        // its own machine; the view picks by isDepartmentalFlow().
        $isDepartmental = $paymentRequest->isDepartmentalFlow();

        $approvalProgress = $isDepartmental ? null : $paymentRequest->approvalProgress();
        $currentApprovals = $isDepartmental ? collect() : $paymentRequest->currentApprovals()->load('approver');

        $departmentalStage = $isDepartmental ? $this->workflow->currentStage($paymentRequest) : null;
        // No ->load('approver') here: the relation is already eager-loaded on
        // $paymentRequest above, and currentCycleDecisions() returns a filtered
        // in-memory slice of it — a plain Collection when empty, which has no
        // load().
        $departmentalDecisions = $isDepartmental
            ? $this->workflow->currentCycleDecisions($paymentRequest)
            : collect();

        $approverPool = $this->approverPool();
        $checkerPool = $this->checkerPool();
        $createOnDepartmentalFlow = PaymentRequestSettings::departmentalFlowEnabled();

        return view('supply-chain.payment-requests.show', compact('paymentRequest', 'summary', 'approvalRows', 'emailLogs', 'emailDefaults', 'approvalProgress', 'currentApprovals', 'departmentalStage', 'departmentalDecisions', 'approverPool', 'checkerPool', 'createOnDepartmentalFlow'));
    }

    /**
     * Creator-facing "My PRA Status" list: the PRAs this user created that have
     * an approval flow, with the current status and approver-wise breakdown.
     */
    public function myStatus(Request $request)
    {
        abort_if(! auth()->user()?->hasRole('supply_chain'), 403);

        // whereHas('approvals') is the flow-1 test for "this PRA entered an
        // approval flow" — flow 1 pre-creates a pending row per reviewer, so a
        // row means it was sent. A departmental PRA writes rows only as
        // decisions are TAKEN, so a brand-new one has none and would vanish from
        // its own creator's status list until somebody acted on it. Its
        // equivalent of "entered a flow" is flow_version, not row count.
        $paymentRequests = PaymentRequest::query()
            ->where('created_by', auth()->id())
            ->where(fn ($query) => $query
                ->whereHas('approvals')
                ->orWhere('flow_version', PaymentRequest::FLOW_DEPARTMENTAL))
            ->with(['approvals.approver'])
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $approverPool = $this->approverPool();
        $checkerPool = $this->checkerPool();

        return view('supply-chain.payment-requests.my-status', compact('paymentRequests', 'approverPool', 'checkerPool'));
    }

    /**
     * Resubmit a rejected PRA for a fresh approval cycle. Old cycle rows stay
     * as audit history; new pending rows are created for the newly selected
     * approvers and the status returns to "pending approval".
     */
    public function resubmit(Request $request, PaymentRequest $paymentRequest)
    {
        $this->authorizePaymentRequest($paymentRequest);
        abort_if($paymentRequest->created_by !== auth()->id(), 403);

        if ($paymentRequest->status !== PaymentRequest::STATUS_REJECTED) {
            return back()->with('warning', 'Only a rejected PRA can be resubmitted.');
        }

        // A departmental PRA has nobody to re-pick — it re-enters the chain at
        // step 2 and routes itself again. The approver validation below would
        // reject the submission outright for want of an approver_ids array that
        // this flow has no way to supply.
        if ($paymentRequest->isDepartmentalFlow()) {
            $request->validate([
                'remarks' => ['nullable', 'string', 'max:2000'],
            'documents' => ['nullable', 'array', 'max:10'],
            'documents.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,xls,xlsx,csv,doc,docx'],
            'remove_documents' => ['nullable', 'array'],
            'remove_documents.*' => ['integer'],
            ]);
            $this->syncDocuments($paymentRequest, $request);

            // Refresh the Prepared By snapshot exactly as the legacy path does below.
            $paymentRequest->update([
                'remarks' => $request->input('remarks') ?: $paymentRequest->remarks,
                'prepared_signature_path' => auth()->user()?->signature_path,
            ]);

            $paymentRequest = $this->workflow->resubmit($paymentRequest);
            $stage = $this->workflow->currentStage($paymentRequest);

            if ($stage) {
                $this->approvalService->notifyStageActors($paymentRequest, $stage);
            }

            return redirect()
                ->route('supply_chain.payment_requests.my_status')
                ->with('success', 'PRA ' . $paymentRequest->request_no
                    . ' resubmitted and sent to the Merchant team for checking.');
        }

        $validated = $request->validate([
            'checker_id' => ['nullable', 'integer'],
            'approver_ids' => ['required', 'array', 'min:1'],
            'approver_ids.*' => ['integer'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'documents' => ['nullable', 'array', 'max:10'],
            'documents.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,xls,xlsx,csv,doc,docx'],
            'remove_documents' => ['nullable', 'array'],
            'remove_documents.*' => ['integer'],
        ]);

        $approverUserIds = PraApprover::where('is_active', true)
            ->whereIn('user_id', collect($validated['approver_ids'])->map(fn ($id) => (int) $id)->unique())
            ->pluck('user_id');

        if ($approverUserIds->isEmpty()) {
            return back()->with('warning', 'Please select at least one active approver.');
        }

        $checkerUserId = $validated['checker_id'] ?? null;
        if ($checkerUserId) {
            $checkerUserId = PraApprover::where('is_active', true)
                ->where('can_check', true)
                ->where('user_id', (int) $checkerUserId)
                ->value('user_id');
        }

        $this->syncDocuments($paymentRequest, $request);

        $paymentRequest->load('approvals');
        $nextCycle = $paymentRequest->currentCycle() + 1;

        // Refresh the Prepared By snapshot with the creator's current signature.
        $creatorSignaturePath = auth()->user()?->signature_path;

        DB::transaction(function () use ($paymentRequest, $approverUserIds, $checkerUserId, $nextCycle, $validated, $creatorSignaturePath) {
            if ($checkerUserId) {
                PraApproval::create([
                    'payment_request_id' => $paymentRequest->id,
                    'approver_id' => $checkerUserId,
                    'cycle' => $nextCycle,
                    'stage' => PraApproval::STAGE_CHECK,
                    'status' => PraApproval::STATUS_PENDING,
                ]);
            }

            foreach ($approverUserIds as $approverId) {
                PraApproval::create([
                    'payment_request_id' => $paymentRequest->id,
                    'approver_id' => $approverId,
                    'cycle' => $nextCycle,
                    'stage' => PraApproval::STAGE_APPROVE,
                    'status' => PraApproval::STATUS_PENDING,
                ]);
            }

            $paymentRequest->update([
                'status' => $checkerUserId ? PaymentRequest::STATUS_PENDING_CHECK : PaymentRequest::STATUS_PENDING_APPROVAL,
                'checked_by' => null,
                'checked_at' => null,
                'approved_by' => null,
                'approved_at' => null,
                'prepared_signature_path' => $creatorSignaturePath,
                'remarks' => $validated['remarks'] ?? $paymentRequest->remarks,
            ]);
        });

        $paymentRequest->refresh()->load(['createdBy']);

        if ($checkerUserId) {
            $checker = User::find($checkerUserId);
            if ($checker) {
                $this->approvalService->notifyCheckRequest($paymentRequest, $checker);
            }

            return redirect()
                ->route('supply_chain.payment_requests.my_status')
                ->with('success', 'PRA ' . $paymentRequest->request_no . ' resubmitted to ' . optional($checker)->name . ' for checking.');
        }

        $approvers = User::whereIn('id', $approverUserIds)->get();
        $this->approvalService->notifyApprovalRequest($paymentRequest, $approvers);

        return redirect()
            ->route('supply_chain.payment_requests.my_status')
            ->with('success', 'PRA ' . $paymentRequest->request_no . ' resubmitted to ' . $approvers->count() . ' approver(s).');
    }

    public function downloadPdf(PaymentRequest $paymentRequest)
    {
        $this->authorizePaymentRequest($paymentRequest);

        [$approvalRows, $summary] = $this->approvalDataFor($paymentRequest);

        if (class_exists('Barryvdh\\DomPDF\\Facade\\Pdf')) {
            return $this->buildPaymentRequestPdf($paymentRequest)
                ->stream('PAYMENT_REQUEST_APPROVAL_' . $paymentRequest->request_no . '.pdf', ['Attachment' => false]);
        }

        return view('supply-chain.payment-requests.approval_pdf', [
            'paymentRequest' => $paymentRequest,
            'summary' => $summary,
            'approvalRows' => $approvalRows,
            'isPdf' => false,
        ]);
    }

    /**
     * Send the Payment Request Approval as an email with the PDF attached.
     * Failures are logged and surfaced to the user without breaking the flow.
     */
    public function sendEmail(Request $request, PaymentRequest $paymentRequest)
    {
        $this->authorizePaymentRequest($paymentRequest);

        $validated = $request->validate([
            'to' => ['required', 'string', 'max:2000'],
            'cc' => ['nullable', 'string', 'max:2000'],
            'from' => ['nullable', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
        ]);

        $to = $this->parseEmails($validated['to']);
        $cc = $this->parseEmails($validated['cc'] ?? '');
        $replyTo = $validated['from'] ?? null;

        if (empty($to)) {
            return back()->with('error', 'Please enter at least one valid recipient email address.');
        }

        $pdfData = null;
        if (class_exists('Barryvdh\\DomPDF\\Facade\\Pdf')) {
            $pdfData = $this->buildPaymentRequestPdf($paymentRequest)->output();
        }
        $pdfName = 'PAYMENT_REQUEST_APPROVAL_' . $paymentRequest->request_no . '.pdf';

        $status = 'sent';
        $error = null;

        try {
            $mail = new PaymentRequestMail($validated['subject'], $validated['body'], $pdfData, $pdfName, $replyTo);
            Mail::to($to)->cc($cc)->send($mail);
        } catch (\Throwable $e) {
            $status = 'failed';
            $error = $e->getMessage();
            Log::error('PRA email failed: ' . $e->getMessage(), ['payment_request_id' => $paymentRequest->id]);
        }

        EmailLog::create([
            'payment_request_id' => $paymentRequest->id,
            'recipients' => implode(', ', $to),
            'cc' => $cc ? implode(', ', $cc) : null,
            'subject' => $validated['subject'],
            'body' => $validated['body'],
            'sent_by' => auth()->id(),
            'status' => $status,
            'error' => $error,
        ]);

        if ($status === 'sent') {
            return back()->with('success', 'Email sent successfully to ' . implode(', ', $to) . '.');
        }

        return back()->with('error', 'Email could not be sent. Please check the mail (SMTP) configuration and try again.');
    }

    /**
     * Build the official PRA PDF (same view/format as the download).
     */
    protected function buildPaymentRequestPdf(PaymentRequest $paymentRequest)
    {
        [$approvalRows, $summary] = $this->approvalDataFor($paymentRequest);

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('supply-chain.payment-requests.approval_pdf', [
            'paymentRequest' => $paymentRequest,
            'summary' => $summary,
            'approvalRows' => $approvalRows,
            'isPdf' => true,
        ])->setPaper('a4', 'landscape');
    }

    /**
     * @return array{0: \Illuminate\Support\Collection, 1: array}
     */
    protected function approvalDataFor(PaymentRequest $paymentRequest): array
    {
        $paymentRequest->loadMissing(['items', 'createdBy', 'checkedBy', 'approvedBy', 'approvals.approver']);
        $approvalRows = $this->approvalRowsFromItems($paymentRequest->items);
        $summary = $this->summaryFromApprovalRows($approvalRows);

        return [$approvalRows, $summary];
    }

    /**
     * Pre-filled subject/body for the Send Email form, using the admin PRA
     * template with its placeholders replaced by this PRA's data.
     *
     * @return array{subject: string, body: string, to: string, cc: string, from: string}
     */
    protected function praEmailDefaults(PaymentRequest $paymentRequest, array $summary): array
    {
        $template = EmailTemplate::forType('pra');
        $subject = $template->subject ?? 'Payment Request Approval - {{pr_number}}';
        $body = $template->body ?? '<p>Please find attached Payment Request Approval {{pr_number}}.</p>';

        $requiredDate = $summary['earliest_payment_required_date']
            ?? ($paymentRequest->data['payment_required_date'] ?? null);
        if ($requiredDate && ! ($requiredDate instanceof \Illuminate\Support\Carbon)) {
            $requiredDate = $this->sourceService->dateValue($requiredDate);
        }

        $placeholders = [
            'pr_number' => $paymentRequest->request_no,
            'buyer' => $this->joinUnique(collect($summary['buyers'] ?? []), $paymentRequest->buyer_name ?: '-'),
            'season' => $this->joinUnique(collect($summary['seasons'] ?? []), $paymentRequest->season_name ?: '-'),
            'supplier' => $this->joinUnique(collect($summary['suppliers'] ?? []), $paymentRequest->supplier_name ?: '-'),
            'payment_require_date' => $requiredDate ? $requiredDate->format('jS M-Y') : '-',
            'total_amount' => '$ ' . number_format((float) ($summary['total_pi_amount'] ?? $paymentRequest->total_pi_amount), 2),
            'date' => optional($paymentRequest->created_at)->format('jS M-Y') ?? '-',
            'company_name' => 'Humana Apparels Pvt. Ltd.',
        ];

        return [
            'subject' => EmailTemplate::render($subject, $placeholders),
            'body' => EmailTemplate::render($body, $placeholders),
            'to' => $template->default_to ?? '',
            'cc' => $template->default_cc ?? '',
            'from' => auth()->user()?->email ?? '',
        ];
    }

    /**
     * Parse a comma / semicolon / newline separated list into valid emails.
     *
     * @return array<int, string>
     */
    protected function parseEmails(?string $raw): array
    {
        if (! $raw || trim($raw) === '') {
            return [];
        }

        return collect(preg_split('/[,;\r\n]+/', $raw))
            ->map(fn ($email) => trim($email))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();
    }

    public function downloadExcel(PaymentRequest $paymentRequest)
    {
        $this->authorizePaymentRequest($paymentRequest);

        $paymentRequest->load(['items', 'createdBy', 'checkedBy', 'approvedBy', 'approvals.approver']);
        $approvalRows = $this->approvalRowsFromItems($paymentRequest->items);
        $summary = $this->summaryFromApprovalRows($approvalRows);
        $fileName = 'PAYMENT_REQUEST_APPROVAL_' . $paymentRequest->request_no;

        if (class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            return $this->downloadXlsx($paymentRequest, $summary, $fileName . '.xlsx', $approvalRows);
        }

        return response()
            ->view('supply-chain.payment-requests.approval_excel', compact('paymentRequest', 'summary', 'approvalRows'))
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '.xls"')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    /**
     * Key of one PRA line for the PP Qty Free choice: the booking PO plus its
     * source row.
     */
    protected function ppLineKey(array $snapshot): string
    {
        return ($snapshot['booking_po_id'] ?? 0) . '-' . ($snapshot['excel_row_id'] ?? 0);
    }

    /**
     * Apply the creator's PP Qty Free choices (Yes = free, No = billed). A line
     * with no choice keeps the sheet's PP Qty Free default.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $snapshots
     * @param  array<string, mixed>  $choices
     */
    protected function applyPpChoices(\Illuminate\Support\Collection $snapshots, array $choices): \Illuminate\Support\Collection
    {
        return $snapshots->map(function (array $snapshot) use ($choices) {
            if ((float) ($snapshot['pp_qty_number'] ?? 0) <= 0) {
                return $snapshot;
            }

            $key = $this->ppLineKey($snapshot);
            $free = array_key_exists($key, $choices)
                ? (string) $choices[$key] === '1'
                : (bool) ($snapshot['pp_free'] ?? false);

            return $this->sourceService->applyPpChoice($snapshot, $free);
        })->values();
    }

    protected function snapshotsForSelectedBookingPos($selectedIds): \Illuminate\Support\Collection
    {
        $selectedIds = collect($selectedIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $this->assertBuyerAccessForBookingPoIds($selectedIds);

        $selectedPoNumbers = BookingPo::query()
            ->whereIn('id', $selectedIds)
            ->pluck('po_no')
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique(fn ($value) => Str::lower($value))
            ->values();

        return BookingPo::query()
            ->with(['excelFile.uploader', 'excelRow.cells.header', 'generatedBy'])
            ->where(function ($query) use ($selectedIds, $selectedPoNumbers) {
                $query->whereIn('id', $selectedIds);

                if ($selectedPoNumbers->isNotEmpty()) {
                    $query->orWhereIn('po_no', $selectedPoNumbers);
                }
            })
            ->whereNotNull('generated_at')
            ->get()
            // One snapshot PER SOURCE ROW, not one per PO. A generated PO can
            // span several worksheet lines, and the PRA has to show each of them
            // on its own row — see paymentSnapshotLines(). Eligibility is still
            // decided for the PO as a whole: every line of a PO inherits the
            // aggregate's flag, so exactly the same POs are selected as before.
            ->flatMap(fn (BookingPo $bookingPo) => $this->sourceService->paymentSnapshotLines($bookingPo))
            ->filter(fn (array $row) => (bool) ($row['eligible_for_payment_request'] ?? false))
            ->values();
    }

    /**
     * Normalised dedup key for a PO No. + PI No. combination. Returns null when
     * both are empty (such rows can never be matched to an existing PRA).
     */
    protected function praKey($poNo, $piNumber): ?string
    {
        $po = Str::lower(trim((string) $poNo));
        $pi = Str::lower(trim((string) $piNumber));

        if ($po === '' && $pi === '') {
            return null;
        }

        return $po . '|' . $pi;
    }

    /**
     * PO No. + PI No. keys already covered by a live (non-rejected) PRA. A
     * rejected PRA frees its PO/PI so it can reappear in the pending list.
     */
    protected function activePraKeys(): \Illuminate\Support\Collection
    {
        return PaymentRequestItem::query()
            ->join('payment_requests', 'payment_requests.id', '=', 'payment_request_items.payment_request_id')
            ->where('payment_requests.status', '!=', PaymentRequest::STATUS_REJECTED)
            ->get(['payment_request_items.po_no', 'payment_request_items.pi_number'])
            ->map(fn (PaymentRequestItem $item) => $this->praKey($item->po_no, $item->pi_number))
            ->filter()
            ->unique()
            ->values();
    }

    protected function pendingPaymentRows(Request $request): array
    {
        // POs already covered by an existing, non-rejected PRA must not appear as
        // "pending" again — otherwise the same PO can be sent for approval twice.
        $consumed = $this->poKeysWithActivePra();

        $baseQuery = BookingPo::query()
            ->with(['excelFile.uploader', 'excelRow.cells.header', 'generatedBy'])
            ->whereNotNull('generated_at');

        // IN THE SQL, NOT AFTER IT. Everything below this line filters a
        // collection that has already been cut to 5000 rows, so a scope applied
        // there would be applied to somebody else's 5000 rows: a scoped user
        // could see nothing at all while unscoped POs filled the limit.
        $this->applyBuyerScope($baseQuery);

        $baseRows = $baseQuery
            ->latest('generated_at')
            ->limit(5000)
            ->get()
            ->map(fn (BookingPo $bookingPo) => $this->sourceService->paymentSnapshot($bookingPo))
            ->filter(fn (array $row) => (bool) ($row['eligible_for_payment_request'] ?? false))
            ->reject(fn (array $row) => $this->isCoveredByActivePra($row, $consumed))
            ->values();

        $filterOptions = $this->filterOptions($baseRows);
        $filteredRows = $this->applyFilters($baseRows, $request)->values();
        $kpis = $this->summaryFromSnapshots($filteredRows);
        $activeFilters = $this->activeFilters($request);

        $perPage = 25;
        $page = LengthAwarePaginator::resolveCurrentPage();
        $items = $filteredRows->forPage($page, $perPage)->values();

        $pendingRows = new LengthAwarePaginator(
            $items,
            $filteredRows->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

        return [$pendingRows, $filterOptions, $kpis, $activeFilters];
    }

    /**
     * Build a lookup of PO identifiers already consumed by a live PRA (any
     * status except rejected). A rejected PRA frees its PO to be raised again.
     * Keyed both by booking_po_id and by normalised po_no so a PO is matched
     * whichever identifier the pending snapshot carries.
     *
     * @return array{po: array<string, true>, booking: array<int, true>}
     */
    protected function poKeysWithActivePra(): array
    {
        $items = PaymentRequestItem::query()
            ->join('payment_requests', 'payment_requests.id', '=', 'payment_request_items.payment_request_id')
            ->where('payment_requests.status', '!=', PaymentRequest::STATUS_REJECTED)
            ->get(['payment_request_items.po_no', 'payment_request_items.booking_po_id']);

        $po = [];
        $booking = [];

        foreach ($items as $item) {
            $poNo = Str::lower(trim((string) $item->po_no));
            if ($poNo !== '') {
                $po[$poNo] = true;
            }
            if ($item->booking_po_id) {
                $booking[(int) $item->booking_po_id] = true;
            }
        }

        return ['po' => $po, 'booking' => $booking];
    }

    /**
     * Whether a pending snapshot row is already covered by a live PRA.
     *
     * @param array{po: array<string, true>, booking: array<int, true>} $consumed
     */
    protected function isCoveredByActivePra(array $row, array $consumed): bool
    {
        $bookingId = $row['booking_po_id'] ?? null;
        if ($bookingId && isset($consumed['booking'][(int) $bookingId])) {
            return true;
        }

        $poNo = Str::lower(trim((string) ($row['po_no'] ?? '')));

        return $poNo !== '' && isset($consumed['po'][$poNo]);
    }

    protected function applyFilters($rows, Request $request): \Illuminate\Support\Collection
    {
        return $rows->filter(function (array $row) use ($request) {
            foreach ($this->textFilterMap() as $input => $key) {
                $needle = trim((string) $request->input($input));
                if ($needle !== '' && ! Str::contains(Str::lower((string) ($row[$key] ?? '')), Str::lower($needle))) {
                    return false;
                }
            }

            foreach ($this->dateFilterMap() as $prefix => $key) {
                $date = $this->sourceService->dateValue($row[$key] ?? null);
                $from = $this->sourceService->dateValue($request->input($prefix . '_from'));
                $to = $this->sourceService->dateValue($request->input($prefix . '_to'));

                if ($from && (! $date || $date->lt($from->startOfDay()))) {
                    return false;
                }

                if ($to && (! $date || $date->gt($to->endOfDay()))) {
                    return false;
                }
            }

            return true;
        });
    }

    protected function textFilterMap(): array
    {
        return [
            'shipment_month' => 'shipment_month',
            'vendor_type' => 'vendor_type',
            'final_status' => 'final_status',
            'payment_term' => 'payment_term',
            'payment_status' => 'payment_status',
            'supplier' => 'supplier_name',
            'buyer' => 'buyer_name',
            'season' => 'season_name',
            'po_no' => 'po_no',
            'pi_number' => 'pi_number',
            'pi_status' => 'pi_status',
            'material_type' => 'material_type',
        ];
    }

    protected function dateFilterMap(): array
    {
        return [
            'contract_shipment' => 'contract_shipment',
            'committed_ex_mill' => 'committed_ex_mill',
            'pcd_required' => 'pcd_required',
            'payment_required_date' => 'payment_required_date',
            'committed_etd' => 'committed_etd',
            'committed_eta' => 'committed_eta',
        ];
    }

    protected function filterOptions($rows): array
    {
        return [
            'shipment_months' => $this->optionValues($rows, 'shipment_month'),
            'vendor_types' => $this->optionValues($rows, 'vendor_type'),
            'final_statuses' => $this->optionValues($rows, 'final_status'),
            'payment_terms' => $this->optionValues($rows, 'payment_term'),
            'payment_statuses' => $this->optionValues($rows, 'payment_status'),
            'suppliers' => $this->optionValues($rows, 'supplier_name'),
            'buyers' => $this->optionValues($rows, 'buyer_name'),
            'seasons' => $this->optionValues($rows, 'season_name'),
            'po_numbers' => $this->optionValues($rows, 'po_no'),
            'pi_numbers' => $this->optionValues($rows, 'pi_number'),
            'pi_statuses' => $this->optionValues($rows, 'pi_status'),
            'material_types' => $this->optionValues($rows, 'material_type'),
        ];
    }

    protected function optionValues($rows, string $key)
    {
        return $rows->pluck($key)
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique(fn ($value) => Str::lower($value))
            ->sort()
            ->values();
    }

    protected function activeFilters(Request $request): array
    {
        $labels = [
            'shipment_month' => 'Shipment Month',
            'vendor_type' => 'Vendor Type',
            'final_status' => 'Final Status',
            'payment_term' => 'Payment Term',
            'payment_status' => 'Payment Status',
            'supplier' => 'Supplier / Vendor',
            'buyer' => 'Buyer',
            'season' => 'Season',
            'po_no' => 'PO Number',
            'pi_number' => 'PI Number',
            'pi_status' => 'PI Status',
            'material_type' => 'Material Type',
        ];

        $active = [];
        foreach ($labels as $input => $label) {
            $value = trim((string) $request->input($input));
            if ($value !== '') {
                $active[$label] = $value;
            }
        }

        $dateLabels = [
            'contract_shipment' => 'Contract Shipment',
            'committed_ex_mill' => 'Committed Ex Mill',
            'pcd_required' => 'PCD Required',
            'payment_required_date' => 'Payment Required Date',
        ];

        foreach ($dateLabels as $prefix => $label) {
            $from = trim((string) $request->input($prefix . '_from'));
            $to = trim((string) $request->input($prefix . '_to'));
            if ($from !== '' || $to !== '') {
                $active[$label] = trim(($from ?: '...') . ' to ' . ($to ?: '...'));
            }
        }

        return $active;
    }

    protected function summaryFromSnapshots($rows): array
    {
        return [
            'total_pi_amount' => $rows->sum(fn (array $row) => (float) ($row['pi_amount'] ?? 0)),
            'total_budget' => $rows->sum(fn (array $row) => (float) ($row['budget'] ?? 0)),
            'total_savings' => $rows->sum(fn (array $row) => (float) ($row['savings'] ?? 0)),
            'total_po_count' => $rows->pluck('po_no')->filter()->unique()->count(),
            'pending_payment_count' => $rows->filter(fn (array $row) => $this->sourceService->normalize($row['payment_status'] ?? '') === 'pmt_pending')->count(),
            'earliest_payment_required_date' => $rows->pluck('payment_required_date')->filter()->sort()->first(),
            'payment_status_summary' => $rows->countBy('payment_status')->all(),
            'buyers' => $this->optionValues($rows, 'buyer_name')->all(),
            'seasons' => $this->optionValues($rows, 'season_name')->all(),
            'suppliers' => $this->optionValues($rows, 'supplier_name')->all(),
            'shipment_months' => $this->optionValues($rows, 'shipment_month')->all(),
            'vendor_types' => $this->optionValues($rows, 'vendor_type')->all(),
            'final_statuses' => $this->optionValues($rows, 'final_status')->all(),
            'payment_terms' => $this->optionValues($rows, 'payment_term')->all(),
            'payment_statuses' => $this->optionValues($rows, 'payment_status')->all(),
            'material_types' => $this->optionValues($rows, 'material_type')->all(),
            'pi_numbers' => $this->optionValues($rows, 'pi_number')->all(),
        ];
    }

    protected function summaryFromItems($items): array
    {
        return [
            'total_pi_amount' => $items->sum(fn ($item) => (float) $item->pi_amount),
            'total_budget' => $items->sum(fn ($item) => (float) data_get($item->data, 'budget', 0)),
            'total_savings' => $items->sum(fn ($item) => (float) data_get($item->data, 'savings', 0)),
            'total_po_count' => $items->pluck('po_no')->filter()->unique()->count(),
            'pending_payment_count' => $items->filter(fn ($item) => $this->sourceService->normalize($item->payment_status ?? '') === 'pmt_pending')->count(),
            'earliest_payment_required_date' => $items->pluck('payment_required_date')->filter()->sort()->first(),
            'payment_status_summary' => $items->countBy('payment_status')->all(),
            'pi_numbers' => $items->pluck('pi_number')->filter()->unique()->values()->all(),
            'payment_terms' => $items->pluck('payment_term')->filter()->unique()->values()->all(),
            'delivery_terms' => $items->pluck('delivery_term')->filter()->unique()->values()->all(),
            'buyers' => $items->pluck('buyer_name')->filter()->unique()->values()->all(),
            'seasons' => $items->pluck('season_name')->filter()->unique()->values()->all(),
            'suppliers' => $items->pluck('supplier_name')->filter()->unique()->values()->all(),
            'shipment_months' => $this->uniqueItemData($items, 'shipment_month'),
            'vendor_types' => $this->uniqueItemData($items, 'vendor_type'),
            'final_statuses' => $this->uniqueItemData($items, 'final_status'),
            'payment_statuses' => $items->pluck('payment_status')->filter()->unique()->values()->all(),
            'material_types' => $this->uniqueItemData($items, 'material_type'),
        ];
    }

    protected function uniqueItemData($items, string $key): array
    {
        return $items->map(fn ($item) => trim((string) data_get($item->data, $key, '')))
            ->filter()
            ->unique(fn ($value) => Str::lower($value))
            ->sort()
            ->values()
            ->all();
    }

    protected function approvalRowsFromSnapshots($snapshots): \Illuminate\Support\Collection
    {
        $items = collect($snapshots)->map(function (array $snapshot) {
            return new PaymentRequestItem([
                'booking_po_id' => $snapshot['booking_po_id'] ?? null,
                'excel_file_id' => $snapshot['excel_file_id'] ?? null,
                'excel_row_id' => $snapshot['excel_row_id'] ?? null,
                'po_no' => $snapshot['po_no'] ?? null,
                'pi_number' => $snapshot['pi_number'] ?? null,
                'pi_status' => $snapshot['pi_status'] ?? null,
                'pi_rate' => $snapshot['pi_rate'] ?? null,
                'pi_amount' => $snapshot['pi_amount'] ?? null,
                'payment_status' => $snapshot['payment_status'] ?? null,
                'payment_required_date' => $snapshot['payment_required_date'] ?? null,
                'supplier_name' => $snapshot['supplier_name'] ?? null,
                'buyer_name' => $snapshot['buyer_name'] ?? null,
                'season_name' => $snapshot['season_name'] ?? null,
                'style_name' => $snapshot['style_name'] ?? null,
                'material_description' => $snapshot['material_description'] ?? null,
                'sap_code' => $snapshot['sap_code'] ?? null,
                'material_color' => $snapshot['material_color'] ?? null,
                'qty' => $snapshot['qty'] ?? null,
                'delivery_term' => $snapshot['delivery_term'] ?? null,
                'payment_term' => $snapshot['payment_term'] ?? null,
                'ship_mode' => $snapshot['ship_mode'] ?? null,
                'forwarder' => $snapshot['forwarder'] ?? null,
                'committed_etd' => $snapshot['committed_etd'] ?? null,
                'committed_eta' => $snapshot['committed_eta'] ?? null,
                'remarks' => $snapshot['remarks'] ?? null,
                'data' => $snapshot,
            ]);
        });

        return $this->approvalRowsFromItems($items);
    }

    protected function approvalRowsFromItems($items): \Illuminate\Support\Collection
    {
        return collect($items)
            ->map(fn (PaymentRequestItem $item) => $this->approvalRowFromItem($item))
            ->groupBy('merge_key')
            ->map(fn ($rows) => $this->mergeApprovalRows($rows))
            ->sortBy([
                ['vendor_name', 'asc'],
                ['style', 'asc'],
                ['material_type', 'asc'],
                ['material_po_number', 'asc'],
            ])
            ->pipe(fn ($rows) => $this->annotateStyleSpans($rows));
    }

    /**
     * Marks each run of CONSECUTIVE rows sharing a Style, so the three
     * renderers can print that style once as a spanning cell instead of
     * repeating it on every line.
     *
     * Each row gains 'style_rowspan': the run length on the FIRST row of a run,
     * and 0 on every row the span already covers (those emit no Style cell at
     * all). Nothing else is touched - no reordering, no data change - so the
     * Grand Total and every other column are unaffected.
     *
     * CONSECUTIVE RUNS, NOT GROUP-BY-STYLE, and the distinction is not
     * theoretical. The sort above is by VENDOR first, so one style legitimately
     * appears in several separate blocks with another vendor's rows between
     * them: on the current data, style 4800093964 occupies two blocks and
     * 4800094675 three. Grouping by style would try to span rows that are not
     * adjacent, which no table can express - a run-based span is the only
     * correct reading of "these rows share a style".
     *
     * Same-style rows are always contiguous WITHIN a vendor, so no sort step is
     * needed to make the runs whole; if the sort above ever stops leading with
     * vendor and style, re-check this.
     */
    protected function annotateStyleSpans($rows): \Illuminate\Support\Collection
    {
        $rows = collect($rows)->values()->all();
        $count = count($rows);
        $index = 0;

        while ($index < $count) {
            $style = $rows[$index]['style'] ?? null;

            $run = 1;
            while ($index + $run < $count && ($rows[$index + $run]['style'] ?? null) === $style) {
                $run++;
            }

            $rows[$index]['style_rowspan'] = $run;
            for ($offset = 1; $offset < $run; $offset++) {
                $rows[$index + $offset]['style_rowspan'] = 0;
            }

            $index += $run;
        }

        return collect($rows);
    }

    protected function approvalRowFromItem(PaymentRequestItem $item): array
    {
        // Rows merge into a single PRA line only when EVERY identity column
        // matches: Style, Color, Size, Item Name, PO No and PI No.
        //
        // WHY THE LAST THREE WERE ADDED. The key used to be Style, Color and
        // Size alone, so several BOM items sharing one style collapsed into a
        // single line and mergeApprovalRows() comma-joined everything that
        // differed. On a real PRA that produced one cell reading "Fabric
        // Interlining, Fabric Scrim, ..." beside another reading "PO-11, PO-12",
        // with the amounts fused into one figure — the approver could not see
        // which item cost what, which is the one thing the document is signed
        // for. One line per (style, item, PO, PI) is what the paper form has
        // always shown.
        //
        // NO AMOUNT IS INVENTED OR DOUBLED. Every PaymentRequestItem carries its
        // own pi_amount and mergeApprovalRows() was summing them; a finer key
        // sums fewer of them together, so the same figures are distributed
        // across more lines instead of added up. The Grand Total, which
        // summaryFromApprovalRows() takes over these same rows, is arithmetically
        // unchanged.
        //
        // PO No and PI No join the key because the request is for them to REPEAT
        // on each split line. A column left out of the key stays comma-joined,
        // which would simply move the cramped cell rather than remove it.
        $style = $item->style_name ?: data_get($item->data, 'style_name');
        $color = $item->material_color ?: data_get($item->data, 'material_color');
        $size = data_get($item->data, 'size') ?: data_get($item->data, 'size_width');
        $materialType = data_get($item->data, 'material_type') ?: $item->material_description ?: $item->material_type;
        $poNumber = $item->po_no ?: data_get($item->data, 'po_no');
        $piNumber = $item->pi_number ?: data_get($item->data, 'pi_number');

        return [
            'merge_key' => $this->mergeKey($style, $color, $size, $materialType, $poNumber, $piNumber),
            'booking_po_ids' => collect([$item->booking_po_id])->filter()->values()->all(),
            'vendor_name' => $item->supplier_name ?: '-',
            'style' => $style ?: '-',
            'pcd_required' => $this->reportDate(data_get($item->data, 'pcd_required')),
            'payment_term' => $item->payment_term ?: data_get($item->data, 'payment_term') ?: '-',
            // The same three locals the merge key is built from, so what is
            // grouped on and what is printed cannot drift apart.
            'material_po_number' => $poNumber ?: '-',
            'material_pi_number' => $piNumber ?: '-',
            'material_type' => $materialType ?: '-',
            'contract_shipment' => $this->reportDate(data_get($item->data, 'contract_shipment')),
            'committed_ex_mill' => $this->reportDate(data_get($item->data, 'committed_ex_mill')),
            'comments' => $item->remarks ?: data_get($item->data, 'remarks') ?: data_get($item->data, 'comments') ?: '(blank)',
            // The allocated budget for this line, from the sheet's "Buget Amount"
            // column (the misspelling is the real header; the aliases cover it).
            // Same value the pending payment list already labels Budget, so the
            // two screens cannot disagree about what the word means.
            //
            // KEPT NULLABLE ON PURPOSE — not cast to float. The column is filled
            // on only some rows, and null means "no budget recorded", which the
            // renderers show as "-". Casting would turn that into $0.00, which
            // reads as a budget of nothing rather than an absent one.
            'budget' => $this->budgetValue(data_get($item->data, 'budget')),
            'pi_amount' => (float) $item->pi_amount,
            'payment_required_date' => $item->payment_required_date ?: data_get($item->data, 'payment_required_date'),
            'buyer_name' => $item->buyer_name,
            'season_name' => $item->season_name,
            // Saved with the PRA from now on; a PRA made before that read the
            // contract from the Workspace row it came from.
            'sales_contract' => data_get($item->data, 'sales_contract') ?: $this->salesContractFromSource($item),
            'payment_status' => $item->payment_status,
            'final_status' => data_get($item->data, 'final_status'),
            'vendor_type' => data_get($item->data, 'vendor_type'),
            'shipment_month' => data_get($item->data, 'shipment_month'),
            'delivery_term' => $item->delivery_term,
            'source_count' => 1,
        ];
    }

    /** @var array<int, string|null> */
    protected array $salesContractCache = [];

    /**
     * The Sales Contract of the Workspace row a PRA line came from. Only used
     * for a line whose saved snapshot has none (a PRA created before the field
     * existed); null when the row is gone or carries no contract.
     */
    protected function salesContractFromSource(PaymentRequestItem $item): ?string
    {
        $rowId = (int) $item->excel_row_id;

        if ($rowId <= 0) {
            return null;
        }

        if (! array_key_exists($rowId, $this->salesContractCache)) {
            $row = \App\Models\ExcelRow::with('cells.header')->find($rowId);
            $this->salesContractCache[$rowId] = $row
                ? $this->sourceService->sourceValueForRow($row, 'sales_contract')
                : null;
        }

        return $this->salesContractCache[$rowId];
    }

    protected function mergeApprovalRows($rows): array
    {
        $first = $rows->first();
        $paymentRequiredDate = $rows
            ->map(fn ($row) => $this->sourceService->dateValue($row['payment_required_date'] ?? null))
            ->filter()
            ->sortBy(fn ($date) => $date->timestamp)
            ->first();

        return [
            'booking_po_ids' => $rows->flatMap(fn ($row) => $row['booking_po_ids'] ?? [])->filter()->unique()->values()->all(),
            'vendor_name' => $this->joinUnique($rows->pluck('vendor_name')), 
            'style' => $this->joinUnique($rows->pluck('style')), 
            'pcd_required' => $this->joinUnique($rows->pluck('pcd_required')), 
            'payment_term' => $this->joinUnique($rows->pluck('payment_term')), 
            'material_po_number' => $this->joinUnique($rows->pluck('material_po_number')), 
            'material_pi_number' => $this->joinUnique($rows->pluck('material_pi_number')), 
            'material_type' => $this->joinUnique($rows->pluck('material_type')), 
            'contract_shipment' => $this->joinUnique($rows->pluck('contract_shipment')), 
            'committed_ex_mill' => $this->joinUnique($rows->pluck('committed_ex_mill')), 
            'comments' => $this->joinUnique($rows->pluck('comments'), '(blank)', '; '),
            // Budget adds up like PI Amount when lines merge — both are money
            // belonging to the same delivery. But a group where NO line carries a
            // budget stays null rather than becoming 0.0: summing absent values
            // into a figure would print "$0.00" and assert a budget of nothing.
            'budget' => $this->sumBudget($rows->pluck('budget')),
            'pi_amount' => $rows->sum(fn ($row) => (float) ($row['pi_amount'] ?? 0)),
            'payment_required_date' => $paymentRequiredDate ?: ($first['payment_required_date'] ?? null),
            'buyer_name' => $this->joinUnique($rows->pluck('buyer_name')), 
            'season_name' => $this->joinUnique($rows->pluck('season_name')), 
            'sales_contract' => $this->joinUnique($rows->pluck('sales_contract')),
            'payment_status' => $this->joinUnique($rows->pluck('payment_status')), 
            'final_status' => $this->joinUnique($rows->pluck('final_status')), 
            'vendor_type' => $this->joinUnique($rows->pluck('vendor_type')), 
            'shipment_month' => $this->joinUnique($rows->pluck('shipment_month')), 
            'delivery_term' => $this->joinUnique($rows->pluck('delivery_term')), 
            'source_count' => $rows->sum(fn ($row) => (int) ($row['source_count'] ?? 1)),
        ];
    }

    protected function summaryFromApprovalRows($rows): array
    {
        $rows = collect($rows);
        $dateValues = $rows->map(fn ($row) => $this->sourceService->dateValue($row['payment_required_date'] ?? null))->filter();
        $earliestPaymentRequired = $dateValues->sortBy(fn ($date) => $date->timestamp)->first();

        return [
            'total_pi_amount' => $rows->sum(fn (array $row) => (float) ($row['pi_amount'] ?? 0)),
            'total_budget' => 0,
            'total_savings' => 0,
            // Budget and Saving totals are only trustworthy when EVERY line has a
            // budget; the renderers print them only then.
            'budget_complete' => $rows->isNotEmpty() && $rows->every(fn (array $row) => isset($row['budget'])),
            'budget_sum' => $rows->sum(fn (array $row) => (float) ($row['budget'] ?? 0)),
            'saving_sum' => $rows->sum(fn (array $row) => (float) ($row['budget'] ?? 0)) - $rows->sum(fn (array $row) => (float) ($row['pi_amount'] ?? 0)),
            'total_po_count' => $rows->pluck('material_po_number')->flatMap(fn ($value) => explode(',', (string) $value))->map(fn ($value) => trim($value))->filter()->unique(fn ($value) => Str::lower($value))->count(),
            'pending_payment_count' => $rows->filter(fn (array $row) => $this->sourceService->normalize($row['payment_status'] ?? '') === 'pmt_pending')->count(),
            'earliest_payment_required_date' => $earliestPaymentRequired,
            'payment_status_summary' => $rows->countBy('payment_status')->all(),
            'pi_numbers' => $this->uniqueRowValues($rows, 'material_pi_number'),
            'payment_terms' => $this->uniqueRowValues($rows, 'payment_term'),
            'delivery_terms' => $this->uniqueRowValues($rows, 'delivery_term'),
            'buyers' => $this->uniqueRowValues($rows, 'buyer_name'),
            'seasons' => $this->uniqueRowValues($rows, 'season_name'),
            'sales_contracts' => $this->uniqueRowValues($rows, 'sales_contract'),
            'suppliers' => $this->uniqueRowValues($rows, 'vendor_name'),
            'shipment_months' => $this->uniqueRowValues($rows, 'shipment_month'),
            'vendor_types' => $this->uniqueRowValues($rows, 'vendor_type'),
            'final_statuses' => $this->uniqueRowValues($rows, 'final_status'),
            'payment_statuses' => $this->uniqueRowValues($rows, 'payment_status'),
            'material_types' => $this->uniqueRowValues($rows, 'material_type'),
        ];
    }

    protected function uniqueRowValues($rows, string $key): array
    {
        return collect($rows)
            ->pluck($key)
            ->flatMap(fn ($value) => explode(',', (string) $value))
            ->map(fn ($value) => trim((string) $value))
            ->reject(fn ($value) => $value === '' || $value === '-')
            ->unique(fn ($value) => Str::lower($value))
            ->sort()
            ->values()
            ->all();
    }

    protected function joinUnique($values, string $fallback = '-', string $glue = ', '): string
    {
        $values = collect($values)
            ->map(fn ($value) => trim((string) $value))
            ->reject(fn ($value) => $value === '' || $value === '-')
            ->unique(fn ($value) => Str::lower($value))
            ->values();

        return $values->isEmpty() ? $fallback : $values->implode($glue);
    }

    protected function firstFilled(array $values): string
    {
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '' && $value !== '-') {
                return $value;
            }
        }

        return '';
    }

    /**
     * A line's Budget as a number, or null when the sheet recorded none.
     *
     * The distinction is the whole point of this helper: the "Buget Amount"
     * column is filled on only some rows, and an absent budget must print as
     * "-" rather than as $0.00. A blank string, a null and a non-numeric cell
     * all mean "not recorded"; a literal 0 in the sheet is a real value and is
     * kept as 0.0.
     */
    protected function budgetValue($value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * Total of the budgets in a merged group, or null when none of them
     * recorded one. Used by mergeApprovalRows(); see the note there.
     */
    protected function sumBudget($values): ?float
    {
        $values = collect($values)->filter(fn ($value) => $value !== null);

        return $values->isEmpty() ? null : (float) $values->sum();
    }

    protected function mergeKey(...$values): string
    {
        return collect($values)
            ->map(fn ($value) => $this->sourceService->normalize($value ?? ''))
            ->implode('|');
    }

    protected function reportDate($value): string
    {
        $date = $this->sourceService->dateValue($value);

        return $date ? $date->format('d-M-y') : (trim((string) $value) ?: '-');
    }

    protected function approvalFiltersFromSnapshots($snapshots): array
    {
        return [
            'Shipment Month' => $this->optionValues($snapshots, 'shipment_month')->all(),
            'Vendor Type' => $this->optionValues($snapshots, 'vendor_type')->all(),
            'Final Status' => $this->optionValues($snapshots, 'final_status')->all(),
            'Payment Term' => $this->optionValues($snapshots, 'payment_term')->all(),
            'Payment Status' => $this->optionValues($snapshots, 'payment_status')->all(),
        ];
    }

    protected function nextRequestNo(): string
    {
        $prefix = 'PR-' . now()->format('Ymd') . '-';
        $last = PaymentRequest::query()
            ->where('request_no', 'like', $prefix . '%')
            ->orderByDesc('request_no')
            ->value('request_no');

        $next = 1;
        if ($last && preg_match('/(\d+)$/', $last, $matches)) {
            $next = ((int) $matches[1]) + 1;
        }

        do {
            $requestNo = $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
            $next++;
        } while (PaymentRequest::where('request_no', $requestNo)->exists());

        return $requestNo;
    }

    protected function uniqueText($rows, string $key): ?string
    {
        $values = $rows->pluck($key)
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique(fn ($value) => Str::lower($value))
            ->values();

        if ($values->isEmpty()) {
            return null;
        }

        return $values->take(5)->implode(', ') . ($values->count() > 5 ? ' +' . ($values->count() - 5) : '');
    }

    protected function shortList(array $values, string $fallback = '-'): string
    {
        $values = collect($values)->map(fn ($value) => trim((string) $value))->filter()->values();

        if ($values->isEmpty()) {
            return $fallback;
        }

        return $values->take(4)->implode(', ') . ($values->count() > 4 ? ' +' . ($values->count() - 4) : '');
    }

    protected function itemReportValue(PaymentRequestItem $item, string $key, mixed $fallback = ''): mixed
    {
        $value = data_get($item->data, $key);

        return ($value === null || trim((string) $value) === '') ? $fallback : $value;
    }

    protected function downloadXlsx(PaymentRequest $paymentRequest, array $summary, string $fileName, $approvalRows = null)
    {
        $approvalRows = $approvalRows ? collect($approvalRows) : $this->approvalRowsFromItems($paymentRequest->items);

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Payment Approval');
        $sheet->setShowGridlines(false);

        // L, not K: Budget was added as a 12th column between Comments and PI
        // Amount. Every merge, style range and signature span below is keyed to
        // this table's width, so they all moved one column right with it.
        $lastColumn = 'M';
        $list = fn ($values, $fallback = '-') => collect($values ?? [])->map(fn ($value) => trim((string) $value))->filter()->take(5)->implode(', ') ?: $fallback;
        $moneyFormat = '$#,##0.00;[Red]($#,##0.00)';
        $paymentRequiredDate = $summary['earliest_payment_required_date'] ?? null;
        $paymentRequired = $paymentRequiredDate ? optional($paymentRequiredDate)->format('d / m / Y') : '-';
        $paymentRequiredParts = $paymentRequiredDate
            ? [optional($paymentRequiredDate)->format('d'), optional($paymentRequiredDate)->format('m'), optional($paymentRequiredDate)->format('Y')]
            : ['-', '-', '-'];

        // HEADER BAND, three boxes side by side (rows 1-4):
        //   A:C  logo + company name      D:J  title + PR number      K:M  dates
        // Each is outlined so the top of the sheet reads as a designed header and
        // not loose text. Column M is a narrow spacer inside the print area: it
        // keeps the right-hand borders of column L from being clipped at the page
        // edge in print preview.
        $align = \PhpOffice\PhpSpreadsheet\Style\Alignment::class;
        $border = \PhpOffice\PhpSpreadsheet\Style\Border::class;
        $boxStyle = ['borders' => ['outline' => ['borderStyle' => $border::BORDER_THIN, 'color' => ['argb' => 'FF4B5FAE']]]];

        $logoPath = public_path('images/humana-logo.png');
        if (file_exists($logoPath)) {
            $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
            $drawing->setName('Logo');
            $drawing->setPath($logoPath);
            $drawing->setHeight(46);
            $drawing->setCoordinates('A1');
            $drawing->setOffsetX(10);
            $drawing->setOffsetY(8);
            $drawing->setWorksheet($sheet);
        }

        // Symmetric about the page centre: logo box A:C (46 wide), title box D:I
        // (103 wide, so the title sits dead centre of the 192-wide table) and the
        // dates box J:L (43 wide). The Payment Require Date is a label over three
        // day / month / year boxes so it fits the narrow right-hand box.
        $sheet->mergeCells('A4:C4');
        $sheet->mergeCells('D1:J2');
        $sheet->mergeCells('D3:J4');
        $sheet->mergeCells('K1:M2');
        $sheet->mergeCells('K3:M3');
        $sheet->setCellValue('A4', 'HUMANA APPARELS PVT. LTD.');
        $sheet->setCellValue('D1', 'Payment Request Approval');
        $sheet->setCellValue('D3', $paymentRequest->request_no);
        $sheet->setCellValue('K1', 'Date:  ' . optional($paymentRequest->created_at)->format('jS M-Y'));
        $sheet->setCellValue('K3', 'Payment Require Date:');
        $sheet->setCellValue('K4', $paymentRequiredParts[0]);
        $sheet->setCellValue('L4', $paymentRequiredParts[1]);
        $sheet->setCellValue('M4', $paymentRequiredParts[2]);
        $sheet->getStyle('A1:M4')->getFont()->getColor()->setARGB('FF000B6F');
        $sheet->getStyle('A4')->getFont()->setBold(true)->setSize(9);
        $sheet->getStyle('A4')->getAlignment()->setVertical($align::VERTICAL_CENTER)->setIndent(1);
        $sheet->getStyle('D1')->getFont()->setBold(true)->setSize(20);
        $sheet->getStyle('D3')->getFont()->setSize(10);
        $sheet->getStyle('D1:J4')->getAlignment()->setHorizontal($align::HORIZONTAL_CENTER)->setVertical($align::VERTICAL_CENTER);
        $sheet->getStyle('K1:M3')->getAlignment()->setHorizontal($align::HORIZONTAL_CENTER)->setVertical($align::VERTICAL_CENTER);
        $sheet->getStyle('K1')->getFont()->setBold(true);
        $sheet->getStyle('K3')->getFont()->setBold(true)->setSize(9);
        $sheet->getStyle('K4:M4')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FF000B6F']],
            'borders' => ['allBorders' => ['borderStyle' => $border::BORDER_THIN, 'color' => ['argb' => 'FF8EA0D4']]],
            'alignment' => ['horizontal' => $align::HORIZONTAL_CENTER, 'vertical' => $align::VERTICAL_CENTER],
        ]);

        foreach (['A1:C4', 'D1:J4', 'K1:M4'] as $headerBox) {
            $sheet->getStyle($headerBox)->applyFromArray($boxStyle);
        }

        // MERGED CELLS DRAW THEIR BORDERS FROM THEIR TOP-LEFT CELL in several
        // viewers and print previews (LibreOffice, WPS), so an outline put only on
        // the range edge went missing on merged areas — the dates box and the OCR
        // box lost their right and bottom lines. Each merged area therefore also
        // carries its own outer sides on the top-left cell. Excel hides the inner
        // edges of a merge, so this changes nothing there.
        $edge = ['borderStyle' => $border::BORDER_THIN, 'color' => ['argb' => 'FF4B5FAE']];
        $sheet->getStyle('A4')->applyFromArray(['borders' => ['left' => $edge, 'bottom' => $edge, 'right' => $edge]]);
        $sheet->getStyle('D1')->applyFromArray(['borders' => ['top' => $edge, 'left' => $edge, 'right' => $edge]]);
        $sheet->getStyle('D3')->applyFromArray(['borders' => ['left' => $edge, 'right' => $edge, 'bottom' => $edge]]);
        $sheet->getStyle('K1')->applyFromArray(['borders' => ['top' => $edge, 'left' => $edge, 'right' => $edge]]);
        $sheet->getStyle('K3')->applyFromArray(['borders' => ['left' => $edge, 'right' => $edge]]);

        $sheet->setCellValue('A5', 'Buyer');
        $sheet->setCellValue('B5', ':');
        $sheet->setCellValue('C5', $list($summary['buyers'] ?? [], $paymentRequest->buyer_name ?: '-'));
        $sheet->setCellValue('A6', 'Season');
        $sheet->setCellValue('B6', ':');
        $sheet->setCellValue('C6', $list($summary['seasons'] ?? [], $paymentRequest->season_name ?: '-'));
        $sheet->setCellValue('A7', 'Sales Contract');
        $sheet->setCellValue('B7', ':');
        $sheet->setCellValue('C7', $list($summary['sales_contracts'] ?? [], '-'));
        $sheet->mergeCells('C5:E5');
        $sheet->mergeCells('C6:E6');
        $sheet->mergeCells('C7:E7');
        $sheet->getStyle('A5:C7')->getFont()->setBold(true)->getColor()->setARGB('FF000B6F');

        $sheet->mergeCells('J5:M8');
        // Auto-filled from the Accounts Team check (step 4) on a departmental
        // PRA. Null — and so the original hand-signed blank — for a legacy PRA
        // and for any departmental one that has not reached step 4.
        $ocrCheck = \App\Support\PaymentRequestSettings::ocrCheckFor($paymentRequest);
        $sheet->setCellValue('J5', $ocrCheck
            ? "OCR Checked:     ☒ Yes      ☐ No\n\nChecker Name     : " . $ocrCheck['name']
                . "\n\nDate             : " . $ocrCheck['date']
            : "OCR Checked:     ☐ Yes      ☐ No\n\nChecker Name     : ______________________\n\nDate             : ______________________");
        $sheet->getStyle('J5:M8')->applyFromArray([
            'font' => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FF000B6F']],
            'alignment' => ['wrapText' => true, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP],
            'borders' => ['outline' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FF4B5FAE']]],
        ]);
        // Merged: also give the top-left cell all four sides (see the header boxes).
        $sheet->getStyle('J5')->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
            ->getColor()->setARGB('FF4B5FAE');

        $sheet->mergeCells('A8:E9');
        $sheet->setCellValue('A8', "* Buyer nominated supplier.\n  No excess quantity has been booked.");
        $sheet->getStyle('A8:E9')->getFont()->getColor()->setARGB('FF000B6F');
        $sheet->getStyle('A8:E9')->getAlignment()->setWrapText(true);

        $sheet->mergeCells('J10:M10');
        $sheet->setCellValue('J10', 'Total PI Amount: $ ' . number_format((float) ($summary['total_pi_amount'] ?? 0), 2));
        $sheet->getStyle('J10:M10')->getFont()->setBold(true)->setSize(11)->getColor()->setARGB('FF000B6F');
        $sheet->getStyle('J10:M10')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);

        // Column order matches the preview, the PDF and the HTML Excel export.
        // The row array below is written positionally with fromArray(), so any
        // change here MUST be mirrored there or every column shifts silently.
        // IHOD is this app's name for the contract shipment date (the Booking
        // Preview filter reads the same contract_shipment_date column) - same
        // field, same value, only the label changed.
        //
        // The three money columns close the table in the same order on every
        // renderer: PI Amount, Budget, then Saving (Budget - PI Amount). This
        // export is the only one with a Comments column, which sits before them.
        $headers = ['IHOD', 'Style', 'PCD Required', 'Vendor Name', 'Material PO Number', 'Material PI Number', 'Item Name', 'Payment Term', 'Committed Ex Mill', 'Comments', 'PI Amount (USD)', 'Budget', 'Saving'];
        $tableStart = 11;
        $sheet->fromArray($headers, null, 'A' . $tableStart);
        $sheet->getStyle('A' . $tableStart . ':' . $lastColumn . $tableStart)->applyFromArray([
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE3EBF8']],
            'font' => ['bold' => true, 'size' => 8, 'color' => ['argb' => 'FF000B6F']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FF8EA0D4']]],
        ]);
        $sheet->getRowDimension($tableStart)->setRowHeight(26);

        $rowNo = $tableStart + 1;
        // Sheet rows to merge in column B (Style), collected while the body is
        // written: one entry per run of consecutive rows sharing a style, so the
        // .xlsx groups them the same way the preview and the PDF do.
        $styleMerges = [];

        // Height each body row needs for its longest wrapping cell (8pt text,
        // about 11pt a line). Collected here, applied with the other row heights.
        $bodyHeights = [];

        foreach ($approvalRows as $row) {
            $wrapLines = max(
                1,
                (int) ceil(mb_strlen((string) ($row['comments'] ?? '')) / 17),
                (int) ceil(mb_strlen((string) ($row['vendor_name'] ?? '')) / 24),
                (int) ceil(mb_strlen((string) ($row['material_type'] ?? '')) / 17),
                (int) ceil(mb_strlen((string) ($row['material_po_number'] ?? '')) / 22),
                (int) ceil(mb_strlen((string) ($row['material_pi_number'] ?? '')) / 23),
            );
            $bodyHeights[$rowNo] = max(28, $wrapLines * 11 + 6);
            $span = (int) ($row['style_rowspan'] ?? 1);
            if ($span > 1) {
                $styleMerges[] = ['B' . $rowNo, 'B' . ($rowNo + $span - 1)];
            }

            $sheet->fromArray([
                // Positional — must stay in step with $headers above.
                $row['contract_shipment'] ?: '-',
                $row['style'] ?: '-',
                $row['pcd_required'] ?: '-',
                $row['vendor_name'] ?: '-',
                $row['material_po_number'] ?: '-',
                $row['material_pi_number'] ?: '-',
                $row['material_type'] ?: '-',
                $row['payment_term'] ?: '-',
                $row['committed_ex_mill'] ?: '-',
                $row['comments'] ?: '(blank)',
                (float) ($row['pi_amount'] ?? 0),
                // A recorded budget is written as a NUMBER so the money format
                // applies; an absent one as "-", matching every other empty cell
                // in this table. It must never be written as 0. Saving is
                // Budget - PI Amount, and "-" when there is no budget.
                isset($row['budget']) ? (float) $row['budget'] : '-',
                isset($row['budget']) ? (float) $row['budget'] - (float) ($row['pi_amount'] ?? 0) : '-',
            ], null, 'A' . $rowNo);
            $sheet->getStyle('A' . $rowNo . ':' . $lastColumn . $rowNo)->applyFromArray([
                'font' => ['size' => 8],
                'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FFE4E7EF']]],
            ]);
            $rowNo++;
        }

        // Merge each style run in column B. Done AFTER the body is written:
        // mergeCells keeps the top-left value and blanks the rest, so writing
        // every row first and merging second leaves exactly the style that
        // started the run. Bold + centred to match the other two renderers.
        foreach ($styleMerges as [$from, $to]) {
            $sheet->mergeCells($from . ':' . $to);
            $sheet->getStyle($from . ':' . $to)->getAlignment()
                ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)
                ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle($from . ':' . $to)->getFont()->setBold(true);
        }

        // The label spans A..K (through Budget) and the total sits in L. Budget
        // gets NO total: the column is filled on only some rows, so a partial sum
        // would read as a real ceiling and make a normal PRA look wildly over
        // budget. summaryFromApprovalRows() keeps total_budget at 0 accordingly.
        $sheet->setCellValue('A' . $rowNo, 'Grand Total');
        $sheet->setCellValue('K' . $rowNo, (float) ($summary['total_pi_amount'] ?? 0));
        // Budget and Saving totals only when EVERY line has a budget — a partial
        // sum would read as a real ceiling.
        if (! empty($summary['budget_complete'])) {
            $sheet->setCellValue('L' . $rowNo, (float) ($summary['budget_sum'] ?? 0));
            $sheet->setCellValue('M' . $rowNo, (float) ($summary['saving_sum'] ?? 0));
        }
        $sheet->mergeCells('A' . $rowNo . ':J' . $rowNo);
        $sheet->getStyle('A' . $rowNo . ':' . $lastColumn . $rowNo)->applyFromArray([
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEAF0FB']],
            'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FF000B6F']],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FFE4E7EF']]],
        ]);
        // Both money columns carry the money format and right alignment. Budget
        // rows holding "-" are text, which the format leaves alone.
        foreach (['K', 'L', 'M'] as $moneyColumn) {
            $sheet->getStyle($moneyColumn . ($tableStart + 1) . ':' . $moneyColumn . $rowNo)->getNumberFormat()->setFormatCode($moneyFormat);
            $sheet->getStyle($moneyColumn . ($tableStart + 1) . ':' . $moneyColumn . $rowNo)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
        }
        // Centre every body column except three, matching the preview and the PDF:
        //   K (Budget), L (PI Amount)  right-aligned above — a money column reads
        //                  down its decimal point, so centring would break that.
        //   J (Comments)   left-aligned — free-text sentences, which centring
        //                  makes markedly harder to read.
        // BODY ROWS ONLY — $rowNo is the Grand Total row by this point, and that
        // row's A:K is a merged cell holding the words "Grand Total". Including
        // it would re-centre that label; the total row is left exactly as it was.
        $lastBodyRow = $rowNo - 1;

        if ($lastBodyRow >= $tableStart + 1) {
            foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'] as $centeredColumn) {
                $sheet->getStyle($centeredColumn . ($tableStart + 1) . ':' . $centeredColumn . $lastBodyRow)
                    ->getAlignment()
                    ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            }
        }

        // ROW HEIGHTS by role (points). Kept in one place because the signature
        // footer below is positioned from their sum.
        $rowHeights = [1 => 36, 2 => 22, 3 => 22, 4 => 22, 5 => 24, 6 => 24, 7 => 24, 8 => 24, 9 => 22, 10 => 24, $tableStart => 30];
        foreach (range($tableStart + 1, $lastBodyRow) as $bodyRow) {
            $rowHeights[$bodyRow] = $bodyHeights[$bodyRow] ?? 28;
        }
        $grandRow = $lastBodyRow + 1;
        $rowHeights[$grandRow] = 26;

        // SIGNATURE POSITION. Excel cannot pin cells to the bottom
        // of a printed page, so blank spacer rows are sized to push the block down
        // until it ends at the foot of the page it starts on. A4 landscape at the
        // fit-to-width scale holds about 4pt of sheet rows per column character; a short PRA gets its
        // signatures at the bottom of page 1, a long one at the bottom of its last
        // page.
        $signHeight = 22 + 40 + 22;
        // The page holds more sheet rows the narrower the sheet is, because
        // fit-to-width shrinks a wide table. Measured on a real print preview, an
        // A4 landscape page carries about 4.15pt of rows per character of total
        // column width; 4.0 keeps a safety margin so the signatures never spill
        // onto a second page.
        $columnWidths = ['A' => 16, 'B' => 16, 'C' => 14, 'D' => 20, 'E' => 18, 'F' => 19, 'G' => 14, 'H' => 16, 'I' => 16, 'J' => 14, 'K' => 15, 'L' => 14, 'M' => 14, 'N' => 1.5];
        // A PRA of normal length is fitted to ONE page (fit-to-height 1 below), so
        // an over-estimate here can only shrink the sheet slightly and never push
        // the signatures onto a second page. Only a long PRA flows over pages.
        $singlePage = ($lastBodyRow - $tableStart) <= 24;
        $pageHeight = (int) round(array_sum($columnWidths) * ($singlePage ? 4.15 : 3.9));
        $usedHeight = array_sum($rowHeights);
        // The block follows the table but sits about 62% of the way down the page
        // when the table is short, so it moves with the content: few rows leave it
        // a little higher, more rows push it lower. A long table leaves only a
        // small gap. If it would not fit on the page it starts, it moves to the
        // top of the next one.
        $pageOffset = $usedHeight % $pageHeight;
        $target = $pageHeight * 0.62;
        $spacer = $pageOffset < $target ? $target - $pageOffset : 20;
        if ($pageOffset + $spacer + $signHeight > $pageHeight) {
            $spacer = $pageHeight - $pageOffset;
        }
        $spacer = max(14, $spacer);
        $spacerRows = max(1, (int) ceil($spacer / 120));
        $spacerEach = $spacer / $spacerRows;
        foreach (range($grandRow + 1, $grandRow + $spacerRows) as $spacerRow) {
            $rowHeights[$spacerRow] = $spacerEach;
        }
        // The spacer is empty space: make sure no cell in it carries a border.
        $sheet->getStyle('A' . ($grandRow + 1) . ':N' . ($grandRow + $spacerRows))
            ->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_NONE);

        $rowNo = $grandRow + $spacerRows + 1;

        // Prepared / Checked / Approved By for this PRA (dynamic signatures with
        // the static admin ones as the fallback). FOUR boxes on a departmental
        // PRA, three on a legacy one.
        $signatureBlocks = \App\Support\PaymentRequestSettings::signatureBlocksFor($paymentRequest, false);
        $signatureColumns = count($signatureBlocks) === 4
            ? [['A', 'C'], ['D', 'F'], ['G', 'I'], ['J', 'M']]
            : [['A', 'C'], ['E', 'G'], ['I', 'M']];

        // Each box is three rows: caption, a tall signing space that ends in the
        // signature line (the signature image sits in it), and the
        // "Signature & Date" label. No typed name, date or designation.
        $signRows = ['title' => $rowNo, 'space' => $rowNo + 1, 'label' => $rowNo + 2];
        $rowHeights[$signRows['title']] = 22;
        $rowHeights[$signRows['space']] = 40;
        $rowHeights[$signRows['label']] = 22;

        foreach ($signatureBlocks as $index => $block) {
            [$from, $to] = $signatureColumns[$index];

            foreach ($signRows as $r) {
                $sheet->mergeCells($from . $r . ':' . $to . $r);
            }

            $sheet->setCellValue($from . $signRows['title'], $block['title']);
            $sheet->setCellValue($from . $signRows['label'], 'Signature & Date');

            if (! empty($block['path'])) {
                $absolute = \Illuminate\Support\Facades\Storage::disk('public')->path($block['path']);
                if (is_file($absolute)) {
                    $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
                    $drawing->setPath($absolute);
                    $drawing->setHeight(36);
                    $drawing->setCoordinates($from . $signRows['space']);
                    $drawing->setOffsetX(6);
                    $drawing->setOffsetY(3);
                    $drawing->setWorksheet($sheet);
                }
            }

            $range = $from . $signRows['title'] . ':' . $to . $signRows['label'];
            $sheet->getStyle($range)->getFont()->getColor()->setARGB('FF000B6F');
            $sheet->getStyle($range)->getFont()->setSize(9);
            $sheet->getStyle($range)->getAlignment()->setVertical($align::VERTICAL_CENTER)->setHorizontal($align::HORIZONTAL_LEFT)->setIndent(1);
            $sheet->getStyle($from . $signRows['title'])->getFont()->setBold(true)->setSize(10);
            $sheet->getStyle($from . $signRows['space'] . ':' . $to . $signRows['space'])->applyFromArray([
                'borders' => ['bottom' => ['borderStyle' => $border::BORDER_THIN, 'color' => ['argb' => 'FF000B6F']]],
            ]);
        }

        // Column widths follow their COLUMN through the reorder (A IHOD, B Style,
        // C PCD, D Vendor, E PO, F PI, G Item, H Pay Term, I Ex Mill, J Comments,
        // K Budget, L Amount). M is the narrow print spacer.
        foreach ($columnWidths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        foreach (range(1, $signRows['label']) as $row) {
            $sheet->getRowDimension($row)->setRowHeight($rowHeights[$row] ?? 22);
        }
        $sheet->getPageMargins()->setTop(0.4);
        $sheet->getPageMargins()->setBottom(0.4);
        $sheet->getPageMargins()->setLeft(0.3);
        $sheet->getPageMargins()->setRight(0.3);
        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
        $sheet->getPageSetup()->setFitToWidth(1);
        $sheet->getPageSetup()->setFitToHeight($singlePage ? 1 : 0);
        $sheet->getPageSetup()->setPrintArea('A1:N' . $signRows['label']);
        $sheet->getPageSetup()->setHorizontalCentered(true);

        $tmp = tempnam(sys_get_temp_dir(), 'payment_request_');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($tmp);

        return response()->download($tmp, $fileName)->deleteFileAfterSend(true);
    }
}
