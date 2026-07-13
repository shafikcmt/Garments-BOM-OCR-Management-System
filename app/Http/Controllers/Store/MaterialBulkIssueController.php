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
    public function index(Request $request)
    {
        $issues = MaterialBulkIssue::with('createdBy')
            ->latest('issue_date')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $bookingPos = BookingPo::with('excelFile')->orderByDesc('id')->take(1000)->get();

        // Open requisitions that a bulk issue can fulfil.
        $requisitions = MaterialRequisition::whereIn('status', [
            MaterialRequisition::STATUS_PENDING,
            MaterialRequisition::STATUS_APPROVED,
        ])->latest('id')->get();

        // Per-PO helper data: current available (running) stock from the ledger,
        // and a suggested bulk_qty default from the BOM row's GMTS Order Qty.
        $prefill = $this->bookingPoPrefill($bookingPos);

        return view('store.material-stock.bulk-issues', compact('issues', 'bookingPos', 'requisitions', 'prefill'));
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

    public function store(Request $request)
    {
        $validated = $request->validate([
            'booking_po_id' => ['required', 'exists:booking_pos,id'],
            'material_requisition_id' => ['nullable', 'exists:material_requisitions,id'],
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
            $po->toStockPayload(),
            [
                'material_requisition_id' => $validated['material_requisition_id'] ?? null,
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

    public function destroy(MaterialBulkIssue $materialBulkIssue)
    {
        $materialBulkIssue->delete();

        return back()->with('success', 'Bulk issue removed. Closing stock updated.');
    }
}
