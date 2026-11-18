<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An optional comment per supplier on a Comparative Statement (the "N.B."
 * line printed above the supplier's details). Additive, nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('gs_cs_suppliers', 'comment')) {
            Schema::table('gs_cs_suppliers', function (Blueprint $table) {
                $table->text('comment')->nullable()->after('terms');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('gs_cs_suppliers', 'comment')) {
            Schema::table('gs_cs_suppliers', fn (Blueprint $table) => $table->dropColumn('comment'));
        }
    }
};
