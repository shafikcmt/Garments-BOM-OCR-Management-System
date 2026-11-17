<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\GeneralStockSupplier;
use App\Models\GsComparativeStatement;
use App\Models\StockPurchase;
use App\Services\ComparativeStatementService;
use App\Services\PurchaseQueueService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * General Stock purchasing: the Comparative Statement screens.
 *
 * Who may do what is the service's allowed() answer, so the buttons on the
 * page and the routes cannot disagree. The route group only decides who may
 * open the section (store.purchase_cs.view).
 */
class ComparativeStatementController extends Controller
{
    public function __construct(
        private readonly ComparativeStatementService $service,
        private readonly PurchaseQueueService $queue,
    ) {
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:30'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $statements = GsComparativeStatement::query()
            ->with(['creator:id,name', 'suppliers:id,gs_comparative_statement_id,name,is_recommended'])
            ->withCount('items')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['search'] ?? null, function ($q, $s) {
                $q->where(fn ($w) => $w->whereLike('cs_no', "%{$s}%")
                    ->orWhereHas('items', fn ($i) => $i->whereLike('item_name', "%{$s}%"))
                    ->orWhereHas('suppliers', fn ($i) => $i->whereLike('name', "%{$s}%")));
            })
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('store.purchasing.cs-index', [
            'statements' => $statements,
            'filters' => $filters,
            'statusLabels' => GsComparativeStatement::statusLabels(),
            'canCreate' => $request->user()->can('store.purchase_cs.create'),
        ]);
    }

    /**
     * The queue posts its ticked lines here (hundreds of ids would not fit in a URL),
     * then we hand over to the create screen.
     */
    public function start(Request $request)
    {
        abort_unless($request->user()->can('store.purchase_cs.create'), 403);

        $ids = $request->validate(['lines' => ['required', 'array', 'min:1'], 'lines.*' => ['integer']], [
            'lines.required' => 'Tick at least one item on the To Purchase list first.',
        ])['lines'];

        $request->session()->put('cs_start_lines', array_values($ids));

        return redirect()->route('store.purchasing.cs.create');
    }

    /** Start a CS from lines ticked on the To Purchase queue. */
    public function create(Request $request)
    {
        abort_unless($request->user()->can('store.purchase_cs.create'), 403);

        $ids = collect((array) ($request->query('lines') ?: $request->session()->get('cs_start_lines', [])))->map(fn ($v) => (int) $v)->filter()->unique()->all();
        $lines = $this->queue->lines()->whereIn('id', $ids)->values();

        if ($lines->isEmpty()) {
            return redirect()->route('store.purchasing.queue')
                ->with('warning', 'Tick at least one item on the To Purchase list first.');
        }

        return view('store.purchasing.cs-form', $this->formData(null, $lines
            ->sortBy(fn ($l) => $l->category_name.'|'.$l->purchase_requisition_id.'|'.$l->sort_order)->values()
            ->map(fn ($l) => $this->lineFromRequisition($l))->all()));
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('store.purchase_cs.create'), 403);

        $this->unpackLines($request);
        $data = $request->validate($this->payloadRules(), $this->messages());
        $cs = $this->service->save(null, $request->user(), $data);
        $request->session()->forget('cs_start_lines');

        if ($request->input('action') === 'submit') {
            $this->service->submit($cs, $request->user());

            return redirect()->route('store.purchasing.cs.show', $cs)->with('success', $cs->cs_no.' saved and sent for checking.');
        }

        return redirect()->route('store.purchasing.cs.show', $cs)->with('success', $cs->cs_no.' saved as a draft.');
    }

    public function show(GsComparativeStatement $cs, Request $request)
    {
        $cs->load(['items.quotes', 'suppliers', 'preparedBy:id,name', 'checkedBy:id,name', 'approvedBy:id,name', 'returnedBy:id,name', 'creator:id,name', 'purchaseOrders']);

        return view('store.purchasing.cs-show', [
            'cs' => $cs,
            'allowed' => $this->service->allowed($cs, $request->user()),
            'comparison' => app(\App\Services\CsComparison::class)->for($cs),
            'poPlan' => $cs->isApproved() ? app(\App\Services\PurchaseOrderService::class)->plan($cs) : collect(),
        ]);
    }

    public function edit(GsComparativeStatement $cs, Request $request)
    {
        $this->authoriseAction($cs, $request, 'edit');
        $cs->load(['items.quotes', 'suppliers', 'items.requisitionItem.requisition']);

        // What each requisition line can still give this sheet: its own qty plus what no other live CS holds.
        $covered = $this->queue->coveredQty($cs->items->pluck('purchase_requisition_item_id')->filter()->all(), $cs->id);

        return view('store.purchasing.cs-form', $this->formData($cs, $cs->items->map(fn ($i) => $this->lineFromItem($i, $covered))->all()));
    }

    public function update(Request $request, GsComparativeStatement $cs)
    {
        $this->authoriseAction($cs, $request, 'edit');

        $this->unpackLines($request);
        $data = $request->validate($this->payloadRules(), $this->messages());
        $cs = $this->service->save($cs, $request->user(), $data);

        if ($request->input('action') === 'submit') {
            $this->service->submit($cs, $request->user());

            return redirect()->route('store.purchasing.cs.show', $cs)->with('success', $cs->cs_no.' saved and sent for checking.');
        }

        return redirect()->route('store.purchasing.cs.show', $cs)->with('success', $cs->cs_no.' saved.');
    }

    public function submit(Request $request, GsComparativeStatement $cs)
    {
        $this->authoriseAction($cs, $request, 'submit');
        $this->service->submit($cs, $request->user());

        return back()->with('success', $cs->cs_no.' sent for checking.');
    }

    public function check(Request $request, GsComparativeStatement $cs)
    {
        $this->authoriseAction($cs, $request, 'check');
        $this->service->check($cs, $request->user());

        return back()->with('success', $cs->cs_no.' checked and sent for approval.');
    }

    public function approve(Request $request, GsComparativeStatement $cs)
    {
        $this->authoriseAction($cs, $request, 'approve');
        $note = $request->validate(['approver_note' => ['nullable', 'string', 'max:1000']])['approver_note'] ?? null;
        $this->service->approve($cs, $request->user(), $note);

        return back()->with('success', $cs->cs_no.' approved. You can now make the purchase order.');
    }

    public function sendBack(Request $request, GsComparativeStatement $cs)
    {
        $this->authoriseAction($cs, $request, 'send_back');
        $note = $request->validate(['note' => ['required', 'string', 'max:1000']], ['note.required' => 'Write what needs to be corrected.'])['note'];
        $this->service->sendBack($cs, $request->user(), $note);

        return back()->with('success', $cs->cs_no.' sent back to the preparer.');
    }

    public function cancel(Request $request, GsComparativeStatement $cs)
    {
        $this->authoriseAction($cs, $request, 'cancel');
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'Write why it is being cancelled.'])['reason'];
        $this->service->cancel($cs, $request->user(), $reason);

        return redirect()->route('store.purchasing.cs.index')->with('success', $cs->cs_no.' cancelled. Its items are back on the To Purchase list.');
    }

    /** The printed form: A4 landscape, same control box and sign-off boxes as the paper CS. */
    public function pdf(GsComparativeStatement $cs)
    {
        $cs->load(['items.quotes', 'suppliers', 'preparedBy:id,name', 'checkedBy:id,name', 'approvedBy:id,name']);

        return Pdf::loadView('store.purchasing.cs-pdf', ['cs' => $cs])
            ->setPaper('a4', 'landscape')
            ->stream('CS-'.str_replace(['/', '\\'], '-', $cs->cs_no).'.pdf');
    }

    /**
     * A monthly statement can carry 500+ items with several fields each, more than
     * PHP's max_input_vars would let through a normal form post. The form therefore
     * sends the item grid as one JSON field; it is turned back into `lines` here.
     */
    private function unpackLines(Request $request): void
    {
        $json = $request->input('lines_json');

        if (is_string($json) && $json !== '') {
            $lines = json_decode($json, true);
            abort_unless(is_array($lines), 422, 'The item list could not be read. Reload the page and try again.');
            $request->merge(['lines' => $lines]);
            $request->request->remove('lines_json');
        }
    }

    private function authoriseAction(GsComparativeStatement $cs, Request $request, string $action): void
    {
        abort_unless(in_array($action, $this->service->allowed($cs, $request->user()), true), 403, 'You cannot do this on this Comparative Statement right now.');
    }

    /** @return array<string, mixed> */
    private function payloadRules(): array
    {
        $max = (int) config('purchasing.max_suppliers', 4);

        return [
            'cs_date' => ['required', 'date'],
            'recommendation' => ['nullable', 'string', 'max:2000'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'signatories' => ['nullable', 'array', 'max:'.\App\Models\GsComparativeStatement::MAX_SIGNATORIES],
            'signatories.*.label' => ['nullable', 'string', 'max:60'],
            'signatories.*.role' => ['nullable', 'in:'.implode(',', array_keys(\App\Models\GsComparativeStatement::SIGN_ROLES))],
            'suppliers' => ['required', 'array', 'min:1', 'max:'.$max],
            'suppliers.*.key' => ['nullable', 'string', 'max:10'],
            'suppliers.*.name' => ['required', 'string', 'max:255'],
            'suppliers.*.general_stock_supplier_id' => ['nullable', 'integer', 'exists:general_stock_suppliers,id'],
            'suppliers.*.address' => ['nullable', 'string', 'max:1000'],
            'suppliers.*.phone' => ['nullable', 'string', 'max:100'],
            'suppliers.*.terms' => ['nullable', 'string', 'max:2000'],
            'recommended_supplier' => ['nullable', 'string', 'max:10'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_requisition_item_id' => ['required', 'integer', 'exists:purchase_requisition_items,id'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.category' => ['nullable', 'string', 'max:120'],
            'lines.*.specification' => ['nullable', 'string', 'max:255'],
            'lines.*.current_supplier' => ['nullable', 'string', 'max:255'],
            'lines.*.remarks' => ['nullable', 'string', 'max:500'],
            'lines.*.selected' => ['nullable', 'string', 'max:10'],
            'lines.*.quotes' => ['nullable', 'array'],
            'lines.*.quotes.*.price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.quotes.*.spec' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'suppliers.required' => 'Add at least one supplier.',
            'suppliers.*.name.required' => 'Enter the supplier name.',
            'lines.required' => 'Add at least one item.',
            'lines.*.qty.required' => 'Enter the quantity.',
            'lines.*.qty.min' => 'Quantity must be more than zero.',
            'lines.*.quotes.*.price.numeric' => 'Enter the unit price as a number.',
        ];
    }

    /**
     * What the form needs. `$lines` is a list of arrays (see lineFromRequisition).
     * Old input wins over everything, so a failed save loses nothing typed.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function formData(?GsComparativeStatement $cs, array $lines): array
    {
        $suppliers = $cs
            ? $cs->suppliers->values()->map(fn ($s, $i) => [
                'key' => (string) $i, 'general_stock_supplier_id' => $s->general_stock_supplier_id, 'name' => $s->name,
                'address' => $s->address, 'phone' => $s->phone, 'terms' => $s->terms,
            ])->all()
            : [];

        $recommended = $cs ? (string) max(0, $cs->suppliers->values()->search(fn ($s) => $s->is_recommended)) : null;

        if ($cs) {
            $keyById = $cs->suppliers->values()->mapWithKeys(fn ($s, $i) => [$s->id => (string) $i])->all();
            $lines = collect($lines)->map(function (array $l) use ($keyById) {
                $l['selected'] = $keyById[$l['selected']] ?? null;
                $l['quotes'] = collect($l['quotes'])->mapWithKeys(fn ($q, $sid) => [$keyById[$sid] ?? $sid => $q])->all();

                return $l;
            })->all();
        }

        return [
            'cs' => $cs,
            'state' => [
                'cs_date' => old('cs_date', $cs?->cs_date?->toDateString() ?? now()->toDateString()),
                'recommendation' => old('recommendation', $cs?->recommendation),
                'remarks' => old('remarks', $cs?->remarks),
                'signatories' => old('signatories', $cs?->signBoxes() ?? \App\Models\GsComparativeStatement::defaultSignatories()),
                'suppliers' => old('suppliers', $suppliers),
                'recommended' => old('recommended_supplier', $recommended),
                'lines' => old('lines', $lines),
            ],
            'savedSuppliers' => GeneralStockSupplier::selectable()->get(['id', 'name', 'address', 'phone']),
            'maxSuppliers' => (int) config('purchasing.max_suppliers', 4),
            'minSuppliers' => (int) config('purchasing.min_suppliers_to_submit', 2),
            'nextNo' => $cs?->cs_no ?? app(\App\Services\GsDocumentNumberGenerator::class)->previewCs(),
        ];
    }

    /** @return array<string, mixed> */
    private function lineFromRequisition(\App\Models\PurchaseRequisitionItem $l): array
    {
        $last = $l->stock_item_id
            ? StockPurchase::query()->where('stock_item_id', $l->stock_item_id)->whereNotNull('supplier_name')->latest('purchase_date')->latest('id')->value('supplier_name')
            : null;

        return [
            'purchase_requisition_item_id' => $l->id,
            'item_name' => $l->displayName(),
            'category' => $l->category_name ?? PurchaseQueueService::categoryOf($l),
            'uom' => $l->uom_snapshot ?: $l->stockItem?->uom,
            'requisition_no' => $l->requisition?->requisition_no,
            'qty' => rtrim(rtrim(number_format((float) $l->outstanding_qty, 4, '.', ''), '0'), '.'),
            'max_qty' => (float) $l->outstanding_qty,
            'specification' => $l->specification,
            'current_supplier' => $last,
            'last_rate' => $l->last_purchase_rate_snapshot !== null ? (float) $l->last_purchase_rate_snapshot : null,
            'remarks' => null,
            'selected' => null,
            'quotes' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function lineFromItem(\App\Models\GsCsItem $i, array $covered): array
    {
        return [
            'purchase_requisition_item_id' => $i->purchase_requisition_item_id,
            'item_name' => $i->item_name,
            'category' => $i->category,
            'uom' => $i->uom,
            'requisition_no' => $i->requisitionItem?->requisition?->requisition_no,
            'qty' => rtrim(rtrim(number_format((float) $i->qty, 4, '.', ''), '0'), '.'),
            'max_qty' => max((float) $i->qty, (float) ($i->requisitionItem?->to_be_procured_qty ?? $i->qty) - ($covered[$i->purchase_requisition_item_id] ?? 0.0)),
            'specification' => $i->specification,
            'current_supplier' => $i->current_supplier,
            'last_rate' => $i->requisitionItem?->last_purchase_rate_snapshot !== null ? (float) $i->requisitionItem->last_purchase_rate_snapshot : null,
            'remarks' => $i->remarks,
            'selected' => $i->selected_supplier_id,
            'quotes' => $i->quotes->mapWithKeys(fn ($q) => [$q->gs_cs_supplier_id => [
                'price' => $q->unit_price !== null ? rtrim(rtrim(number_format((float) $q->unit_price, 4, '.', ''), '0'), '.') : null,
                'spec' => $q->specification,
            ]])->all(),
        ];
    }
}
