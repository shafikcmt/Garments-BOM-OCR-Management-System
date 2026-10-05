<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Item SKU — the 2-letter category code the SKU starts with ({CAT:2}).
 *
 * Admin-editable on Admin > Item SKU. Not unique: categories that are the
 * same kind of item share a code on purpose (Needle + Machine Needles = ND),
 * and the settings screen warns about every other clash.
 *
 * Filled once here with the codes the owner approved on 2026-09-29, matched
 * by category name; any other category gets the first two letters of its
 * name. An inactive, deleted or "ZZ…" placeholder category with no items
 * gets none. Nothing
 * else is touched, and a code never rewrites an SKU already given.
 *
 * Additive; plain column type; named index — the same on MySQL, PostgreSQL
 * and SQLite.
 */
return new class extends Migration
{
    /** Approved codes by category name (case-insensitive). */
    private const CODES = [
        'stationery & printing' => 'ST',
        'electrical components' => 'EL', 'electrical' => 'EL', 'electric' => 'EL',
        'cleaning & hygiene' => 'CL', 'cleaning' => 'CL',
        'it & accessories' => 'IT', 'it' => 'IT',
        'press' => 'PS',
        'production' => 'PC', 'production consumables' => 'PC',
        'needle' => 'ND', 'machine needles' => 'ND',
        'mechanical parts & tools' => 'MP',
        'maintenance' => 'MT',
        'medicine' => 'MD', 'madeicne' => 'MD',
    ];

    public function up(): void
    {
        Schema::table('item_categories', function (Blueprint $table) {
            $table->string('code', 10)->nullable()->after('name');
            $table->index('code', 'item_categories_code_idx');
        });

        $withItems = DB::table('stock_items')->whereNotNull('item_category_id')
            ->distinct()->pluck('item_category_id')->map(fn ($id) => (int) $id)->all();

        foreach (DB::table('item_categories')->get(['id', 'name', 'is_active', 'deleted_at']) as $category) {
            $hasItems = in_array((int) $category->id, $withItems, true);

            // Inactive, deleted, or a "ZZ…" placeholder, with nothing in it
            // (ZZProduction, ZZStationery): no code (owner, 2026-09-29).
            $placeholder = str_starts_with(strtoupper(trim((string) $category->name)), 'ZZ');
            if (! $hasItems && ($category->deleted_at !== null || ! $category->is_active || $placeholder)) {
                continue;
            }

            $name = mb_strtolower(trim((string) $category->name));
            $code = self::CODES[$name] ?? substr(strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $category->name)), 0, 2);

            if ($code !== '') {
                DB::table('item_categories')->where('id', $category->id)->update(['code' => str_pad($code, 2, 'X')]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('item_categories', function (Blueprint $table) {
            $table->dropIndex('item_categories_code_idx');
            $table->dropColumn('code');
        });
    }
};
