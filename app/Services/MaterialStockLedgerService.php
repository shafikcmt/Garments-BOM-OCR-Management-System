<?php

namespace App\Services;

use App\Models\MaterialBulkIssue;
use App\Models\MaterialDeadMovement;
use App\Models\MaterialLiabilityMovement;
use App\Models\MaterialReceiving;
use App\Models\MaterialStockLedger;
use Illuminate\Database\Eloquent\Model;

// Recalculates the cached material_stock_ledgers row(s) from the underlying
// event tables. A unique ledger key is (excel_row_id, size). Called from the
// TriggersMaterialStockLedger trait after any event row is saved/deleted.
class MaterialStockLedgerService
{
    /**
     * Recalculate the ledger row for the key carried by an event model.
     */
    public function recalculateFor(Model $model): void
    {
        $this->recalculateKey((int) $model->excel_row_id, $model->size);
    }

    /**
     * Recalculate (create/update/delete) the cached ledger row for one key.
     */
    public function recalculateKey(?int $excelRowId, ?string $size): void
    {
        if (! $excelRowId) {
            return; // BOM-linked ledger only; unlinked events are ignored here.
        }

        $receivings = MaterialReceiving::where('excel_row_id', $excelRowId)
            ->where('size', $size)->get();
        $bulkIssues = MaterialBulkIssue::where('excel_row_id', $excelRowId)
            ->where('size', $size)->get();
        $liabilityMoves = MaterialLiabilityMovement::where('excel_row_id', $excelRowId)
            ->where('size', $size)->get();
        $deadMoves = MaterialDeadMovement::where('excel_row_id', $excelRowId)
            ->where('size', $size)->get();

        // No events left for this key -> remove the cached row.
        if ($receivings->isEmpty() && $bulkIssues->isEmpty()
            && $liabilityMoves->isEmpty() && $deadMoves->isEmpty()) {
            MaterialStockLedger::where('excel_row_id', $excelRowId)
                ->where('size', $size)->delete();

            return;
        }

        // --- Receive side ---
        $bookingReceive = (float) $receivings
            ->where('source_type', MaterialReceiving::SOURCE_BOOKING)->sum('qty');
        $internalReceive = (float) $receivings
            ->where('source_type', MaterialReceiving::SOURCE_INTERNAL_PO)->sum('qty');
        $totalReceive = (float) $receivings->sum('qty');

        // --- Bulk-issue split ---
        $bulkIssue = (float) $bulkIssues->sum('bulk_qty');
        $sampleFromBulk = (float) $bulkIssues->sum('sample_qty');
        $declaredLiability = (float) $bulkIssues->sum('liability_qty');
        $calculatedDead = (float) $bulkIssues->sum('dead_qty');

        // --- Reuse / sample movements out of Liability & Dead ---
        $liabilityToBulk = (float) $liabilityMoves->sum('transfer_to_bulk_qty');
        $liabilitySample = (float) $liabilityMoves->sum('sample_issue_qty');
        $deadToBulk = (float) $deadMoves->sum('transfer_to_bulk_qty');
        $deadSample = (float) $deadMoves->sum('sample_issue_qty');

        // --- Closings ---
        // NOTE: transfer-to-bulk qty is added back into Running because reused
        // material physically returns to the free/available production pool. This
        // keeps Total Closing = physical stock on hand (conservation). See the
        // flag raised to the owner — confirm before this feeds a live report.
        $runningClosing = $totalReceive
            - $bulkIssue - $sampleFromBulk - $declaredLiability - $calculatedDead
            + $liabilityToBulk + $deadToBulk;

        $liabilityClosing = $declaredLiability - $liabilityToBulk - $liabilitySample;
        $deadClosing = $calculatedDead - $deadToBulk - $deadSample;
        $totalClosing = $runningClosing + $liabilityClosing + $deadClosing;

        // --- Valuation: weighted average of received unit prices ---
        $valuedQty = 0.0;
        $valuedAmount = 0.0;
        foreach ($receivings as $r) {
            if ($r->unit_price !== null) {
                $valuedQty += (float) $r->qty;
                $valuedAmount += (float) $r->qty * (float) $r->unit_price;
            }
        }
        $avgUnitPrice = $valuedQty > 0 ? $valuedAmount / $valuedQty : null;
        $totalValue = $avgUnitPrice !== null ? $totalClosing * $avgUnitPrice : 0;

        // Denormalized identity: prefer a receiving row, else any available event.
        $identitySource = $receivings->first()
            ?? $bulkIssues->first()
            ?? $liabilityMoves->first()
            ?? $deadMoves->first();

        MaterialStockLedger::updateOrCreate(
            ['excel_row_id' => $excelRowId, 'size' => $size],
            [
                'excel_file_id' => $identitySource->excel_file_id,
                'booking_po_id' => $identitySource->booking_po_id,
                'po_no' => $identitySource->po_no,
                'buyer_name' => $identitySource->buyer_name,
                'season_name' => $identitySource->season_name,
                'style_name' => $identitySource->style_name,
                'material_description' => $identitySource->material_description,
                'sap_code' => $identitySource->sap_code,
                'material_color' => $identitySource->material_color,
                'uom' => $identitySource->uom,

                'booking_receive_qty' => $bookingReceive,
                'internal_po_receive_qty' => $internalReceive,
                'total_receive_qty' => $totalReceive,

                'bulk_issue_qty' => $bulkIssue,
                'sample_qty' => $sampleFromBulk,
                'declared_liability_qty' => $declaredLiability,
                'calculated_dead_qty' => $calculatedDead,

                'liability_to_bulk_qty' => $liabilityToBulk,
                'liability_sample_qty' => $liabilitySample,
                'dead_to_bulk_qty' => $deadToBulk,
                'dead_sample_qty' => $deadSample,

                'running_closing_qty' => $runningClosing,
                'liability_closing_qty' => $liabilityClosing,
                'dead_closing_qty' => $deadClosing,
                'total_closing_qty' => $totalClosing,

                'avg_unit_price' => $avgUnitPrice,
                'total_value' => $totalValue,

                'recalculated_at' => now(),
            ]
        );
    }
}
