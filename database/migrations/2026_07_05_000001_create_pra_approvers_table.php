<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pool of users the admin has designated as PRA (Payment Request Approval)
 * approvers. A creator can only send a PRA for approval to users listed here.
 * This table is additive and does not touch the existing payment request flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pra_approvers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('user_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pra_approvers');
    }
};
