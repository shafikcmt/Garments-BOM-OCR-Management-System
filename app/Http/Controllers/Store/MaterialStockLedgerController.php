<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\MaterialDeadMovement;
use App\Models\MaterialLiabilityMovement;
use App\Models\MaterialStockLedger;
use Illuminate\Http\Request;
use App\Support\Concerns\ScopesToAssignedBuyers;

/**
 * Buyer/Style Stock (module B) — Closing Stock report (Excel "Closing Stock"
 * master). Reads the cached material_stock_ledgers table. Also records reuse:
 * Liability / Dead stock transferred back to Bulk (returns to Running) or issued
 * as sample. These are real transactions, not manual overrides. Store only.
 */
class MaterialStockLedgerController extends Controller
{
    use ScopesToAssignedBuyers;

    public function index(Request $request)
    {
        // Buyer scope before the screen's own filters. NEVER on buyer_name, which
        // the filters below match on: that column is denormalised free text a
        // merchant can edit, fine as a filter and useless as a guard.
        $query = $this->scopedLedger();

        if ($buyer = $request->input('buyer')) {
            $query->where('buyer_name', $buyer);
        }
        if ($style = $request->input('style')) {
            $query->where('style_name', $style);
        }
        // Structured filters, ANDed with the Buyer/Style dropdowns and the free
        // text box above. Each is an exact match on a denormalized identity
        // column the ledger already carries.
        if ($season = $request->input('season')) {
            $query->where('season_name', $season);
        }
        if ($poNo = $request->input('po_no')) {
            $query->where('po_no', $poNo);
        }
        if ($gmtsColor = $request->input('gmts_color')) {
            $query->where('gmts_color_name', $gmtsColor);
        }
        if ($search = $request->input('q')) {
            // whereLike compiles to ILIKE on PostgreSQL, where a plain LIKE is
            // case-sensitive and quietly returns less than it should. SAP codes
            // and colour names are mixed case, so this matters here.
            $query->where(function ($q) use ($search) {
                $q->whereLike('material_description', "%{$search}%")
                    ->orWhereLike('sap_code', "%{$search}%")
                    ->orWhereLike('po_no', "%{$search}%")
                    ->orWhereLike('material_color', "%{$search}%");
            });
        }

        $ledgers = $query->orderBy('buyer_name')
            ->orderBy('style_name')
            ->orderBy('po_no')
            ->paginate(30)
            ->withQueryString();

        // Options come from the ledger itself, so a dropdown never offers a value
        // that would return an empty report — and they run through the same
        // buyer scope as the rows, or the Buyer dropdown would name every buyer
        // in the factory to a user allowed to see one of them.
        $buyers = $this->filterOptions('buyer_name');
        $styles = $this->filterOptions('style_name');
        $seasons = $this->filterOptions('season_name');
        $poNos = $this->filterOptions('po_no');
        $gmtsColors = $this->filterOptions('gmts_color_name');

        // Totals for the summary cards. Scoped too: a card reading the whole
        // factory's closing value above a list showing one buyer's rows is both
        // a leak and a figure nobody can reconcile.
        $totals = [
            'running' => (float) $this->scopedLedger()->sum('running_closing_qty'),
            'liability' => (float) $this->scopedLedger()->sum('liability_closing_qty'),
            'dead' => (float) $this->scopedLedger()->sum('dead_closing_qty'),
            'value' => (float) $this->scopedLedger()->sum('total_value'),
        ];

        return view('store.material-stock.ledger', compact(
            'ledgers', 'buyers', 'styles', 'seasons', 'poNos', 'gmtsColors', 'totals'
        ));
    }

