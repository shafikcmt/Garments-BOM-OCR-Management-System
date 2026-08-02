<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\BookingPo;
use App\Models\ExcelCell;
use App\Models\MaterialBulkIssue;
use App\Models\MaterialRequisition;
use App\Models\MaterialStockLedger;
use App\Services\HeaderAliasResolver;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

/**
 * Buyer/Style Stock (module B) — production issue (Excel "Bulk Issuing" sheet).
 * The SPLIT point: each issue divides into bulk / sample / declared liability /
 * calculated dead. May fulfil a pending/approved requisition. Store only.
 */
class MaterialBulkIssueController extends Controller
{
    /** Allowed page sizes for the history table. */
    private const PAGE_SIZES = [10, 20, 50, 100];

    /** Date tabs the history table can be scoped to. */
    private const TABS = ['all', 'today', 'week', 'month'];

    /** Cap on the browse list; past it the server narrows instead of the browser. */
    private const BROWSE_LIMIT = 500;

    /**
     * The fields one free-text term is asked of.
     *
     * Every field the issue matrix shows, so the search box on the form can find
     * a PO by anything printed on its rows — a SAP Code or an Art. No is a
     * perfectly ordinary way for Store to arrive at a booking, and before this
     * the box quietly failed for them.
     *
     * BOTH PO numbers are here. po_no is the material PO this system generates,
     * the one the matrix's "PO Number" column shows and the one a user reading
     * that column will type; contract_po is the buyer's order/contract PO from
     * the GMNTS PO Number columns. They are different numbers and Store uses
     * both, so leaving either out makes the search silently fail for whichever
     * half of the paperwork is in front of them. po_no is also the cheapest
     * group here: a direct column LIKE on booking_pos, no ExcelCell scan.
     *
     * The cost of the rest is real but bounded: each is a LIKE scan over
     * ExcelCell, and a leading wildcard cannot use an index, so this is linear
     * in the size of that table. Measured over 77.5k cells the full set takes
     * ~92ms against ~65ms for the five this used to hold. The browser keeps that
     * in hand by debouncing and by refusing to search a single character — see
     * runSearch() in bulk-issue-table.js. If excel_cells grows an order of
     * magnitude this needs revisiting, most likely as a search index rather than
     * by taking fields away again.
     */
    private const SMART_SEARCH_GROUPS = [
        'po_no', 'contract_po',
        'style', 'buyer', 'season', 'material_name',
        'material_description', 'sap_code', 'art_no', 'gmts_color',
        'material_color', 'size',
    ];

    /** Per-field cap for the smart search, and for its merged result. */
    private const SMART_SEARCH_LIMIT = 50;

    public function index(Request $request)
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'all';
        $q = trim((string) $request->query('q', ''));
        $sort = $request->query('sort') === 'po' ? 'po' : 'date';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        $perPage = (int) $request->query('per_page', 20);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 20;

        $query = MaterialBulkIssue::with('createdBy');
        $this->applyTab($query, $tab);
        $this->applySearch($query, $q);

        if ($sort === 'po') {
            $query->orderBy('po_no', $dir)->orderByDesc('id');
        } else {
            $query->orderBy('issue_date', $dir)->orderByDesc('id');
        }

        $issues = $query->paginate($perPage)->withQueryString();

        // Tab counts reflect the active search (so each tab shows how many of the
        // searched rows fall in that window), but not the active tab itself.
        $counts = collect(self::TABS)->mapWithKeys(fn ($t) => [$t => $this->countFor($t, $q)])->all();

        // AJAX partial swap: the table body + pagination only, so search/sort/tab
        // never trigger a full-page reload.
        // Who may do what, resolved once and shared with both the full page and
        // the AJAX partial so a swapped table keeps the same gating.
        $user = $request->user();
        $canCreate = $user?->can('store.issue') ?? false;
        $canEdit = $user?->can('store.edit') ?? false;
        $canDelete = $user?->can('store.delete') ?? false;

        // AJAX partial swap: the table body + pagination only, so search/sort/tab
        // never trigger a full-page reload.
        if ($request->boolean('partial')) {
            return view('store.material-stock._bulk-issues-table', compact(
                'issues', 'counts', 'tab', 'q', 'sort', 'dir', 'perPage', 'canEdit', 'canDelete'
            ));
        }

        // The picker now fetches POs and their per-row stock on demand, so the
        // page only needs to know whether anything is issuable at all.
        $hasBookingPos = BookingPo::exists();

