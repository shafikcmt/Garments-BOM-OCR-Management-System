<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// GRN Date — the date the goods receipt note itself is booked, which is not
// always the date the material physically arrived (`receive_date`).
//
// Additive and nullable, so existing rows stay valid. `receive_date` keeps every
// job it already had: the stock ledger, the Store dashboard trend, the report
// date filter and the year segment of the generated GRN No all still read it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_receivings', function (Blueprint $table) {
            $table->date('grn_date')->nullable()->after('receive_date');
        });

        // Rows written before this column existed were booked on the day they
        // were received, so that is the truthful backfill.
        DB::table('material_receivings')
            ->whereNull('grn_date')
            ->update(['grn_date' => DB::raw('receive_date')]);
    }

    public function down(): void
    {
        Schema::table('material_receivings', function (Blueprint $table) {
            $table->dropColumn('grn_date');
        });
    }
};
