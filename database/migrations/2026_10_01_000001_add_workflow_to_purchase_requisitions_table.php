<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which approval flow a requisition is on, and the stage it waits at.
 *
 *   workflow_version  NULL = the old flow (every sheet submitted before the
 *                     stage engine), finished exactly as before. 1 = the
 *                     stage engine (RequisitionWorkflow).
 *   current_stage     the sequence of the stage being waited on; NULL when
 *                     the sheet is on the old flow or has no stage left.
 *
 * Additive, nullable, no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->unsignedTinyInteger('workflow_version')->nullable()->after('status');
            $table->unsignedTinyInteger('current_stage')->nullable()->after('workflow_version');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->dropColumn(['workflow_version', 'current_stage']);
        });
    }
};
