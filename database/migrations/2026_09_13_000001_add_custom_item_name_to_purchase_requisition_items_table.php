<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a requisition line name an item that is not in the Item Master yet.
 *
 * A department asking for something the store has never bought had nowhere to
 * put it: the item picker only offers stock_items, so the request was either
 * mis-filed against a near-enough item or never raised at all.
 *
 * Two additive changes, both safe against live data:
 *
 *   custom_item_name  the typed name, used only when stock_item_id is null.
 *   stock_item_id     becomes NULLABLE. Every existing row has one, so nothing
 *                     changes for any requisition already written.
 *
 * Deliberately NOT a foreign key and deliberately NOT auto-creating a
 * stock_items row: adding an item to the master is a Store decision with a
 * category, UOM and re-order level behind it, and a requisition line is not
 * enough information to make it. Store adds it by hand afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requisition_items', function (Blueprint $table) {
            $table->string('custom_item_name', 255)->nullable()->after('stock_item_id');
        });

        // Separated from the column add above: changing an existing column needs
        // doctrine/dbal on some stacks, and keeping it in its own closure means
        // the new column still lands if this half has to be looked at.
        Schema::table('purchase_requisition_items', function (Blueprint $table) {
            $table->unsignedBigInteger('stock_item_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // The rows that only a custom name identifies cannot survive a rollback
        // — there is no stock_item_id to fall back on — so they go first,
        // rather than the NOT NULL below failing halfway.
        \Illuminate\Support\Facades\DB::table('purchase_requisition_items')
            ->whereNull('stock_item_id')
            ->delete();

        Schema::table('purchase_requisition_items', function (Blueprint $table) {
            $table->unsignedBigInteger('stock_item_id')->nullable(false)->change();
        });

        Schema::table('purchase_requisition_items', function (Blueprint $table) {
            $table->dropColumn('custom_item_name');
        });
    }
};
