<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A standard price per unit on the item itself.
 *
 * The Stock Report's Value column already has a source and keeps it: the price
 * on the most recent challan for the item, which is what the Excel it replaced
 * used. This column does NOT feed that calculation — nothing reads it yet.
 *
 * It exists because that source is empty for almost every item. Of 1,240 items,
 * five have ever been received with a price on the challan, so the other 1,235
 * show "—" where a value should be. A standard price on the master is what a
 * later change can fall back to when an item has no purchase history, leaving
 * every figure the report produces today exactly as it is.
 *
 * Nullable and additive, matching stock_purchases.unit_price in shape (18,4) so
 * a price copied between the two cannot lose precision or overflow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->decimal('unit_price', 18, 4)->nullable()->after('opening_as_on');
        });
    }

    public function down(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropColumn('unit_price');
        });
    }
};
