<?php

namespace App\Http\Controllers\Store;

use App\Exports\RequisitionDocumentExport;
use App\Http\Controllers\Concerns\AuthorizesStoreCorrections;
use App\Http\Controllers\Controller;
use App\Models\IndentPerson;
use App\Models\IndentSection;
use App\Models\ItemCategory;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Models\StockItem;
use App\Services\MonthlyRequisitionReportService;
use App\Services\PurchaseRequisitionNumberGenerator;
use App\Services\PurchaseRequisitionService;
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
    ) {
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'status' => ['nullable', 'string', 'in:'.implode(',', PurchaseRequisition::STATUS_FLOW)],
            'section' => ['nullable', 'integer', 'exists:indent_sections,id'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $requisitions = PurchaseRequisition::query()
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
                    ->orWhereHas('items.stockItem', fn ($i) => $i->whereLike('name', $like)));
            })
            ->orderByDesc('requisition_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('store.stock.requisitions.index', [
            'requisitions' => $requisitions,
            'filters' => $filters,
            'sections' => IndentSection::selectable()->get(['id', 'name']),
            'statusLabels' => PurchaseRequisition::statusLabels(),
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

        return view('store.stock.requisitions.create', $this->formData() + [
            'mode' => $mode,
            // A preview, not a reservation: whoever saves first takes it, and
            // it changes with the section, so the form recomputes it there too.
            'nextNo' => $this->numbers->preview(null),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $requisition = DB::transaction(function () use ($data) {
            // Optional fields are absent from the validated array when they are
            // not posted at all, so every read of one has to tolerate a missing
            // key, not just an empty value.
            $section = ($data['indent_section_id'] ?? null)
                ? IndentSection::find($data['indent_section_id'])
                : null;

            $date = Carbon::parse($data['requisition_date']);

            // A number typed on the form wins — it lets a paper document that
            // already carries a number be entered as-is. Otherwise the
            // allocator takes the next one for this section and month.
            //
            // The key may be ABSENT, not merely empty: the form drops the
            // field's name when the operator never edited the suggestion, so
            // that an untouched preview cannot bypass the allocator.
            $number = ($data['requisition_no'] ?? null)
                ?: $this->numbers->next($section?->name, $date);

            $requisition = PurchaseRequisition::create([
                'requisition_no' => $number,
                'requisition_date' => $data['requisition_date'],
                'mode' => $data['mode'],
                'unit_name' => $data['unit_name'] ?? config('stock.company_name'),
                'indent_section_id' => $data['indent_section_id'] ?? null,
                'indent_person_id' => $data['indent_person_id'] ?? null,
                'contact' => $data['contact'] ?? null,
                'status' => $data['action'] === 'submit'
                    ? PurchaseRequisition::STATUS_SUBMITTED
                    : PurchaseRequisition::STATUS_DRAFT,
                'submitted_at' => $data['action'] === 'submit' ? now() : null,
                'remarks' => $data['remarks'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $this->syncItems($requisition, $data['items'], $date);

            return $requisition;
        });

        $verb = $requisition->status === PurchaseRequisition::STATUS_SUBMITTED ? 'submitted' : 'saved as draft';

        return redirect()
            ->route('store.stock.requisitions.show', $requisition)
            ->with('success', 'Requisition '.$requisition->requisition_no.' '.$verb.'.');
    }

    public function show(PurchaseRequisition $requisition)
    {
        $requisition->load([
            'items.stockItem', 'items.itemCategory',
            'indentSection', 'indentPerson', 'createdBy',
            'storeAckBy', 'accountsAckBy', 'checkedBy', 'approvedBy',
        ]);

        return view('store.stock.requisitions.show', [
            'requisition' => $requisition,
            'statusLabels' => PurchaseRequisition::statusLabels(),
            'typeLabels' => PurchaseRequisitionItem::typeLabels(),
            'canDelete' => $this->storeCorrectionAbilities()['delete'],
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
        $data = $this->documentData($requisition, $report);

        // Landscape A4: the sheet is 21 columns wide and will not fit portrait.
        return \Barryvdh\DomPDF\Facade\Pdf::loadView('store.stock.requisitions.document-pdf', $data)
            ->setPaper('a4', 'landscape')
            ->download($this->documentFilename($requisition).'.pdf');
    }

    /** The same document as a single-sheet workbook. */
    public function excel(PurchaseRequisition $requisition, MonthlyRequisitionReportService $report)
    {
        $data = $this->documentData($requisition, $report);

        return Excel::download(
            new RequisitionDocumentExport($requisition, $data['rows'], $data['title']),
            $this->documentFilename($requisition).'.xlsx',
        );
    }

    /**
     * Only a Draft can be reopened. Once submitted the figures on it have been
     * read by someone else, so a change is a correction — that is stage 2 and
     * is permission-gated.
     */
    public function edit(PurchaseRequisition $requisition)
    {
        abort_unless($requisition->isEditable(), 403,
            'This requisition has already been submitted and can no longer be edited.');

        $requisition->load('items.stockItem');

        return view('store.stock.requisitions.create', $this->formData() + [
            'mode' => $requisition->mode,
            'requisition' => $requisition,
            'nextNo' => $requisition->requisition_no,
        ]);
    }

    public function update(Request $request, PurchaseRequisition $requisition)
    {
        abort_unless($requisition->isEditable(), 403,
            'This requisition has already been submitted and can no longer be edited.');

        $data = $this->validated($request, $requisition);

        DB::transaction(function () use ($data, $requisition) {
            $requisition->update([
                'requisition_no' => ($data['requisition_no'] ?? null) ?: $requisition->requisition_no,
                'requisition_date' => $data['requisition_date'],
                'mode' => $data['mode'],
                'unit_name' => $data['unit_name'] ?? $requisition->unit_name,
                'indent_section_id' => $data['indent_section_id'] ?? null,
                'indent_person_id' => $data['indent_person_id'] ?? null,
                'contact' => $data['contact'] ?? null,
                'status' => $data['action'] === 'submit'
                    ? PurchaseRequisition::STATUS_SUBMITTED
                    : PurchaseRequisition::STATUS_DRAFT,
                'submitted_at' => $data['action'] === 'submit' ? now() : null,
                'remarks' => $data['remarks'] ?? null,
            ]);

            // Lines are replaced wholesale: the form posts the complete set, and
            // a draft has no downstream references to preserve.
            $requisition->items()->delete();
            $this->syncItems($requisition, $data['items'], Carbon::parse($data['requisition_date']));
        });

        return redirect()
            ->route('store.stock.requisitions.show', $requisition)
            ->with('success', 'Requisition '.$requisition->requisition_no.' updated.');
    }

    public function destroy(PurchaseRequisition $requisition)
    {
        $this->authorizeStoreDelete('purchase requisition');

        $no = $requisition->requisition_no;
        $requisition->delete();

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
        return response()->json(array_merge($snapshot, [
            'uom' => $stockItem->uom,
            'specification' => $stockItem->specification,
            'item_category_id' => $stockItem->item_category_id,
            'last_purchase_date' => $snapshot['last_purchase_date']
                ? Carbon::parse($snapshot['last_purchase_date'])->format('d-M-y')
                : null,
        ]));
    }

    /** The next Requisition No for a section, so the form can update it live. */
    public function nextNumber(Request $request): JsonResponse
    {
        $section = $request->filled('section')
            ? IndentSection::find($request->query('section'))?->name
            : null;

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
            'storeAckBy', 'accountsAckBy', 'checkedBy', 'approvedBy',
        ]);

        return [
            'requisition' => $requisition,
            'rows' => $report->requisitionRows($requisition),
            'title' => 'Purchase Requisition',
        ];
    }

    /** A Requisition No carries slashes, so it cannot go into a filename as typed. */
    private function documentFilename(PurchaseRequisition $requisition): string
    {
        return 'Purchase-Requisition-'.Str::slug($requisition->requisition_no ?: (string) $requisition->id);
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        return [
            'items' => $this->requisitions->selectableItems(),
            'categories' => ItemCategory::selectable()->get(['id', 'name']),
            'sections' => IndentSection::selectable()->get(['id', 'name']),
            'persons' => IndentPerson::selectable()->get(['id', 'name']),
            'typeLabels' => PurchaseRequisitionItem::typeLabels(),
            'modeLabels' => PurchaseRequisition::modeLabels(),
            'unitName' => config('stock.company_name'),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?PurchaseRequisition $existing = null): array
    {
        $unique = 'unique:purchase_requisitions,requisition_no'.($existing ? ','.$existing->id : '');

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
            'items.*.stock_item_id' => ['required', 'integer', 'exists:stock_items,id'],
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

        $itemIds = array_map(fn ($line) => (int) $line['stock_item_id'], $lines);
        $snapshots = $this->requisitions->snapshots($itemIds, $on);
        $stockItems = StockItem::whereIn('id', $itemIds)->get()->keyBy('id');

        foreach ($lines as $index => $line) {
            $itemId = (int) $line['stock_item_id'];
            $stockItem = $stockItems->get($itemId);
            $snapshot = $snapshots[$itemId] ?? [];

            $qty = (float) $line['qty_requested'];
            $toBeProcured = $this->requisitions->toBeProcured($qty, $snapshot['stock_in_hand'] ?? null);

            // Rate defaults to the last purchase rate but is editable, so an
            // expected price rise can be planned for.
            $typed = $line['rate_appx'] ?? null;
            $rate = ($typed !== null && $typed !== '')
                ? (float) $typed
                : ($snapshot['last_purchase_rate'] ?? null);

            $requisition->items()->create([
                'stock_item_id' => $itemId,
                'item_category_id' => $stockItem?->item_category_id,
                'sort_order' => $index,
                'uom_snapshot' => $stockItem?->uom,
                'specification' => $line['specification'] ?? $stockItem?->specification,
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
            ]);
        }
    }
}
