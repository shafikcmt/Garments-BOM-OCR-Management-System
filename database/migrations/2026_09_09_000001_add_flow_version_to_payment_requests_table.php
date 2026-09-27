<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE ISOLATION SWITCH for the 6-step departmental PRA chain.
 *
 * `flow_version` is what keeps every PRA that already exists out of the new
 * state machine entirely:
 *
 *   1  the legacy optional Check -> Approve flow. Reviewers are hand-picked by
 *      the creator from the flat pra_approvers pool. This is what all 19
 *      existing PRAs are, and their behaviour must not change by one character.
 *   2  the departmental chain: Supply Chain creates -> Merchant Team checks ->
 *      Merchant Head approves -> Accounts Team checks -> Accounts Head approves
 *      -> Commercial Head completes.
 *
 * DEFAULT IS 1, DELIBERATELY. Adding the column stamps every existing row as
 * legacy on both MySQL and PostgreSQL without a separate UPDATE, so there is no
 * backfill window in which a live PRA is briefly a version-2 record. New PRAs
 * are switched to 2 in a later phase, not here — after this migration the
 * application still writes and reads exactly what it did before.
 *
 * `current_sequence` is the position of the step a version-2 PRA is waiting on
 * (2..6; see the step table on PraApproval). NULL for every legacy PRA, and
 * NULL for a version-2 PRA that has finished or been rejected. Legacy PRAs
 * derive their state from pending pra_approvals rows exactly as they always
 * have and never read this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->unsignedTinyInteger('flow_version')->default(1)->after('status');
            $table->unsignedTinyInteger('current_sequence')->nullable()->after('flow_version');

            // The approver queue asks "which version-2 PRAs are sitting on step
            // N right now" on every page load; this is that question's index.
            $table->index(['flow_version', 'status', 'current_sequence'], 'payment_requests_flow_state_index');
        });
    }

    public function down(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->dropIndex('payment_requests_flow_state_index');
            $table->dropColumn(['flow_version', 'current_sequence']);
        });
    }
};
