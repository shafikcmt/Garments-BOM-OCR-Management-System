<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// General Stock (module A) — RCV Date on the Purchase (goods receiving) record.
//
// The reference Purchase sheet carries Challan Date and RCV Date as two
// separate columns: the challan is dated by the supplier, the RCV date is when
// the goods actually reached the store, and they routinely differ.
//
// `purchase_date` remains the Challan Date and is untouched. The Consumable
// Stock Report also keeps keying off it — "Date of Last Addition" and the
// monthly Addition totals follow the reference workbook, which reads the challan
// date column.
//
// Nullable in the database so any row entered before this column existed stays
// valid; required on the form from here on.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_purchases', function (Blueprint $table) {
            $table->date('rcv_date')->nullable()->after('purchase_date');
        });
    }

    public function down(): void
    {
        Schema::table('stock_purchases', function (Blueprint $table) {
            $table->dropColumn('rcv_date');
        });
    }
};
