<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHO A DEPARTMENTAL PRA STEP WAS POINTED AT — the optional "primary assignee".
 *
 * When a Supply Chain creator raises a flow-2 PRA they may, per step, name one
 * specific person from that step's eligible actors. This table records that
 * choice. It is INFORMATIONAL: PraStageResolver still decides who may act, and
 * an assignment never narrows that set — the named person is highlighted and
 * notified personally, and any other eligible team member can still carry the
 * step. A step with no row here is routed exactly as it always was.
 *
 * WHY THIS IS NOT A PENDING ROW IN pra_approvals. That table is the audit log of
 * decisions TAKEN — a row there means somebody did something, and the
 * departmental flow was built on that invariant (see 2026_09_09_000002). An
 * assignment is an intention, not a decision: it exists before anyone acts and
 * it may be fulfilled by somebody else. Putting it in the log would mean a row
 * for an approval that never happened, which is the flow-1 shape the chain
 * deliberately left behind.
 *
 * ONE ROW PER (PRA, STEP), NOT PER CYCLE. An assignment belongs to the PRA and
 * survives a reject/resubmit; the creator may change it on resubmit. Which
 * cycle an assignment was honoured in is answered by the snapshot the decision
 * row takes (pra_approvals.assigned_user_id, migration 000002), not here.
 *
 * `sequence` uses the same 2..6 numbering as pra_approvals.sequence. Step 1 has
 * no row, for the same reason it has no approval row: the creator does not
 * assign themself.
 *
 * Flow-1 PRAs never get a row here and nothing on the legacy path reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pra_stage_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_request_id')->constrained('payment_requests')->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence');
            // The person pointed at. Cascade: a deleted user cannot remain the
            // named assignee of anything — the step simply reverts to automatic
            // routing, which is the resolver's behaviour for a missing row.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Who made the assignment. Kept for the trail; nulled rather than
            // cascaded so the assignment itself outlives its author's account.
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // A step is pointed at ONE person. Reassigning replaces, not adds.
            $table->unique(['payment_request_id', 'sequence'], 'pra_stage_assignments_unique_step');
            // "Which PRAs am I the primary assignee on" — for the queue badge.
            $table->index(['user_id', 'sequence'], 'pra_stage_assignments_user_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pra_stage_assignments');
    }
};
