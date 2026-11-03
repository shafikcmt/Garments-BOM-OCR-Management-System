<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Bank details on both vendor lists: Admin > Vendors (`suppliers`, used by
 * bookings / POs / payment requests) and the Store's General Stock suppliers.
 * Additive, all nullable: vendors already saved are untouched.
 */
return new class extends Migration
{
    private const TABLES = ['suppliers', 'general_stock_suppliers'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'bank_name')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->string('bank_name')->nullable();
                $t->string('bank_branch')->nullable();
                $t->string('bank_account_name')->nullable();
                $t->string('bank_account_number', 100)->nullable();
                $t->string('bank_routing_number', 100)->nullable();
                $t->string('bank_swift_code', 50)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'bank_name')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn([
                    'bank_name', 'bank_branch', 'bank_account_name',
                    'bank_account_number', 'bank_routing_number', 'bank_swift_code',
                ]));
            }
        }
    }
};
