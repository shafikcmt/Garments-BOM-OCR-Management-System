<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Buyer/Style module (module B) — movements OUT of Dead stock (Excel "Dead
// Issuing" sheet). Dead is never lost: transferred back to Bulk (reused) or
// issued as sample.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_dead_movements', function (Blueprint $table) {
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

            $table->date('movement_date')->nullable();
            $table->decimal('transfer_to_bulk_qty', 18, 4)->default(0);
            $table->decimal('sample_issue_qty', 18, 4)->default(0);
            $table->text('remarks')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['excel_row_id', 'size']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_dead_movements');
    }
};
