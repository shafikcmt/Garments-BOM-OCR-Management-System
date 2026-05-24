<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_request_id')->constrained('payment_requests')->cascadeOnDelete();
            $table->foreignId('booking_po_id')->nullable()->constrained('booking_pos')->nullOnDelete();
            $table->foreignId('excel_file_id')->nullable()->constrained('excel_files')->nullOnDelete();
            $table->foreignId('excel_row_id')->nullable()->constrained('excel_rows')->nullOnDelete();
            $table->string('po_no')->nullable();
            $table->string('pi_number')->nullable();
            $table->string('pi_status')->nullable();
            $table->decimal('pi_rate', 18, 4)->nullable();
            $table->decimal('pi_amount', 18, 4)->nullable();
            $table->string('payment_status')->nullable();
            $table->date('payment_required_date')->nullable();
            $table->string('supplier_name')->nullable();
            $table->string('buyer_name')->nullable();
            $table->string('season_name')->nullable();
            $table->string('style_name')->nullable();
            $table->text('material_description')->nullable();
            $table->string('sap_code')->nullable();
            $table->string('material_color')->nullable();
            $table->decimal('qty', 18, 4)->nullable();
            $table->string('delivery_term')->nullable();
            $table->string('payment_term')->nullable();
            $table->string('ship_mode')->nullable();
            $table->string('forwarder')->nullable();
            $table->date('committed_etd')->nullable();
            $table->date('committed_eta')->nullable();
            $table->text('remarks')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();

            $table->index(['po_no', 'pi_number']);
            $table->index(['payment_status', 'payment_required_date']);
            $table->index(['supplier_name', 'buyer_name', 'season_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_request_items');
    }
};
