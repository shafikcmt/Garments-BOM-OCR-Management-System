<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives a pra_approvals row a POSITION and a ROUTE, which is the one thing the
 * table has never had.
 *
 *   sequence     where in the chain this decision sits.
 *                  0  legacy row — the flow-1 Check/Approve pair, which has no
 *                     position because flow 1 orders itself by `stage` alone.
 *                  2  Merchant Team    check
 *                  3  Merchant Head    approve
 *                  4  Accounts Team    check
 *                  5  Accounts Head    approve
 *                  6  Commercial Head  complete
 *                (1 is the creator's own step. It is deliberately NOT stored
 *                 here — the creator is already recorded on payment_requests as
 *                 created_by / prepared_signature_path, and inventing an
 *                 approval row for someone who approved nothing would put a
 *                 decision in the audit log that never happened.)
 *
 *   actor_kind   'team' or 'head' — which tier of the department acted. The
 *                distinction the resolver is built on, stored so the audit trail
 *                still reads correctly after someone is promoted or demoted.
 *
 *   department   a DepartmentRoles KEY: 'merchandising', 'accounts',
 *                'commercial'. THE KEY, NOT THE ROLE NAME — 'accounts' the key
 *                vs 'account' the role is the same trap already pinned in
 *                BuyerScope::SCOPED_DEPARTMENTS, and the wrong one matches
 *                nothing silently.
 *
 * All three are additive and no existing column is modified. Legacy rows take
 * sequence 0 and NULL for the other two, which is what marks them as flow-1
 * rows for anything that looks.
 *
 * WHY SEQUENCE DEFAULTS TO 0 AND NOT NULL. It joins the unique key. A NULL there
 * would make every legacy row's key NULL-bearing, and both MySQL and PostgreSQL
 * treat NULLs in a unique index as distinct — which would silently DROP the
 * duplicate protection those 6 live rows have today. 0 keeps them constrained
 * exactly as they are now.
 *
 * The unique key gains `sequence` because one person may legitimately act twice
 * in one cycle at the same stage: a user who is a member of both Merchant and
 * Accounts would hold a 'check' row at step 2 and another at step 4, which the
 * old four-column key would reject.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pra_approvals', function (Blueprint $table) {
            $table->unsignedTinyInteger('sequence')->default(0)->after('cycle');
            $table->string('actor_kind', 20)->nullable()->after('sequence');
            $table->string('department', 50)->nullable()->after('actor_kind');

            $table->dropUnique('pra_approvals_unique_cycle_stage');
            $table->unique(
                ['payment_request_id', 'approver_id', 'cycle', 'stage', 'sequence'],
                'pra_approvals_unique_cycle_stage_sequence'
            );

            // "What happened at step N of this PRA's current cycle" — the read
            // the version-2 state machine and the PDF both make.
            $table->index(['payment_request_id', 'cycle', 'sequence'], 'pra_approvals_step_index');
        });
    }

    public function down(): void
    {
        Schema::table('pra_approvals', function (Blueprint $table) {
            $table->dropIndex('pra_approvals_step_index');

            $table->dropUnique('pra_approvals_unique_cycle_stage_sequence');
            $table->unique(
                ['payment_request_id', 'approver_id', 'cycle', 'stage'],
                'pra_approvals_unique_cycle_stage'
            );

            $table->dropColumn(['sequence', 'actor_kind', 'department']);
        });
    }
};
