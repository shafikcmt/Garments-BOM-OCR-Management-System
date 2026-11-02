<?php

use App\Support\UomOptions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One name per unit of measure. Where the same unit is written several ways
 * ("Pcs", "pcs." and "Pieces"; "Kg" and "KGS"), every spelling becomes the one
 * most Item Master rows already use (UomOptions::resolver()), and the others
 * are removed — from the Item Master and from requisition lines' unit snapshot —
 * so the unit list and the Stock Report's Stock by Unit show each unit once.
 * A unit written one way only, or not in the list, is left as it is. Data only.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Decided once from the Item Master as it is now, then applied to both tables.
        $one = UomOptions::resolver();

        foreach ([['stock_items', 'uom'], ['purchase_requisition_items', 'uom_snapshot']] as [$table, $column]) {
            foreach (DB::table($table)->whereNotNull($column)->where($column, '<>', '')->distinct()->pluck($column) as $old) {
                $new = $one($old);

                if ($new !== null && $new !== $old) {
                    DB::table($table)->where($column, $old)->update([$column => $new]);
                }
            }
        }
    }

    public function down(): void
    {
        // The old spellings are not kept; nothing to restore.
    }
};
