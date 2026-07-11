<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-style purchasing budget. A "style" is not a first-class entity in this
 * system — it is the free-text `style_name` carried on booking_pos / payment
 * request items — so the budget is keyed by that name, with optional buyer /
 * season scoping. When a PRA is created the cumulative non-rejected PRA amount
 * for a style is checked against its budget. Additive; nothing else is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('style_budgets', function (Blueprint $table) {
            $table->id();
            $table->string('style_name');
            $table->string('buyer_name')->nullable();
            $table->string('season_name')->nullable();
            $table->decimal('budget_amount', 15, 2)->default(0);
            $table->text('note')->nullable();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('style_name');
            // Best-effort uniqueness for the scope tuple. (MySQL treats NULLs as
            // distinct, so the controller also upserts on this tuple.)
            $table->unique(['style_name', 'buyer_name', 'season_name'], 'style_budgets_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('style_budgets');
    }
};
