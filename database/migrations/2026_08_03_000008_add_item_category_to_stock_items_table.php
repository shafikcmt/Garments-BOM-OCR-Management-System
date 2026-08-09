<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// General Stock (module A) — put the item master's Category on the same master
// list the Issue form already uses, so the two can no longer disagree.
//
// The existing free-text `category` column is KEPT and still written on every
// save, holding the selected master's name. That is deliberate: the Consumable
// Stock Report, its PDF/Excel exports, the report search and the category
// filter all read that column, and none of them need to change. The new
// item_category_id is the structured link; `category` is the readable snapshot.
//
// Additive and nullable. The backfill below links any item whose existing
// category text already matches a master entry, and creates the master entry
// when it does not, so nothing has to be re-typed by hand.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->foreignId('item_category_id')->nullable()->after('category')
                ->constrained('item_categories')->nullOnDelete();
        });

        $categories = DB::table('stock_items')
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->pluck('category');

        foreach ($categories as $name) {
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            // Match the master case-insensitively; create it if this category
            // only ever existed as free text on an item.
            $id = DB::table('item_categories')
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->whereNull('deleted_at')
                ->value('id');

            $id ??= DB::table('item_categories')->insertGetId([
                'name' => $name,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('stock_items')
                ->whereRaw('LOWER(category) = ?', [mb_strtolower($name)])
                ->update(['item_category_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_category_id');
        });
    }
};
