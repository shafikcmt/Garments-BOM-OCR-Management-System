<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Buyer scope for booking instructions.
 *
 * WHY. Every row in booking_instructions printed on every PO, for every buyer,
 * because there was nothing to say otherwise. Buyer-specific clauses went in
 * anyway — the live table carries a Hugo Boss RSL / PFC-free declaration and a
 * down-proof test requirement — so a supplier working a different buyer's order
 * received instructions about a brand that had nothing to do with it.
 *
 * NULL MEANS GLOBAL, and null is the default, so every existing row stays
 * exactly as it behaves today. The column only ever narrows a row that someone
 * has deliberately narrowed.
 *
 * nullOnDelete rather than cascade: losing a buyer should widen its
 * instructions back to global, never silently delete instruction text an admin
 * wrote. Note buyers uses SoftDeletes, so this fires only on a hard delete —
 * and six of the nine live buyers are soft-deleted while two of them (HUMANA,
 * NEXT) still appear on booking_pos. That is why the reader resolves buyers
 * withTrashed(); see BookingController::instructionBuyerIdForBooking().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('booking_instructions') || Schema::hasColumn('booking_instructions', 'buyer_id')) {
            return;
        }

        Schema::table('booking_instructions', function (Blueprint $table) {
            $table->foreignId('buyer_id')
                ->nullable()
                ->after('instruction')
                ->constrained('buyers')
                ->nullOnDelete();
        });

        $this->backfillHugoBoss();
    }

    public function down(): void
    {
        if (! Schema::hasTable('booking_instructions') || ! Schema::hasColumn('booking_instructions', 'buyer_id')) {
            return;
        }

        Schema::table('booking_instructions', function (Blueprint $table) {
            $table->dropForeign(['buyer_id']);
            $table->dropColumn('buyer_id');
        });
    }

    /**
     * Attach the one instruction whose own text names its buyer.
     *
     * Both sides are LOOKED UP, never hardcoded as ids — this has to be safe on
     * production, where the ids differ from local. If either lookup misses, the
     * row simply stays global, which is the behaviour it has today; a backfill
     * that cannot find its target must do nothing rather than guess.
     *
     * whereLike() compiles to ILIKE on PostgreSQL and LIKE on MySQL, so the
     * match is case-insensitive on both engines. Deliberately NOT hand-written
     * ILIKE, which is a syntax error on MySQL.
     *
     * Only this one instruction is assigned. The down-proof / HIGHTECHFILL
     * clause reads as buyer-specific too, but its text names no buyer and the
     * material appears nowhere in the BOM data, so assigning it would be a
     * guess. It stays global until the business says otherwise.
     */
    private function backfillHugoBoss(): void
    {
        $buyerId = DB::table('buyers')
            ->whereLike('buyer_name', 'Hugo Boss')
            ->value('id');

        if (! $buyerId) {
            return;
        }

        DB::table('booking_instructions')
            ->whereLike('instruction', '%Hugo Boss RSL%')
            ->update(['buyer_id' => $buyerId]);
    }
};
