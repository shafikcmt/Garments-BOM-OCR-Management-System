<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// General Stock (module A) — the last item-master columns the Excel
// "Stock <Month>" sheet carries that the table did not have yet.
//
// opening_qty / opening_as_on are the migration seed: the Excel starts each
// item from a counted opening balance, not from an empty warehouse. Without it
// the ledger's first month would open at 0 and every closing balance after it
// would be short by the stock that was already on the shelf on day one.
//
// Additive and nullable throughout — existing rows keep working untouched.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            // "Register Page No" — the physical store register page, printed on
            // the report so store staff can tie a row back to the paper book.
            $table->string('register_page_no', 50)->nullable()->after('category');

            // Counted opening balance and the date it was counted on.
            $table->decimal('opening_qty', 18, 4)->nullable()->after('register_page_no');
            $table->date('opening_as_on')->nullable()->after('opening_qty');
        });
    }

    public function down(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropColumn(['register_page_no', 'opening_qty', 'opening_as_on']);
        });
    }
};
