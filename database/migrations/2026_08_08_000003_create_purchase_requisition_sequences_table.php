<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * General Stock (module A) — the counter behind the auto-generated Requisition
 * No (HAPL/PRODUCTION/2026/August/01).
 *
 * Same allocator pattern as stock_rv_sequences: the period's row is locked for
 * the length of the transaction, so two people saving at the same moment queue
 * instead of colliding.
 *
 * The period key carries the SECTION as well as the month, because the serial
 * restarts per section per month — "…/August/01" exists once for Production and
 * once for Stationary, exactly as the paper numbering worked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requisition_sequences', function (Blueprint $table) {
            $table->id();
            // "2026-08|PRODUCTION" — month plus the section slug.
            $table->string('period', 120)->unique();
            $table->unsignedInteger('last_no')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requisition_sequences');
    }
};
