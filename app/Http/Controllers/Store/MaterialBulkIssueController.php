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
        if ($request->boolean('partial')) {
            return view('store.material-stock._bulk-issues-table', compact('issues', 'counts', 'tab', 'q', 'sort', 'dir', 'perPage'));
        }

        $bookingPos = BookingPo::with('excelFile')->orderByDesc('id')->take(1000)->get();

        // Open requisitions that a bulk issue can fulfil.
        $requisitions = MaterialRequisition::whereIn('status', [
            MaterialRequisition::STATUS_PENDING,
            MaterialRequisition::STATUS_APPROVED,
        ])->latest('id')->get();

        // Per-PO helper data: current available (running) stock from the ledger,
        // and a suggested bulk_qty default from the BOM row's GMTS Order Qty.
        $prefill = $this->bookingPoPrefill($bookingPos);

        // Standard production sections for the Indent Section dropdown (no master
        // table exists — see config/stock.php).
        $sections = config('stock.indent_sections', []);

        return view('store.material-stock.bulk-issues', compact(
            'issues', 'counts', 'tab', 'q', 'sort', 'dir', 'perPage',
            'bookingPos', 'requisitions', 'prefill', 'sections'
        ));
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
     * Per booking_po_id: running_closing_qty (available stock, summed across
     * sizes from material_stock_ledgers) + suggested bulk_qty default from the
     * Store-owned GMTS Order Qty BOM cell.
     *
     * @return array<int, array{running: float, gmts_order_qty: ?string}>
     */
    private function bookingPoPrefill($bookingPos): array
    {
        $rowIds = $bookingPos->pluck('excel_row_id')->filter()->unique()->values();
        if ($rowIds->isEmpty()) {
            return [];
        }

        // Available stock per BOM row (all sizes) from the cached ledger.
        $running = MaterialStockLedger::whereIn('excel_row_id', $rowIds->all())
            ->get(['excel_row_id', 'running_closing_qty'])
            ->groupBy('excel_row_id')
            ->map(fn ($group) => (float) $group->sum('running_closing_qty'));

        // GMTS Order Qty is Store-owned; resolve its exact header id (filtered to
        // the store role) to avoid matching the customer-contract alias.
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
        foreach ($bookingPos as $po) {
            $g = $gmts->get($po->excel_row_id);
            $prefill[$po->id] = [
                'running' => (float) ($running->get($po->excel_row_id) ?? 0),
                'gmts_order_qty' => ($g !== null && $g !== '') ? $g : null,
            ];
        }

        return $prefill;
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
        $validated = $request->validate([
            'booking_po_id' => ['required', 'exists:booking_pos,id'],
            'material_requisition_id' => ['nullable', 'exists:material_requisitions,id'],
            // Indent header (Excel "Bulk Issuing" register). All optional.
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

        $bulk = (float) ($validated['bulk_qty'] ?? 0);
        $sample = (float) ($validated['sample_qty'] ?? 0);
        $liability = (float) ($validated['liability_qty'] ?? 0);
        $dead = (float) ($validated['dead_qty'] ?? 0);

        if (($bulk + $sample + $liability + $dead) <= 0) {
            return back()->with('warning', 'Enter at least one of bulk / sample / liability / dead quantity.');
        }

        $po = BookingPo::with('excelFile')->findOrFail($validated['booking_po_id']);

        if ($po->excelFile && $po->excelFile->isLockedForUser(auth()->user())) {
            return back()->with('warning', 'This file/style is locked. Stock entry is not allowed.');
        }

        MaterialBulkIssue::create(array_merge(
            $this->identityFor($po),
            [
                'material_requisition_id' => $validated['material_requisition_id'] ?? null,
                'indent_section' => $validated['indent_section'] ?? null,
                'indent_person' => $validated['indent_person'] ?? null,
                'requisition_number' => $validated['requisition_number'] ?? null,
                'issue_no' => $validated['issue_no'] ?? null,
                'issue_date' => $validated['issue_date'],
                'bulk_qty' => $bulk,
                'sample_qty' => $sample,
                'liability_qty' => $liability,
                'dead_qty' => $dead,
                'remarks' => $validated['remarks'] ?? null,
                'created_by' => auth()->id(),
            ]
        ));

        // Mark the fulfilled requisition as issued.
        if (! empty($validated['material_requisition_id'])) {
            MaterialRequisition::where('id', $validated['material_requisition_id'])
                ->update(['status' => MaterialRequisition::STATUS_ISSUED]);
        }

        return back()->with('success', 'Bulk issue recorded. Closing stock updated.');
    }

    /**
     * One issue as JSON, to prefill the edit slide-in panel.
     */
    public function show(MaterialBulkIssue $materialBulkIssue)
    {
        return response()->json([
            'id' => $materialBulkIssue->id,
            'booking_po_id' => $materialBulkIssue->booking_po_id,
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
        $validated = $this->validateIssue($request);

        $quantities = $this->quantities($validated);
        if (array_sum($quantities) <= 0) {
            return back()->with('warning', 'Enter at least one of bulk / sample / liability / dead quantity.');
        }

        $po = BookingPo::with('excelFile')->findOrFail($validated['booking_po_id']);
        if ($po->excelFile && $po->excelFile->isLockedForUser(auth()->user())) {
            return back()->with('warning', 'This file/style is locked. Stock entry is not allowed.');
        }

        $materialBulkIssue->update(array_merge(
            $this->identityFor($po),
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
        $materialBulkIssue->delete();

        return back()->with('success', 'Bulk issue removed. Closing stock updated.');
    }

    /**
     * Delete every selected issue. Each delete triggers the ledger recompute
     * via the model, exactly as a single destroy would.
     */
    public function bulkDestroy(Request $request)
    {
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
