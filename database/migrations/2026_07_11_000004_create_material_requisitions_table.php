<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Buyer/Style module (module B) — the requisition REQUEST step that precedes an
// issue. store-only for now: only the store role creates and fulfills these.
// A material_bulk_issue may later reference the requisition it fulfilled.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_requisitions', function (Blueprint $table) {
            $table->id();

            // BOM / PO linkage (same pattern as payment_request_items).
            $table->foreignId('excel_file_id')->nullable()->constrained('excel_files')->nullOnDelete();
            $table->foreignId('excel_row_id')->nullable()->constrained('excel_rows')->nullOnDelete();
            $table->foreignId('booking_po_id')->nullable()->constrained('booking_pos')->nullOnDelete();

            // Denormalized identity columns for fast reporting (copied at create time).
            $table->string('po_no')->nullable();
            $table->string('buyer_name')->nullable();
            $table->string('season_name')->nullable();
            $table->string('style_name')->nullable();
            $table->text('material_description')->nullable();
            $table->string('sap_code')->nullable();
            $table->string('material_color')->nullable();
            $table->string('size')->nullable();
            $table->string('uom')->nullable();

            $table->string('requisition_no')->nullable();
            // pending -> approved -> issued
            $table->string('status')->default('pending');
            $table->decimal('qty', 18, 4)->default(0);

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('remarks')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'requisition_no']);
            $table->index(['excel_row_id', 'size']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_requisitions');
    }
};
