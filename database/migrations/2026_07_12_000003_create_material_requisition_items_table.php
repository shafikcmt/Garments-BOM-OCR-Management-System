<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Buyer/Style module (module B) — line items of a requisition slip. A single
// material_requisition (the slip HEADER) now carries many item rows, one per
// BOM/PO material line (all booking_pos sharing the slip's po_no). Additive:
// old single-item requisitions keep working via the header's own columns.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_requisition_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('material_requisition_id')
                ->constrained('material_requisitions')
                ->cascadeOnDelete();

            // Source BOM/PO material line (same linkage pattern as the header).
            $table->foreignId('booking_po_id')->nullable()->constrained('booking_pos')->nullOnDelete();
            $table->foreignId('excel_row_id')->nullable()->constrained('excel_rows')->nullOnDelete();

            // Denormalized material identity copied at create time (from the PO).
            $table->text('material_description')->nullable();
            $table->string('sap_code')->nullable();
            $table->string('material_color')->nullable();
            $table->string('size')->nullable();
            $table->string('uom')->nullable();

            // Required Qty comes from the PO/BOM (read-only on the form).
            $table->decimal('required_qty', 18, 4)->default(0);

            // Issued: picked from the existing stock item master, qty defaults to
            // Required but is editable for a partial issue.
            $table->foreignId('issued_stock_item_id')->nullable()->constrained('stock_items')->nullOnDelete();
            $table->decimal('issued_qty', 18, 4)->default(0);

            // Received: picked from the stock item master, qty defaults to Issued
            // but is editable for a partial receive.
            $table->foreignId('received_stock_item_id')->nullable()->constrained('stock_items')->nullOnDelete();
            $table->decimal('received_qty', 18, 4)->default(0);

            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['material_requisition_id', 'booking_po_id'], 'mri_req_po_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_requisition_items');
    }
};
