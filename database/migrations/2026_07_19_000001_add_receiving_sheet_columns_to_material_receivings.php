<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Align material_receivings with the Store/Commercial master Receiving sheet.
// Purely additive + nullable: no existing column is renamed or re-typed, so old
// rows and the stock ledger (which reads `qty` = Physical Rcv Qty) keep working.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_receivings', function (Blueprint $table) {
            // Identity columns copied from the Booking PO / BOM row (auto-filled).
            $table->string('supplier_name')->nullable()->after('season_name');
            $table->string('material_name')->nullable()->after('style_name');
            $table->string('gmts_color_name')->nullable()->after('material_description');
            $table->string('art_no')->nullable()->after('gmts_color_name');

            // Sheet quantity columns. `qty` (existing) stays the Physical Rcv Qty
            // that drives the ledger — these are recorded alongside it.
            $table->decimal('invoice_qty', 18, 4)->nullable()->after('qty');
            $table->decimal('internal_po_qty', 18, 4)->nullable()->after('invoice_qty');
            // Always server-computed as invoice_qty * unit_price.
            $table->decimal('invoice_value', 18, 4)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('material_receivings', function (Blueprint $table) {
            $table->dropColumn([
                'supplier_name',
                'material_name',
                'gmts_color_name',
                'art_no',
                'invoice_qty',
                'internal_po_qty',
                'invoice_value',
            ]);
        });
    }
};
