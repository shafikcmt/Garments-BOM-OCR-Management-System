<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * "Other names" for a header, typed by Admin on Excel Header Control, so a
 * buyer who calls a column something new is matched on upload without a code
 * change. Additive and nullable; the built-in alias lists keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('excel_headers', 'aliases')) {
            Schema::table('excel_headers', function (Blueprint $table) {
                $table->text('aliases')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('excel_headers', 'aliases')) {
            Schema::table('excel_headers', fn (Blueprint $table) => $table->dropColumn('aliases'));
        }
    }
};
