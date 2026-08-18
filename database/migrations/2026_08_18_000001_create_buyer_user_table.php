<?php

use App\Support\BuyerPivotBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Many buyers per Merchandising team member.
 *
 * ADDITIVE ONLY. users.buyer_id is not dropped, not nulled and not read from
 * again by anything that changes here — it stays the primary/default buyer,
 * and every existing query against it goes on working. The whole of this
 * migration undoes by dropping one table.
 *
 * Team members only. A department admin's buyers stay on
 * buyers.department_admin_id, which has held several since the hasMany change;
 * the two mechanisms answer different questions and merging them would put
 * ownership and assignment in one bucket.
 *
 * SOFT DELETES: `buyers` soft-deletes, so removing a buyer through the UI does
 * NOT fire the cascade below and its team keeps the assignment. That is the
 * same thing users.buyer_id does today — deliberate parity, not an oversight.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_user', function (Blueprint $table) {
            $table->id();

            $table->foreignId('buyer_id')->constrained('buyers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->timestamps();

            // One row per pair. This is what lets the backfill be re-run after
            // a half-finished deploy, and what stops a double-submitted
            // assignment form recording the same buyer twice.
            $table->unique(['buyer_id', 'user_id']);
        });

        // Every current single-buyer assignment, copied in, so nothing changes
        // for anyone already scoped. Zero rows on development, where no user
        // has a buyer_id at all — see BuyerPivotBackfill for why that means
        // the class exists separately.
        app(BuyerPivotBackfill::class)->run();
    }

    public function down(): void
    {
        // The backfilled rows go with the table. users.buyer_id was never
        // touched, so this returns the system to exactly its previous state.
        Schema::dropIfExists('buyer_user');
    }
};
