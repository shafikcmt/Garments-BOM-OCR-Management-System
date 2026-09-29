<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 3d — an approver changes a line's quantity at their own stage.
 *
 * One row per line changed: what it was, what it became, at which stage and
 * cycle, by whom, and the reason (one reason per save, copied onto each row
 * of that save). The line's qty_requested is updated in place, so every report
 * follows the approved figure; this table is how the screen still shows what
 * was originally requested.
 *
 * Additive: a new table, nothing existing is touched. Plain column types, so
 * the same on MySQL, PostgreSQL and SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Constraint and index names are given explicitly: the generated ones
        // run past MySQL's 64 and PostgreSQL's 63 character limits.
        Schema::create('purchase_requisition_qty_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_requisition_id');
            // Null once the raiser, amending a returned sheet, removes the line.
            $table->foreignId('purchase_requisition_item_id')->nullable();
            $table->unsignedInteger('cycle')->default(1);
            $table->unsignedInteger('stage_sequence')->nullable();
            $table->string('stage_label')->nullable();
            // Copied, so the record reads the same after the line is gone.
            $table->string('item_name')->nullable();
            $table->decimal('old_qty', 18, 4);
            $table->decimal('new_qty', 18, 4);
            $table->text('reason');
            $table->foreignId('user_id')->nullable();
            $table->string('user_name')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('purchase_requisition_id', 'pr_qty_changes_requisition_fk')
                ->references('id')->on('purchase_requisitions')->cascadeOnDelete();
            $table->foreign('purchase_requisition_item_id', 'pr_qty_changes_item_fk')
                ->references('id')->on('purchase_requisition_items')->nullOnDelete();
            $table->foreign('user_id', 'pr_qty_changes_user_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->index(['purchase_requisition_id', 'cycle'], 'pr_qty_changes_requisition_cycle_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requisition_qty_changes');
    }
};