        // Open requisitions that a bulk issue can fulfil.
        $requisitions = MaterialRequisition::whereIn('status', [
            MaterialRequisition::STATUS_PENDING,
            MaterialRequisition::STATUS_APPROVED,
        ])->latest('id')->get();

        // Standard production sections for the Indent Section dropdown (no master
        // table exists — see config/stock.php).
        $sections = config('stock.indent_sections', []);

        return view('store.material-stock.bulk-issues', compact(
            'issues', 'counts', 'tab', 'q', 'sort', 'dir', 'perPage',
            'hasBookingPos', 'requisitions', 'sections',
            'canCreate', 'canEdit', 'canDelete'
        ));
    }

    /**
     * The full-page New Bulk Issue form.
     *
     * Same form as the index page's slide-in panel — both render the one
     * _bulk-issue-form partial — so this adds a route and a page shell, not a
     * second implementation. The panel stays until this page is signed off.
     */
    public function create(Request $request)
    {
        abort_unless($request->user()?->can('store.issue'), 403);

        return view('store.material-stock.bulk-issue-form', $this->formData() + [
            'editing' => false,
            'issue' => null,
        ]);
    }

    /**
     * The same page in correction mode. The record itself is fetched by the
     * form's own JavaScript through show(), which is the path the panel already
     * used — one prefill route, not two.
     */
    public function edit(Request $request, MaterialBulkIssue $materialBulkIssue)
    {
        abort_unless($request->user()?->can('store.edit'), 403);

        return view('store.material-stock.bulk-issue-form', $this->formData() + [
            'editing' => true,
            'issue' => $materialBulkIssue,
        ]);
    }

    /**
     * What the form needs whichever shell renders it, mirroring index().
     *
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            // The picker fetches POs and their per-row stock on demand, so the
            // page only needs to know whether anything is issuable at all.
            'hasBookingPos' => BookingPo::exists(),
            'requisitions' => MaterialRequisition::whereIn('status', [
                MaterialRequisition::STATUS_PENDING,
                MaterialRequisition::STATUS_APPROVED,
            ])->latest('id')->get(),
            // Standard production sections for the Indent Section dropdown (no
            // master table exists — see config/stock.php).
            'sections' => config('stock.indent_sections', []),
        ];
    }

    /** Scope a query to a date tab window (issue_date). "all" is a no-op. */
    private function applyTab($query, string $tab): void
    {
        match ($tab) {
            'today' => $query->whereDate('issue_date', now()->toDateString()),
            'week' => $query->whereBetween('issue_date', [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()]),
            'month' => $query->whereBetween('issue_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]),
            default => null,
        };
    }

    /** Free-text search across the denormalised identity columns. */
    private function applySearch($query, string $q): void
    {
        if ($q === '') {
            return;
        }

        $query->where(function ($w) use ($q) {
            foreach (['po_no', 'buyer_name', 'style_name', 'material_name', 'material_description', 'sap_code', 'indent_person', 'requisition_number'] as $col) {
                $w->orWhere($col, 'like', '%'.$q.'%');
            }
        });
    }

    /** Count rows for one tab under the active search (used for the tab badges). */
    private function countFor(string $tab, string $q): int
    {
        $query = MaterialBulkIssue::query();
        $this->applyTab($query, $tab);
        $this->applySearch($query, $q);

        return $query->count();
    }

    /**
     * Step 1 of the picker: find a PO by PO / PI / Invoice number.
     *
     * Deliberately its own endpoint rather than calling Receiving's: the two
     * screens now have different access (Bulk Issuing is shared with Admin /
     * Management, Receiving is store-only), so borrowing Receiving's route
     * would 403 for exactly the roles this screen just opened up to. The
     * matching itself is not duplicated — both go through BookingPoSourceService.
     *
     * Receiving also offers SAP Code here; Bulk Issuing intentionally does not,
     * because Store looks up an issue by the paperwork it is issuing against.
     */
    public function poSearch(Request $request)
    {
        $validated = $request->validate([
            // Every handle Store recognises a booking by. All of them resolve
            // through the same source service — the order/booking fields, the
            // material line's own fields, and the paperwork numbers.
            //
            // contract_po is the buyer's order/contract PO (GMNTS PO Number /
            // Initial Contract Number) and is what the "PO Number" option
            // searches. po_no is a different number — the system-generated
            // material PO on booking_pos.po_no — and stays available to the
            // rest of the app even though the picker does not offer it.
            // Not required when `q` is given: the smart search below asks the
            // same question of several fields at once, so it has no single type.
            'type' => ['required_without:q', 'nullable', 'in:po_no,contract_po,season,buyer,style,material_name,material_description,sap_code,art_no,gmts_color,material_color,size'],
            'term' => ['nullable', 'string', 'max:100'],
            // Present once step 1 has been answered: the exact field value whose
            // bookings step 2 should list.
            'value' => ['nullable', 'string', 'max:255'],
            // Free-text across SMART_SEARCH_GROUPS — the single search box.
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $term = trim((string) ($validated['term'] ?? ''));
        $value = trim((string) ($validated['value'] ?? ''));
        $q = trim((string) ($validated['q'] ?? ''));
        $type = $validated['type'] ?? null;
        $source = app(\App\Services\BookingPoSourceService::class);

        // Smart search: one term asked of the handles Store actually recognises
        // a booking by, answered with bookings rather than with field values.
        //
        // Deliberately a loop over the existing per-field matcher rather than a
        // new query: bookingPosMatching() already resolves a term through the
        // header aliases and the booking_pos column fallback, and Receiving's
        // picker has been using it that way. Deduplicated by id because one
        // term can legitimately reach the same booking through two fields.
        if ($q !== '') {
            $bookings = collect();
            foreach (self::SMART_SEARCH_GROUPS as $group) {
                $bookings = $bookings->concat($source->bookingPosMatching($group, $q, self::SMART_SEARCH_LIMIT));
            }

            $bookings = $bookings->unique('id')->take(self::SMART_SEARCH_LIMIT)->values();

            return response()->json([
                // Shaped as step 2 so the browser renders these with the same
                // booking row it already uses for a drilled-down list.
                'step' => 2,
                'mode' => 'smart',
                'value' => $q,
                'complete' => false,
                'results' => $bookings->map(fn (BookingPo $po) => $this->poOption($po))->values(),
            ]);
        }

        // `q` present but blank and no field chosen: nothing has been asked yet.
        if ($type === null) {
            return response()->json(['step' => 1, 'complete' => true, 'results' => []]);
        }

        // The drill-down is two steps, and the same endpoint serves both:
        // without a `value` it lists what the chosen field holds; with one it
        // lists the bookings under that value. Both go through the one generic
        // pair resolver, so every search type behaves identically.
        if ($value !== '') {
            $bookings = $source->bookingPosForGroupValue($type, $value, self::BROWSE_LIMIT);

            return response()->json([
                'step' => 2,
                'value' => $value,
                'complete' => $bookings->count() < self::BROWSE_LIMIT,
                'results' => $bookings->map(fn (BookingPo $po) => $this->poOption($po))->values(),
            ]);
        }

        // Step 1 — the field's own distinct values, each with how many bookings
        // it covers. A term narrows that list rather than jumping to bookings,
        // so typing and browsing land in the same place.
        $values = $source->distinctValuesForGroup($type, self::BROWSE_LIMIT);

        if ($term !== '') {
            $needle = mb_strtolower($term);
            $values = $values->filter(fn (array $v) => str_contains(mb_strtolower($v['value']), $needle))->values();
        }

        return response()->json([
            'step' => 1,
            // Whole dataset in hand means the browser can filter locally and
            // stop calling back on every keystroke.
            'complete' => $values->count() < self::BROWSE_LIMIT,
            'results' => $values,
        ]);
    }

    /**
     * One booking as the picker's step 2 shows it. Style travels too, so the
     * meta line can identify a booking without repeating whichever field the
     * user already chose in step 1.
     *
     * @return array<string, mixed>
     */
    private function poOption(BookingPo $po): array
    {
        return [
            'id' => $po->id,
            'value' => $po->po_no,
            'po_no' => $po->po_no,
            'buyer_name' => $po->buyer_name,
            'season_name' => $po->season_name,
            'style_name' => $po->style_name,
            'vendor_name' => $po->vendor_name,
        ];
    }

    /**
     * Steps 3–4 of the picker: every material line under one PO, grouped by
     * style in the browser.
     *
     * Unlike Receiving, each line carries its *available* stock (what the ledger
     * says is on hand) rather than an ordered/received figure — Bulk Issuing
     * takes stock out, so what matters is what is left, not what is incoming.
     */
    public function poItems(BookingPo $bookingPo)
    {
        if ($bookingPo->excelFile && $bookingPo->excelFile->isLockedForUser(auth()->user())) {
            return response()->json(['locked' => true], 423);
        }

        $rows = app(\App\Services\BookingPoSourceService::class)->itemRowsForBookingPo($bookingPo);
        $prefill = $this->rowPrefill($rows->pluck('id'));

        $items = $rows->map(function ($row) use ($bookingPo, $prefill) {
            $identity = $this->identityForRow($bookingPo, $row);
            $extra = $prefill[$row->id] ?? ['running' => 0.0, 'gmts_order_qty' => null];

            return array_merge($identity, [
                'available' => $extra['running'],
                'gmts_order_qty' => $extra['gmts_order_qty'],
            ]);
        })->values();

        return response()->json([
            'po_no' => $bookingPo->po_no,
            'buyer_name' => $bookingPo->buyer_name,
            'season_name' => $bookingPo->season_name,
            'items' => $items,
        ]);
    }

    /** Quantity for a message: 4dp storage without the trailing zeros. */
    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    /**
     * Available (running) stock per BOM row, straight from the ledger.
     *
     * material_stock_ledgers is a cache, but a synchronously maintained one: the
     * TriggersMaterialStockLedger trait recalculates the affected key on every
     * save/delete of a stock event, so this is the live balance rather than a
     * stale snapshot. Summed across sizes to match exactly what the picker shows
     * the user, so the screen and the guard can never disagree.
     *
     * @return array<int, float>
     */
    private function availableByRow($rowIds): array
    {
        $rowIds = collect($rowIds)->filter()->unique()->values();
        if ($rowIds->isEmpty()) {
            return [];
        }

        return MaterialStockLedger::whereIn('excel_row_id', $rowIds->all())
            ->get(['excel_row_id', 'running_closing_qty'])
            ->groupBy('excel_row_id')
            ->map(fn ($group) => (float) $group->sum('running_closing_qty'))
            ->all();
    }

    /**
     * Available stock + suggested bulk qty per BOM row. Same resolution as
     * bookingPoPrefill(), keyed by excel row instead of by PO so a multi-line PO
     * reports each material line separately.
     *
     * @return array<int, array{running: float, gmts_order_qty: ?string}>
     */
    private function rowPrefill($rowIds): array
    {
        $rowIds = collect($rowIds)->filter()->unique()->values();
        if ($rowIds->isEmpty()) {
            return [];
        }

        $running = MaterialStockLedger::whereIn('excel_row_id', $rowIds->all())
            ->get(['excel_row_id', 'running_closing_qty'])
            ->groupBy('excel_row_id')
            ->map(fn ($group) => (float) $group->sum('running_closing_qty'));

        $storeRoleId = Role::where('name', 'store')->value('id');
        $gmtsId = app(HeaderAliasResolver::class)
            ->resolveHeaderId('gmts_order_qty', $storeRoleId ? (int) $storeRoleId : null);

        $gmts = $gmtsId
            ? ExcelCell::whereIn('row_id', $rowIds->all())
                ->where('header_id', $gmtsId)
                ->get(['row_id', 'value'])
                ->mapWithKeys(fn ($cell) => [(int) $cell->row_id => trim((string) $cell->value)])
            : collect();

        $prefill = [];
        foreach ($rowIds as $rowId) {
            $g = $gmts->get((int) $rowId);
            $prefill[(int) $rowId] = [
                'running' => (float) ($running->get((int) $rowId) ?? 0),
                'gmts_order_qty' => ($g !== null && $g !== '') ? $g : null,
            ];
        }

        return $prefill;
    }

    /**
     * Identity for one specific material line under the PO.
     *
     * The primary line is exactly what booking_pos describes, so it delegates to
     * identityFor() and single-line behaviour is unchanged. A sibling line has no
     * BookingPo record of its own, so its item-level identity is read from that
     * worksheet row and is deliberately NOT allowed to fall back to the PO
     * master — that master describes the primary material, and using it here
     * would stamp the wrong style/colour/size onto the issue. PO-level identity
     * (buyer, season, PO no) is shared by every line, so the master is valid for
     * those. Mirrors Receiving's autoFieldsForRow().
     *
     * @return array<string, mixed>
     */
    private function identityForRow(BookingPo $po, \App\Models\ExcelRow $row): array
    {
        if ((int) $row->id === (int) $po->excel_row_id) {
            return $this->identityFor($po);
        }

        $source = app(\App\Services\BookingPoSourceService::class);

        $item = function (string $group) use ($source, $row): ?string {
            $value = $source->sourceValueForRow($row, $group);

            return ($value !== null && trim($value) !== '') ? $value : null;
        };

        return [
            'excel_file_id' => $po->excel_file_id,
            'excel_row_id' => $row->id,
            'booking_po_id' => $po->id,
            // Shared across every line under this PO.
            'po_no' => $po->po_no,
            'buyer_name' => $po->buyer_name,
            'season_name' => $po->season_name,
            // No UOM column exists in the current workbooks; the PO master is the
            // only available source until one appears.
            'uom' => $item('uom') ?? $po->uom,
            // Specific to this material line.
            'style_name' => $item('style'),
            'material_name' => $item('material_name'),
            'material_description' => $item('material_description'),
            'gmts_color_name' => $item('gmts_color'),
            'art_no' => $item('art_no'),
            'sap_code' => $item('sap_code'),
            'material_color' => $item('material_color'),
            'size' => $item('size'),
        ];
    }

    /**
     * Read-only PO / Material identity for one Booking PO, shown in the form's
     * summary card after a PO is chosen. Resolved through the same source
     * service the save path uses, so what Store sees is exactly what gets
     * stored — nothing here is typed by hand.
     */
    public function poDetails(BookingPo $bookingPo)
    {
        if ($bookingPo->excelFile && $bookingPo->excelFile->isLockedForUser(auth()->user())) {
            return response()->json(['locked' => true], 423);
        }

        return response()->json($this->identityFor($bookingPo));
    }

    /**
     * Denormalized identity copied onto the issue row (and shown read-only on the
     * form). toStockPayload() already carries buyer/season/style/PO/description/
     * SAP/colour/size/uom; the three below are the Excel "Bulk Issuing" columns
     * that live on the BOM row and are resolved the same way Receiving resolves
     * them.
     *
     * @return array<string, mixed>
     */
    private function identityFor(BookingPo $po): array
    {
        $source = app(\App\Services\BookingPoSourceService::class);

        return array_merge($po->toStockPayload(), [
            'material_name' => $source->sourceValueForBookingPo($po, 'material_name'),
            'gmts_color_name' => $source->sourceValueForBookingPo($po, 'gmts_color'),
            'art_no' => $source->sourceValueForBookingPo($po, 'art_no'),
        ]);
    }

    public function store(Request $request)
    {
        // Admin / Management can reach this page to review and correct, but only
        // roles holding store.issue actually record new issues.
        $this->authorizeCorrection('store.issue');

        // The picker submits one row per selected material line. Indent info and
        // the dates are entered once and shared, exactly as Receiving shares its
        // delivery header across the items it books.
        $validated = $request->validate([
            // The header PO is now a FALLBACK, not the source of truth. A row
            // may name its own booking (rows.*.booking_po_id) so one issue can
            // span several POs; a row that names none inherits this, which is
            // exactly what the single-PO form has always posted. Both shapes
            // are accepted, so the existing form keeps working unchanged.
            'booking_po_id' => ['nullable', 'exists:booking_pos,id'],
            'rows.*.booking_po_id' => ['nullable', 'exists:booking_pos,id'],
            'material_requisition_id' => ['nullable', 'exists:material_requisitions,id'],
            // Indent header (Excel "Bulk Issuing" register). All optional.
            'indent_section' => ['nullable', 'string', 'max:100'],
            'indent_person' => ['nullable', 'string', 'max:100'],
            'requisition_number' => ['nullable', 'string', 'max:100'],
            'issue_no' => ['nullable', 'string', 'max:100'],
            'issue_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.excel_row_id' => ['required', 'integer', 'exists:excel_rows,id'],
            // Unchanged per-row rules — one issued line still validates exactly
            // as a single issue always did.
            'rows.*.bulk_qty' => ['nullable', 'numeric', 'min:0'],
            'rows.*.sample_qty' => ['nullable', 'numeric', 'min:0'],
            'rows.*.liability_qty' => ['nullable', 'numeric', 'min:0'],
            'rows.*.dead_qty' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Resolve every row to its booking first: the row's own, or the header's
        // as a fallback. Nothing else can proceed until each row knows which PO
        // it belongs to.
        $headerPoId = $validated['booking_po_id'] ?? null;
        $rowPoIds = [];

        foreach ($validated['rows'] as $index => $row) {
            $poId = $row['booking_po_id'] ?? $headerPoId;

            if (! $poId) {
                return back()->with('warning', 'Item '.($index + 1).' is not linked to a PO. Please reselect the items.');
            }

            $rowPoIds[$index] = (int) $poId;
        }

        // One query for however many distinct POs the submission touches —
        // usually one.
        $pos = BookingPo::with('excelFile')->findMany(array_unique($rowPoIds))->keyBy('id');

        foreach ($pos as $po) {
            if ($po->excelFile && $po->excelFile->isLockedForUser(auth()->user())) {
                return back()->with('warning', 'PO '.$po->po_no.': this file/style is locked. Stock entry is not allowed.');
            }
        }

        // One issue, one buyer. The indent header — section, person, requisition
        // number — is entered once and applies to every row, which only makes
        // sense for a single buyer's paperwork. Rows spanning two buyers is
        // almost certainly a mistake, so it is refused rather than recorded.
        $buyers = $pos->map(fn (BookingPo $po) => trim((string) $po->buyer_name))
            ->filter()->unique()->values();

        if ($buyers->count() > 1) {
            return back()->with('warning', 'An issue cannot mix buyers ('.$buyers->implode(', ').
                '). Record one issue per buyer.');
        }

        // Only the rows genuinely under a PO can be issued against it, so a
        // tampered excel_row_id cannot attach an issue to an unrelated BOM line.
        // Resolved once per PO rather than once per row.
        $source = app(\App\Services\BookingPoSourceService::class);
        $allowedByPo = $pos->map(fn (BookingPo $po) => $source->itemRowsForBookingPo($po)->keyBy('id'));

        // Stock integrity: nothing may be issued beyond what is on hand. The
        // browser blocks this too, but that is convenience — this is the rule.
        // Keyed by excel row, so it is unaffected by how the rows group into POs.
        $available = $this->availableByRow(collect($validated['rows'])->pluck('excel_row_id'));

        $prepared = [];
        foreach ($validated['rows'] as $index => $row) {
            $po = $pos->get($rowPoIds[$index]);
            $rowModel = $allowedByPo->get($rowPoIds[$index])->get((int) $row['excel_row_id']);

            if (! $rowModel) {
                return back()->with('warning', 'Item '.($index + 1).' does not belong to PO '.$po->po_no.
                    '. Please reselect the items.');
            }

            $quantities = [
                'bulk_qty' => (float) ($row['bulk_qty'] ?? 0),
                'sample_qty' => (float) ($row['sample_qty'] ?? 0),
                'liability_qty' => (float) ($row['liability_qty'] ?? 0),
                'dead_qty' => (float) ($row['dead_qty'] ?? 0),
            ];

            $label = 'Item '.($index + 1);

            if (array_sum($quantities) <= 0) {
                return back()->with('warning', $label.': enter at least one of bulk / sample / liability / dead quantity.');
            }

            $onHand = (float) ($available[(int) $row['excel_row_id']] ?? 0);

            if ($onHand <= 0) {
                return back()->with('warning', $label.' has no available stock and cannot be issued.');
            }

            // Tolerance mirrors the 4dp the quantities are stored at, so a value
            // equal to the balance is never rejected by float noise.
            if (array_sum($quantities) > $onHand + 1e-9) {
                return back()->with('warning', $label.': issue quantity ('.$this->trim(array_sum($quantities)).
                    ') exceeds available stock ('.$this->trim($onHand).').');
            }

            // The row's own PO travels with it, so identity is stamped from the
            // booking that row actually belongs to rather than from one header.
            $prepared[] = [$po, $rowModel, $quantities];
        }

        $shared = [
            'material_requisition_id' => $validated['material_requisition_id'] ?? null,
            'indent_section' => $validated['indent_section'] ?? null,
            'indent_person' => $validated['indent_person'] ?? null,
            'requisition_number' => $validated['requisition_number'] ?? null,
            'issue_no' => $validated['issue_no'] ?? null,
            'issue_date' => $validated['issue_date'],
            'remarks' => $validated['remarks'] ?? null,
            'created_by' => auth()->id(),
        ];

        // All-or-nothing: a half-saved multi-item issue would leave closing stock
        // reflecting only some of the lines the user confirmed.
        \Illuminate\Support\Facades\DB::transaction(function () use ($prepared, $shared) {
            foreach ($prepared as [$rowPo, $rowModel, $quantities]) {
                MaterialBulkIssue::create(array_merge(
                    $this->identityForRow($rowPo, $rowModel),
                    $shared,
                    $quantities,
                ));
            }
        });

        // Mark the fulfilled requisition as issued.
        if (! empty($validated['material_requisition_id'])) {
            MaterialRequisition::where('id', $validated['material_requisition_id'])
                ->update(['status' => MaterialRequisition::STATUS_ISSUED]);
        }

        $count = count($prepared);

        return back()->with('success', $count === 1
            ? 'Bulk issue recorded. Closing stock updated.'
            : $count.' bulk issues recorded. Closing stock updated.');
    }

    /**
     * One issue as JSON, to prefill the edit slide-in panel.
     */
    public function show(MaterialBulkIssue $materialBulkIssue)
    {
        return response()->json([
            'id' => $materialBulkIssue->id,
            'booking_po_id' => $materialBulkIssue->booking_po_id,
            // Lets the edit panel reselect the exact material line this issue
            // was recorded against.
            'excel_row_id' => $materialBulkIssue->excel_row_id,
            'material_requisition_id' => $materialBulkIssue->material_requisition_id,
            'indent_section' => $materialBulkIssue->indent_section,
            'indent_person' => $materialBulkIssue->indent_person,
            'requisition_number' => $materialBulkIssue->requisition_number,
            'issue_no' => $materialBulkIssue->issue_no,
            'issue_date' => optional($materialBulkIssue->issue_date)->toDateString(),
            'bulk_qty' => (float) $materialBulkIssue->bulk_qty,
            'sample_qty' => (float) $materialBulkIssue->sample_qty,
            'liability_qty' => (float) $materialBulkIssue->liability_qty,
            'dead_qty' => (float) $materialBulkIssue->dead_qty,
            'remarks' => $materialBulkIssue->remarks,
            // Identity (read-only display in the panel).
            'po_no' => $materialBulkIssue->po_no,
            'buyer_name' => $materialBulkIssue->buyer_name,
            'season_name' => $materialBulkIssue->season_name,
            'style_name' => $materialBulkIssue->style_name,
            'material_name' => $materialBulkIssue->material_name,
            'material_description' => $materialBulkIssue->material_description,
            'gmts_color_name' => $materialBulkIssue->gmts_color_name,
            'art_no' => $materialBulkIssue->art_no,
            'sap_code' => $materialBulkIssue->sap_code,
            'material_color' => $materialBulkIssue->material_color,
            'size' => $materialBulkIssue->size,
            'uom' => $materialBulkIssue->uom,
        ]);
    }

    /**
     * Edit an existing issue. Re-resolves identity from the (possibly changed)
     * PO so a moved issue never keeps stale identity, and the model's ledger
     * trigger recomputes closing stock on save. Same validation as store().
     */
    public function update(Request $request, MaterialBulkIssue $materialBulkIssue)
    {
        $this->authorizeCorrection('store.edit');

        $validated = $this->validateIssue($request);

        $quantities = $this->quantities($validated);
        if (array_sum($quantities) <= 0) {
            return back()->with('warning', 'Enter at least one of bulk / sample / liability / dead quantity.');
        }

        // Stock integrity, same rule as store(). The running balance already has
        // THIS issue's quantities deducted, so they are added back before the
        // comparison — otherwise re-saving an unchanged issue would fail.
        $targetRowId = (int) ($validated['excel_row_id'] ?? $materialBulkIssue->excel_row_id);
        $onHand = (float) ($this->availableByRow([$targetRowId])[$targetRowId] ?? 0);

        if ((int) $materialBulkIssue->excel_row_id === $targetRowId) {
            $onHand += (float) $materialBulkIssue->bulk_qty
                + (float) $materialBulkIssue->sample_qty
                + (float) $materialBulkIssue->liability_qty
                + (float) $materialBulkIssue->dead_qty;
        }

        if (array_sum($quantities) > $onHand + 1e-9) {
            return back()->with('warning', 'Issue quantity ('.$this->trim(array_sum($quantities)).
                ') exceeds available stock ('.$this->trim($onHand).').');
        }

        $po = BookingPo::with('excelFile')->findOrFail($validated['booking_po_id']);
        if ($po->excelFile && $po->excelFile->isLockedForUser(auth()->user())) {
            return back()->with('warning', 'This file/style is locked. Stock entry is not allowed.');
        }

        // Re-resolve identity from the specific material line when the edit panel
        // names one, so correcting an issue can also move it to the right BOM row.
        $identity = $this->identityFor($po);
        if (! empty($validated['excel_row_id'])) {
            $rowModel = app(\App\Services\BookingPoSourceService::class)
                ->itemRowsForBookingPo($po)
                ->firstWhere('id', (int) $validated['excel_row_id']);

            if (! $rowModel) {
                return back()->with('warning', 'That item does not belong to the selected PO. Please reselect the item.');
            }

            $identity = $this->identityForRow($po, $rowModel);
        }

        $materialBulkIssue->update(array_merge(
            $identity,
            [
                'material_requisition_id' => $validated['material_requisition_id'] ?? null,
                'indent_section' => $validated['indent_section'] ?? null,
                'indent_person' => $validated['indent_person'] ?? null,
                'requisition_number' => $validated['requisition_number'] ?? null,
                'issue_no' => $validated['issue_no'] ?? null,
                'issue_date' => $validated['issue_date'],
            ],
            $quantities,
            ['remarks' => $validated['remarks'] ?? null],
        ));

        return back()->with('success', 'Bulk issue updated. Closing stock updated.');
    }

    public function destroy(MaterialBulkIssue $materialBulkIssue)
    {
        $this->authorizeCorrection('store.delete');

        $materialBulkIssue->delete();

        return back()->with('success', 'Bulk issue removed. Closing stock updated.');
    }

    /**
     * Guard for the correction actions (edit / delete). Hiding the buttons in
     * the view is presentation only — this is what actually stops a hand-crafted
     * request from a role without the permission.
     */
    private function authorizeCorrection(string $permission): void
    {
        $messages = [
            'store.issue' => 'You do not have permission to record a bulk issue.',
            'store.edit' => 'You do not have permission to edit a recorded bulk issue.',
            'store.delete' => 'You do not have permission to delete a recorded bulk issue.',
        ];

        abort_unless(
            auth()->user()?->can($permission) ?? false,
            403,
            $messages[$permission] ?? 'You do not have permission for this action.',
        );
    }

    /**
     * Delete every selected issue. Each delete triggers the ledger recompute
     * via the model, exactly as a single destroy would.
     */
    public function bulkDestroy(Request $request)
    {
        $this->authorizeCorrection('store.delete');

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:material_bulk_issues,id'],
        ]);

        $issues = MaterialBulkIssue::whereIn('id', $validated['ids'])->get();
        foreach ($issues as $issue) {
            $issue->delete();
        }

        $count = $issues->count();

        return back()->with('success', $count.' bulk issue'.($count === 1 ? '' : 's').' removed. Closing stock updated.');
    }

    /**
     * Excel export of the selected issues (FromView, so preview == download).
     */
    public function exportExcel(Request $request)
    {
        $issues = $this->selectedIssues($request);

        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\BulkIssueExport($issues),
            'bulk-issues-'.now()->format('Ymd-His').'.xlsx',
        );
    }

    /**
     * PDF export of the selected issues (A4 landscape, per 05_PDF_EXCEL rules).
     */
    public function exportPdf(Request $request)
    {
        $issues = $this->selectedIssues($request);

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('store.material-stock.bulk-issues-pdf', [
            'issues' => $issues,
            'generatedAt' => now(),
        ])->setPaper('a4', 'landscape')->download('bulk-issues-'.now()->format('Ymd-His').'.pdf');
    }

    /**
     * Validated selection for the export endpoints, kept in the same order the
     * user selected them where possible (falls back to newest first).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, MaterialBulkIssue>
     */
    private function selectedIssues(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:material_bulk_issues,id'],
        ]);

        return MaterialBulkIssue::whereIn('id', $validated['ids'])
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Shared validation for store()/update(). store() keeps its own inline copy
     * for backwards safety; update() uses this.
     *
     * @return array<string, mixed>
     */
    private function validateIssue(Request $request): array
    {
        return $request->validate([
            'booking_po_id' => ['required', 'exists:booking_pos,id'],
            // Which material line under the PO this issue belongs to. Optional so
            // an issue recorded before the item picker existed still updates,
            // falling back to the PO's primary line.
            'excel_row_id' => ['nullable', 'integer', 'exists:excel_rows,id'],
            'material_requisition_id' => ['nullable', 'exists:material_requisitions,id'],
            'indent_section' => ['nullable', 'string', 'max:100'],
            'indent_person' => ['nullable', 'string', 'max:100'],
            'requisition_number' => ['nullable', 'string', 'max:100'],
            'issue_no' => ['nullable', 'string', 'max:100'],
            'issue_date' => ['required', 'date'],
            'bulk_qty' => ['nullable', 'numeric', 'min:0'],
            'sample_qty' => ['nullable', 'numeric', 'min:0'],
            'liability_qty' => ['nullable', 'numeric', 'min:0'],
            'dead_qty' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /**
     * The four-way split as floats, keyed for a create/update payload.
     *
     * @param  array<string, mixed>  $validated
     * @return array{bulk_qty: float, sample_qty: float, liability_qty: float, dead_qty: float}
     */
    private function quantities(array $validated): array
    {
        return [
            'bulk_qty' => (float) ($validated['bulk_qty'] ?? 0),
            'sample_qty' => (float) ($validated['sample_qty'] ?? 0),
            'liability_qty' => (float) ($validated['liability_qty'] ?? 0),
            'dead_qty' => (float) ($validated['dead_qty'] ?? 0),
        ];
    }
}
