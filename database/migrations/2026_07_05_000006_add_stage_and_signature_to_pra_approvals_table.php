<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the PRA approval rows for the sequential Check -> Approve flow:
 *  - `stage`          : 'check' or 'approve' (defaults to 'approve' so every
 *                        pre-existing row keeps its original approver meaning).
 *  - `signature_path` : snapshot of the acting user's personal signature at the
 *                        moment they checked / approved, so the PDF stays
 *                        correct even if they later change their signature.
 *
 * The uniqueness guard is widened to include `stage` because one user may be
 * both the checker and an approver on the same PRA cycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pra_approvals', function (Blueprint $table) {
            $table->string('stage')->default('approve')->after('cycle'); // check | approve
            $table->string('signature_path')->nullable()->after('comment');

            $table->dropUnique('pra_approvals_unique_cycle');
            $table->unique(['payment_request_id', 'approver_id', 'cycle', 'stage'], 'pra_approvals_unique_cycle_stage');
        });
    }

    public function down(): void
    {
        Schema::table('pra_approvals', function (Blueprint $table) {
            $table->dropUnique('pra_approvals_unique_cycle_stage');
            $table->unique(['payment_request_id', 'approver_id', 'cycle'], 'pra_approvals_unique_cycle');

            $table->dropColumn(['stage', 'signature_path']);
        });
    }
};
