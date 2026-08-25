<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reference dates for Last Addition and Last Consumption on the Stock Report.
 *
 * WHY THESE EXIST AT ALL. Both dates on that report are DERIVED, not stored:
 * GeneralStockReportService reads MAX(purchase_date) from stock_purchases and
 * MAX(issue_date) from stock_issues on every view. An item carried over from
 * the old Excel sheets has no such transaction here, so both columns print
 * blank however much history the store actually has for it.
 *
 * There is no way to fill that in by writing to the report's own columns —
 * they are recomputed every time — and the only alternative, inserting a
 * backdated purchase or issue to make the MAX come out right, would put a
 * fabricated movement into transaction history that closing stock, the ledger
 * and every downstream figure would then count. That must never happen, so
 * these two columns hold the historical date SEPARATELY instead.
 *
 * THEY ARE A FALLBACK, NEVER AN OVERRIDE. The report shows a real
 * transaction-derived date whenever one exists and falls back to these only
 * when none does, marked on screen so a reference date is never read as
 * evidence of a movement. That is the same shape stock_items.unit_price
 * already has against the last challan price.
 *
 * Nullable and additive: every existing row keeps working untouched, and an
 * item with no reference date behaves exactly as it does today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->date('last_addition_reference')->nullable()->after('unit_price');
            $table->date('last_consumption_reference')->nullable()->after('last_addition_reference');
        });
    }

    public function down(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropColumn(['last_addition_reference', 'last_consumption_reference']);
        });
    }
};
