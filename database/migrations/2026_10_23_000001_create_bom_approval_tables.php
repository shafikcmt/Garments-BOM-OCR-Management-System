<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * BOM approval: a style's BOM is submitted, checked and approved (one or two
 * levels) before Supply Chain orders against it. Additive; nothing existing is
 * altered, and nothing is blocked until an admin switches the PO gate on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bom_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mother_style_id')->constrained('mother_styles')->cascadeOnDelete();
            // pending | returned | approved
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('level')->default(1);
            $table->unsignedSmallInteger('cycle')->default(1);
            // Fingerprint of the BOM as submitted; once approved, the approved BOM.
            $table->string('fingerprint', 64)->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('changed_notified_at')->nullable();
            $table->timestamps();

            $table->unique('mother_style_id');
            $table->index(['status', 'level']);
        });

        Schema::create('bom_approval_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_approval_id')->constrained('bom_approvals')->cascadeOnDelete();
            $table->unsignedSmallInteger('cycle');
            $table->unsignedTinyInteger('level');
            // submitted | approved | returned
            $table->string('action', 20);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bom_approval_steps');
        Schema::dropIfExists('bom_approvals');
    }
};
