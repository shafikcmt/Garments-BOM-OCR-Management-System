<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Safety Stock is not added to To Be Procured on an Individual requisition
 * (owner, 2026-11-03): Qty - Stock in Hand only, as a round figure.
 *
 * Individual requisitions still on their way to approval are recalculated from
 * their own snapshots. Approved and later ones are left as they were approved.
 * Data only; no column changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('purchase_requisition_items as i')
            ->join('purchase_requisitions as r', 'r.id', '=', 'i.purchase_requisition_id')
            ->where('r.requisition_type', 'individual')
            ->whereIn('r.status', ['draft', 'submitted', 'store_reviewed'])
            ->whereNotNull('i.stock_in_hand_snapshot')
            ->select('i.id', 'i.qty_requested', 'i.stock_in_hand_snapshot', 'i.rate_appx')
            ->orderBy('i.id')
            ->chunk(500, function ($lines) {
                foreach ($lines as $line) {
                    $toBeProcured = max(0.0, round((float) $line->qty_requested - (float) $line->stock_in_hand_snapshot));

                    DB::table('purchase_requisition_items')->where('id', $line->id)->update([
                        'to_be_procured_qty' => $toBeProcured,
                        'amount' => $line->rate_appx !== null ? $toBeProcured * (float) $line->rate_appx : 0,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Figures recalculated from snapshots; nothing to undo.
    }
};
