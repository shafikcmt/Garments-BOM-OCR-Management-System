<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Roll / Bale count on the Receiving (MRR) sheet — packing information the
// delivery arrives with. There is no OCR/BOM column for it anywhere in the
// workbook, so it is recorded on the GRN itself.
//
// Purely additive + nullable, exactly like 2026_07_19_000001: no existing
// column is renamed or re-typed, and the stock ledger (which reads `qty` =
// Physical Rcv Qty) is untouched. Existing rows simply carry NULL.
//
// Stored as a string rather than a number because Store writes it both ways —
// "12" and "12 Roll" are both routine on the delivery challan.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_receivings', function (Blueprint $table) {
            $table->string('roll_bale', 100)->nullable()->after('invoice_qty');
        });
    }

    public function down(): void
    {
        Schema::table('material_receivings', function (Blueprint $table) {
            $table->dropColumn('roll_bale');
        });
    }
};
