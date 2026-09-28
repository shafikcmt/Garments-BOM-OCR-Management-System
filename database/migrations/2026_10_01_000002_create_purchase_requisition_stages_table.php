<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A requisition's approval plan: one row per stage per cycle, written at
 * submit from the Requisition Signatures settings, with the approver
 * resolved then. Settings changed later do not reroute a sheet already in
 * progress. A resubmission starts the next cycle; older cycles stay.
 *
 * Names are copied (assignee_name, acted_by_name) so the plan still reads
 * after a user is removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requisition_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_requisition_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('cycle')->default(1);
            $table->unsignedTinyInteger('sequence');
            $table->string('label', 80);
            $table->string('title', 80)->nullable();
            $table->string('stamp_source', 30)->nullable();
            $table->string('assignee_type', 30);
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assignee_name')->nullable();
            // pending | approved | skipped
            $table->string('status', 20)->default('pending');
            $table->foreignId('acted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('acted_by_name')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['purchase_requisition_id', 'cycle', 'sequence'], 'pr_stages_unique_cycle_sequence');
            $table->index(['assignee_user_id', 'status'], 'pr_stages_assignee_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requisition_stages');
    }
};
