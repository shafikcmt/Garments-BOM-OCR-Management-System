<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\MaterialDeadMovement;
use App\Models\MaterialLiabilityMovement;
use App\Models\MaterialStockLedger;
use Illuminate\Http\Request;

/**
 * Buyer/Style Stock (module B) — Closing Stock report (Excel "Closing Stock"
 * master). Reads the cached material_stock_ledgers table. Also records reuse:
 * Liability / Dead stock transferred back to Bulk (returns to Running) or issued
 * as sample. These are real transactions, not manual overrides. Store only.
 */
class MaterialStockLedgerController extends Controller
{
    public function index(Request $request)
    {
        $query = MaterialStockLedger::query();

        if ($buyer = $request->input('buyer')) {
            $query->where('buyer_name', $buyer);
        }
        if ($style = $request->input('style')) {
            $query->where('style_name', $style);
        }
        if ($search = $request->input('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('material_description', 'like', "%{$search}%")
                    ->orWhere('sap_code', 'like', "%{$search}%")
                    ->orWhere('po_no', 'like', "%{$search}%")
                    ->orWhere('material_color', 'like', "%{$search}%");
            });
        }

        $ledgers = $query->orderBy('buyer_name')
            ->orderBy('style_name')
            ->orderBy('po_no')
            ->paginate(30)
            ->withQueryString();

        $buyers = MaterialStockLedger::whereNotNull('buyer_name')->distinct()->orderBy('buyer_name')->pluck('buyer_name');
        $styles = MaterialStockLedger::whereNotNull('style_name')->distinct()->orderBy('style_name')->pluck('style_name');

        // Totals for the summary cards.
        $totals = [
            'running' => (float) MaterialStockLedger::sum('running_closing_qty'),
            'liability' => (float) MaterialStockLedger::sum('liability_closing_qty'),
            'dead' => (float) MaterialStockLedger::sum('dead_closing_qty'),
            'value' => (float) MaterialStockLedger::sum('total_value'),
        ];

        return view('store.material-stock.ledger', compact('ledgers', 'buyers', 'styles', 'totals'));
    }

    /**
     * Move qty OUT of Liability stock: transfer back to Bulk (reuse) and/or issue
     * as sample. Cannot exceed the current liability closing balance.
     */
    public function storeLiabilityMovement(Request $request, MaterialStockLedger $ledger)
    {
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
