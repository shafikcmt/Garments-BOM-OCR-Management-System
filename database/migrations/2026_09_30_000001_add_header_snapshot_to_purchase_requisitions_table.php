<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The document header (unit, section, user, contact, Store Personnel) as it
 * was at the last submit, so a printed requisition never changes when a
 * user's details do. See App\Services\RequisitionHeaderResolver.
 *
 * Additive and nullable, no backfill: a sheet without a snapshot prints
 * from its own columns, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->json('header_snapshot')->nullable()->after('remarks');
            $table->timestamp('header_snapshot_at')->nullable()->after('header_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->dropColumn(['header_snapshot', 'header_snapshot_at']);
        });
    }
};
