<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Buyer/Style module (module B) — GRN-level receive (Excel "Receiving" sheet).
// source_type splits Total Receive into Booking-wise vs Internal PO-wise.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_receivings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('excel_file_id')->nullable()->constrained('excel_files')->nullOnDelete();
            $table->foreignId('excel_row_id')->nullable()->constrained('excel_rows')->nullOnDelete();
            $table->foreignId('booking_po_id')->nullable()->constrained('booking_pos')->nullOnDelete();

            $table->string('po_no')->nullable();
            $table->string('buyer_name')->nullable();
            $table->string('season_name')->nullable();
            $table->string('style_name')->nullable();
            $table->text('material_description')->nullable();
            $table->string('sap_code')->nullable();
            $table->string('material_color')->nullable();
            $table->string('size')->nullable();
            $table->string('uom')->nullable();

            $table->string('grn_no')->nullable();
            $table->string('invoice_no')->nullable();
            $table->date('receive_date')->nullable();
            // booking | internal_po
            $table->string('source_type')->default('booking');
            $table->decimal('qty', 18, 4)->default(0);
            $table->decimal('unit_price', 18, 4)->nullable();
            $table->text('remarks')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['excel_row_id', 'size']);
            $table->index(['source_type', 'receive_date']);
            $table->index(['buyer_name', 'style_name', 'po_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_receivings');
    }
};
