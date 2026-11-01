<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "To Be Procured" now adds Safety Stock (owner, 2026-11-01):
 * Qty − Stock in Hand + Safety Stock, rounded to a whole figure.
 *
 * Requisitions still on their way to approval (draft, submitted, store
 * reviewed) are recalculated from their own snapshots so they show the new
 * figure. Approved and later ones are left exactly as they were approved.
 * Data only; no column changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $open = ['draft', 'submitted', 'store_reviewed'];

        DB::table('purchase_requisition_items as i')
            ->join('purchase_requisitions as r', 'r.id', '=', 'i.purchase_requisition_id')
            ->whereIn('r.status', $open)
            ->whereNotNull('i.stock_in_hand_snapshot')
            ->select('i.id', 'i.qty_requested', 'i.stock_in_hand_snapshot', 'i.safety_stock_snapshot', 'i.rate_appx')
            ->orderBy('i.id')
            ->chunk(500, function ($lines) {
                foreach ($lines as $line) {
                    $needed = (float) $line->qty_requested - (float) $line->stock_in_hand_snapshot + round((float) ($line->safety_stock_snapshot ?? 0));
                    $toBeProcured = max(0.0, round($needed));

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
