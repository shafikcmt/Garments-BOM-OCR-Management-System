<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Vendor ID on PI / payment requests and receivings. Additive and nullable: the
 * supplier_name text on each row stays as the historical value. Existing rows are
 * linked only where exactly ONE vendor has that name (never a guess).
 */
return new class extends Migration
{
    private const TABLES = ['payment_requests', 'payment_request_items', 'material_receivings'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'supplier_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('supplier_id')->nullable()->index());
            }
        }

        $unique = DB::table('suppliers')->whereNull('deleted_at')->get(['id', 'supplier_name'])
            ->groupBy(fn ($s) => mb_strtolower(trim($s->supplier_name)))->filter(fn ($g) => $g->count() === 1);

        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'supplier_id') || ! Schema::hasColumn($table, 'supplier_name')) {
                continue;
            }

            DB::table($table)->whereNull('supplier_id')->whereNotNull('supplier_name')->select('supplier_name')->distinct()->pluck('supplier_name')
                ->each(function ($name) use ($table, $unique) {
                    if ($match = $unique->get(mb_strtolower(trim($name)))) {
                        DB::table($table)->where('supplier_name', $name)->whereNull('supplier_id')->update(['supplier_id' => $match->first()->id]);
                    }
                });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'supplier_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('supplier_id'));
            }
        }
    }
};