    /**
     * A ledger query with the buyer scope already on it.
     *
     * Every read on this screen starts here — the row list, the five filter
     * dropdowns and the four summary totals. They used to be eight separate
     * MaterialStockLedger::query() calls, which is eight chances for the next
     * one to be written without the scope.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function scopedLedger()
    {
        $query = MaterialStockLedger::query();

        $this->applyBuyerScope($query);

        return $query;
    }
    /**
     * Distinct non-blank values of one ledger column, for a filter dropdown.
     * Trimmed and de-duplicated in PHP so values that differ only by padding do
     * not appear twice in the list.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function filterOptions(string $column): \Illuminate\Support\Collection
    {
        return $this->scopedLedger()
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * Move qty OUT of Liability stock: transfer back to Bulk (reuse) and/or issue
     * as sample. Cannot exceed the current liability closing balance.
     */
    public function storeLiabilityMovement(Request $request, MaterialStockLedger $ledger)
    {
        // The ledger row named by id belongs to a buyer through its BOM file.
        // Both movement endpoints write stock, so both are refused outright
        // rather than left as the one unguarded write on a scoped screen.
        $this->assertBuyerAccessVia($ledger);

        $data = $this->validateMovement($request);

        if (($data['transfer_to_bulk_qty'] + $data['sample_issue_qty']) <= 0) {
            return back()->with('warning', 'Enter a transfer-to-bulk or sample quantity.');
        }

        $available = (float) $ledger->liability_closing_qty;
        if (($data['transfer_to_bulk_qty'] + $data['sample_issue_qty']) > $available + 1e-6) {
            return back()->with('warning', 'Movement exceeds available Liability closing stock (' . rtrim(rtrim(number_format($available, 4), '0'), '.') . ').');
        }

        MaterialLiabilityMovement::create($this->movementPayload($ledger, $data));

        return back()->with('success', 'Liability movement recorded. Closing stock updated.');
    }

    /**
     * Move qty OUT of Dead stock: transfer back to Bulk (reuse) and/or issue as
     * sample. Cannot exceed the current dead closing balance.
     */
    public function storeDeadMovement(Request $request, MaterialStockLedger $ledger)
    {
        $this->assertBuyerAccessVia($ledger);

        $data = $this->validateMovement($request);

        if (($data['transfer_to_bulk_qty'] + $data['sample_issue_qty']) <= 0) {
            return back()->with('warning', 'Enter a transfer-to-bulk or sample quantity.');
        }

        $available = (float) $ledger->dead_closing_qty;
        if (($data['transfer_to_bulk_qty'] + $data['sample_issue_qty']) > $available + 1e-6) {
            return back()->with('warning', 'Movement exceeds available Dead closing stock (' . rtrim(rtrim(number_format($available, 4), '0'), '.') . ').');
        }

        MaterialDeadMovement::create($this->movementPayload($ledger, $data));

        return back()->with('success', 'Dead movement recorded. Closing stock updated.');
    }

    /**
     * @return array{movement_date:string, transfer_to_bulk_qty:float, sample_issue_qty:float, remarks:?string}
     */
    protected function validateMovement(Request $request): array
    {
        $v = $request->validate([
            'movement_date' => ['required', 'date'],
            'transfer_to_bulk_qty' => ['nullable', 'numeric', 'min:0'],
            'sample_issue_qty' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $v['transfer_to_bulk_qty'] = (float) ($v['transfer_to_bulk_qty'] ?? 0);
        $v['sample_issue_qty'] = (float) ($v['sample_issue_qty'] ?? 0);

        return $v;
    }

    /**
     * Copy the ledger row's identity onto a new movement event.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function movementPayload(MaterialStockLedger $ledger, array $data): array
    {
        return [
            'excel_file_id' => $ledger->excel_file_id,
            'excel_row_id' => $ledger->excel_row_id,
            'booking_po_id' => $ledger->booking_po_id,
            'po_no' => $ledger->po_no,
            'buyer_name' => $ledger->buyer_name,
            'season_name' => $ledger->season_name,
            'style_name' => $ledger->style_name,
            'material_description' => $ledger->material_description,
            'sap_code' => $ledger->sap_code,
            'material_color' => $ledger->material_color,
            'size' => $ledger->size,
            'uom' => $ledger->uom,
            'movement_date' => $data['movement_date'],
            'transfer_to_bulk_qty' => $data['transfer_to_bulk_qty'],
            'sample_issue_qty' => $data['sample_issue_qty'],
            'remarks' => $data['remarks'] ?? null,
            'created_by' => auth()->id(),
        ];
    }
}
