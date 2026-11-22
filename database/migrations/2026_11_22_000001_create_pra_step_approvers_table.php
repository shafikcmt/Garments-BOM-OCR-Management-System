<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADMIN-CHOSEN APPROVERS FOR A DEPARTMENTAL PRA STEP.
 *
 * By default PraStageResolver works out who may act at each of the five steps
 * from the department roles and buyer access (see that class). That gives "no
 * Merchandising Team member has access to this buyer" whenever the data is not
 * set up, and nothing on screen to fix it with except editing users and buyers.
 *
 * This table is the override an Admin can edit on Settings > PRA Approval
 * Routing. One row = one person who may act at one step:
 *
 *   buyer_id NULL      for every PRA at that step
 *   buyer_id = a buyer for PRAs of that buyer only; it wins over the NULL rows
 *
 * A step with no rows here is routed exactly as before. Additive and nullable:
 * nothing existing reads it until a row is written.
 *
 * `sequence` uses the 2..6 numbering of pra_approvals.sequence.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pra_step_approvers')) {
            return;
        }

        Schema::create('pra_step_approvers', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('sequence');
            $table->foreignId('buyer_id')->nullable()->constrained('buyers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['sequence', 'buyer_id']);
            $table->unique(['sequence', 'buyer_id', 'user_id'], 'pra_step_approvers_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pra_step_approvers');
    }
};
