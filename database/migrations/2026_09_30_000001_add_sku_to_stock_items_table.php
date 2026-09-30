<?php

use App\Support\StockSku;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// General Stock — an SKU on every item: category code + item initials + a
// 4-digit serial per category, no hyphens (STCPI0001). Nullable, and
// existing items are given one here in id order, so the serials follow the
// order the items were added.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->string('sku', 30)->nullable()->after('name');
        });

        $next = [];

        DB::table('stock_items')->orderBy('id')->select(['id', 'name', 'category'])
            ->each(function ($item) use (&$next) {
                $prefix = StockSku::categoryCode($item->category);
                $next[$prefix] = ($next[$prefix] ?? 0) + 1;

                DB::table('stock_items')->where('id', $item->id)->update([
                    'sku' => $prefix.StockSku::itemCode((string) $item->name).str_pad((string) $next[$prefix], 4, '0', STR_PAD_LEFT),
                ]);
            });

        Schema::table('stock_items', function (Blueprint $table) {
            $table->unique('sku');
        });
    }

    public function down(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropUnique(['sku']);
            $table->dropColumn('sku');
        });
    }
};
