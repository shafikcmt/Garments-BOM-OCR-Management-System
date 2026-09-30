<?php

use App\Support\StockSku;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// General Stock — an SKU on every item: category code + item initials + a
// 4-digit serial per category, no hyphens (STCPI0001).
//
// Safe to run where an `sku` column already exists (an earlier local
// attempt added one): the column and the unique index are only added when
// missing, an SKU already written with hyphens is rewritten without them,
// and only items with no SKU are given one — numbered after the highest
// serial their category already uses, in id order.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('stock_items', 'sku')) {
            Schema::table('stock_items', function (Blueprint $table) {
                $table->string('sku', 30)->nullable()->after('name');
            });
        }

        // Existing SKUs: no hyphens or spaces, upper case.
        DB::table('stock_items')->whereNotNull('sku')->where('sku', '<>', '')->select(['id', 'sku'])
            ->lazyById()->each(function ($item) {
                $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $item->sku));

                if ($clean !== $item->sku) {
                    DB::table('stock_items')->where('id', $item->id)->update(['sku' => $clean !== '' ? $clean : null]);
                }
            });

        // Highest serial each category code already uses.
        $next = [];
        foreach (DB::table('stock_items')->whereNotNull('sku')->pluck('sku') as $sku) {
            if (preg_match('/^([A-Z0-9]{2})[A-Z0-9]{3}(\d{4})$/', (string) $sku, $m)) {
                $next[$m[1]] = max($next[$m[1]] ?? 0, (int) $m[2]);
            }
        }

        DB::table('stock_items')->where(fn ($q) => $q->whereNull('sku')->orWhere('sku', ''))
            ->select(['id', 'name', 'category'])
            ->lazyById()->each(function ($item) use (&$next) {
                $prefix = StockSku::categoryCode($item->category);
                $next[$prefix] = ($next[$prefix] ?? 0) + 1;

                DB::table('stock_items')->where('id', $item->id)->update([
                    'sku' => $prefix.StockSku::itemCode((string) $item->name).str_pad((string) $next[$prefix], 4, '0', STR_PAD_LEFT),
                ]);
            });

        if (! Schema::hasIndex('stock_items', ['sku'], 'unique')) {
            Schema::table('stock_items', function (Blueprint $table) {
                $table->unique('sku');
            });
        }
    }

    public function down(): void
    {
        // Only undoes the unique index; the column may predate this migration.
        if (Schema::hasIndex('stock_items', ['sku'], 'unique')) {
            Schema::table('stock_items', function (Blueprint $table) {
                $table->dropUnique(['sku']);
            });
        }
    }
};
