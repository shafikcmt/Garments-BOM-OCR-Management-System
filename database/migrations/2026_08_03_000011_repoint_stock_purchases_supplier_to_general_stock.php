<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// General Stock (module A) — sever the purchase record from the Buyer/Style
// supplier table and point it at the General Stock supplier list instead.
//
// `supplier_id` pointed at `suppliers`, which belongs to the Buyer/Style
// module. It is dropped rather than left in place: leaving a live foreign key
// into that table is exactly the coupling this change exists to remove, and
// a dead column invites someone to wire it back up later.
//
// Dropping is safe here because no purchase has ever carried a supplier_id —
// verified as 0 rows (and 0 purchases in total) before writing this. On any
// install that does hold data, check that before migrating.
//
// `supplier_name` is untouched and remains the value displayed and exported, so
// a supplier later renamed or deactivated cannot silently rewrite an old
// challan.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');

            $table->foreignId('general_stock_supplier_id')->nullable()->after('supplier_name')
                ->constrained('general_stock_suppliers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('general_stock_supplier_id');

            $table->foreignId('supplier_id')->nullable()->after('supplier_name')
                ->constrained('suppliers')->nullOnDelete();
        });
    }
};
