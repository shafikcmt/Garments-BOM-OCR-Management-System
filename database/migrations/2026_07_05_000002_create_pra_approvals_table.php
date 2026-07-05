<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per approver selected for a PRA within an approval cycle. Holds the
 * approver's decision (pending / approved / rejected), an optional comment and
 * the time they acted. A "resubmit" starts a new cycle: old rows stay for the
 * audit trail and the current status is derived from the highest cycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pra_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_request_id')->constrained('payment_requests')->cascadeOnDelete();
            $table->foreignId('approver_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('cycle')->default(1);
            $table->string('status')->default('pending'); // pending | approved | rejected
            $table->text('comment')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();

            $table->unique(['payment_request_id', 'approver_id', 'cycle'], 'pra_approvals_unique_cycle');
            $table->index(['approver_id', 'status']);
            $table->index(['payment_request_id', 'cycle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pra_approvals');
    }
};
