<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * General Stock (module A) — the counter behind the auto-generated RV No on
 * Record Purchase (AUG26-00001).
 *
 * A counter table rather than a unique index on stock_purchases.rv_no: one
 * receiving event writes SEVERAL purchase rows that all share its RV No, so
 * that column cannot be unique. Uniqueness therefore has to come from the
 * allocator, and this row is what a transaction locks while it takes the next
 * number.
 *
 * One row per period, keyed "AUG26", because the sequence restarts each month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_rv_sequences', function (Blueprint $table) {
            $table->id();
            // "AUG26" — the 3-letter month and 2-digit year the numbers belong to.
            $table->string('period', 5)->unique();
            $table->unsignedInteger('last_no')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_rv_sequences');
    }
};
