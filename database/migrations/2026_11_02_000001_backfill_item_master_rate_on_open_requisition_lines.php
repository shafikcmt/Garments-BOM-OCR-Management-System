<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Last Purchase Rate and Date fall back to the Item Master's standard price
 * and reference date when an item has no purchase on record. Requisition
 * lines still before approval (draft, submitted, store reviewed) that were
 * saved with those blank are filled the same way, and Amount is recalculated
 * where a rate now exists. Approved and later requisitions are not touched.
 * Data only; no column changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('purchase_requisition_items as i')
            ->join('purchase_requisitions as r', 'r.id', '=', 'i.purchase_requisition_id')
            ->join('stock_items as s', 's.id', '=', 'i.stock_item_id')
            ->whereIn('r.status', ['draft', 'submitted', 'store_reviewed'])
            ->whereNull('i.last_purchase_rate_snapshot')
            ->whereNotNull('s.unit_price')
            ->select('i.id', 'i.rate_appx', 'i.to_be_procured_qty', 's.unit_price', 's.last_addition_reference')
            ->orderBy('i.id')
            ->chunk(500, function ($lines) {
                foreach ($lines as $line) {
                    $rate = $line->rate_appx !== null ? (float) $line->rate_appx : (float) $line->unit_price;

                    DB::table('purchase_requisition_items')->where('id', $line->id)->update([
                        'last_purchase_rate_snapshot' => $line->unit_price,
                        'last_purchase_date_snapshot' => $line->last_addition_reference,
                        'rate_appx' => $rate,
                        'amount' => (float) $line->to_be_procured_qty * $rate,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Figures filled from the Item Master; nothing to undo.
    }
};
