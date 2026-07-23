<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// GMTS Colour Name on the cached Closing Stock ledger.
//
// The ledger already denormalizes its identity columns (buyer, season, style,
// SAP code, material colour) from whichever event row it was built from, but
// GMTS colour — the garment's colour, kept separate from the material's own —
// was never among them. Both source tables carry it, so the Closing Stock and
// Store Reports screens could not offer it as a filter while the ledger could
// not answer on it.
//
// Purely additive and nullable. No stock figure is touched: this is an identity
// column, not a quantity, and the Running/Liability/Dead/Total maths does not
// read it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_stock_ledgers', function (Blueprint $table) {
            $table->string('gmts_color_name')->nullable()->after('material_color');
            // The Closing Stock filter bar queries on this directly.
            $table->index('gmts_color_name');
        });

        // Backfill from the receiving that produced each ledger row, matched on
        // the ledger's own key (excel_row_id, size). Rows written before this
        // column existed keep their figures and simply gain the label.
        //
        // Done in PHP rather than one UPDATE...JOIN so the statement stays
        // portable across MySQL and SQLite (the test database).
        DB::table('material_receivings')
            ->whereNotNull('excel_row_id')
            ->whereNotNull('gmts_color_name')
            ->select('excel_row_id', 'size', 'gmts_color_name')
            ->orderBy('id')
            ->chunk(500, function ($receivings) {
                foreach ($receivings as $receiving) {
                    DB::table('material_stock_ledgers')
                        ->where('excel_row_id', $receiving->excel_row_id)
                        ->when(
                            $receiving->size === null,
                            fn ($q) => $q->whereNull('size'),
                            fn ($q) => $q->where('size', $receiving->size)
                        )
                        ->whereNull('gmts_color_name')
                        ->update(['gmts_color_name' => $receiving->gmts_color_name]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('material_stock_ledgers', function (Blueprint $table) {
            $table->dropIndex(['gmts_color_name']);
            $table->dropColumn('gmts_color_name');
        });
    }
};
