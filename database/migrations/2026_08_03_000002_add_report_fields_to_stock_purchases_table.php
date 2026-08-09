<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// General Stock (module A) — the Excel "Purchase" sheet columns the table was
// missing: RV No, and a real link to the supplier master.
//
// supplier_name is deliberately kept. It stays the value that is displayed and
// exported, so historical rows (typed before the supplier master existed) still
// read correctly, and a supplier later renamed in the master does not silently
// rewrite what an old challan said. supplier_id is the new structured link.
//
// purchase_date already IS the Excel "Challan Date" — it is only relabelled in
// the UI, never renamed, so nothing that reads the column breaks.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_purchases', function (Blueprint $table) {
            $table->string('rv_no', 100)->nullable()->after('challan_no');

            $table->foreignId('supplier_id')->nullable()->after('supplier_name')
                ->constrained('suppliers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn('rv_no');
        });
    }
};
