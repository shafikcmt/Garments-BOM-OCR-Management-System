<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a decision row remember who the step had been POINTED AT when it was
 * taken — so the history can read "assigned to A, approved by B".
 *
 * A SNAPSHOT, TAKEN AT THE MOMENT OF THE DECISION. pra_stage_assignments holds
 * the live assignment and the creator may change it on resubmit; this column
 * freezes what it was when this particular row was written, so a later
 * reassignment cannot rewrite a cycle that has already closed.
 *
 * NULL means "this step was not specifically assigned" — the resolver routed it
 * to the whole eligible set and whoever acted, acted. That is every legacy
 * (flow-1) row, every departmental row written before this column existed, and
 * every future row for a step nobody pointed at. There is no backfill because
 * there is nothing true to backfill with.
 *
 * Nullable and unindexed: it is read only when rendering one PRA's history,
 * never searched. nullOnDelete — a decision stands even if the person it was
 * once assigned to is later removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pra_approvals', function (Blueprint $table) {
            $table->foreignId('assigned_user_id')
                ->nullable()
                ->after('approver_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pra_approvals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_user_id');
        });
    }
};
