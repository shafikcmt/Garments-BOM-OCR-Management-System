<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The history of a purchase requisition: one row per thing someone did to it.
 *
 * Append-only. A row is written in the same transaction as the change it
 * records, and nothing ever edits or deletes one.
 *
 * Survives the sheet and the user: both foreign keys are NULL ON DELETE, and
 * requisition_no / user_name are copies taken at the time, so a deleted
 * sheet's history — including who deleted it and why — still reads in full.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requisition_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_requisition_id')->nullable()->constrained()->nullOnDelete();
            $table->string('requisition_no', 100)->nullable();
            $table->string('action', 40)->index();
            $table->string('stage', 40)->nullable();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->text('comment')->nullable();
            $table->unsignedSmallInteger('cycle')->default(1);
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requisition_actions');
    }
};
