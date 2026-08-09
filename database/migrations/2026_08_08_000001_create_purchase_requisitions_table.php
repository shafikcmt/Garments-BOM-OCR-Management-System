<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * General Stock (module A) — the Purchase Requisition header, replacing the
 * "Month_Of_<Month>.xlsx" workbook the store kept by hand.
 *
 * One row per requisition document. Its item lines live in
 * purchase_requisition_items; a single-item and a multi-item requisition are
 * the SAME shape and differ only by `mode`, so both land in one list and one
 * report rather than in two parallel tables.
 *
 * Additive: nothing here touches stock_items, stock_purchases or stock_issues.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requisitions', function (Blueprint $table) {
            $table->id();

            // "HAPL/PRODUCTION/2026/August/01" — allocated by
            // PurchaseRequisitionNumberGenerator, but editable on the form, so
            // a number typed to match an existing paper document is allowed.
            // Unique because one requisition is exactly one document (unlike
            // RV No, which several purchase rows deliberately share).
            $table->string('requisition_no', 100)->unique();
            $table->date('requisition_date');

            // 'single' = the quick one-item form, 'multi' = the full form. A
            // flag rather than a separate table: both follow the same approval
            // flow and appear in the same report.
            $table->enum('mode', ['single', 'multi'])->default('multi');

            // Header block of the Excel sheet (rows 3-8).
            $table->string('unit_name')->nullable();
            $table->foreignId('indent_section_id')->nullable()->constrained()->nullOnDelete();
            // Named explicitly: Laravel would guess "indent_people", but the
            // master table created by the Issue Setup migration is
            // indent_persons (see IndentPerson::$table for the same note).
            $table->foreignId('indent_person_id')->nullable()->constrained('indent_persons')->nullOnDelete();
            $table->string('contact')->nullable();

            // Draft is the only status the creator can leave it in; everything
            // beyond Submitted is gated by the store.requisition.* permissions.
            $table->enum('status', [
                'draft',
                'submitted',
                'store_reviewed',
                'approved',
                'purchase_action_taken',
            ])->default('draft')->index();

            $table->timestamp('submitted_at')->nullable();

            // Store Acknowledgement on GRN — replaces the physical signature in
            // the Excel's Q:R column group.
            $table->foreignId('store_ack_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('store_ack_at')->nullable();

            // Accounts Acknowledgement on Payment — the S:T column group.
            $table->foreignId('accounts_ack_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accounts_ack_at')->nullable();

            // The printed footer: Prepared by / Checked by / Approved by.
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The list screen filters by month and orders by date.
            $table->index('requisition_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requisitions');
    }
};
