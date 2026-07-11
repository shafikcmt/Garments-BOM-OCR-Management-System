<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Buyer/Style module (module B) — production issue (Excel "Bulk Issuing" sheet).
// This is the SPLIT point: each issue divides into bulk / sample / declared
// liability / calculated dead. May optionally fulfill a material_requisition.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_bulk_issues', function (Blueprint $table) {
            $table->id();

            $table->foreignId('excel_file_id')->nullable()->constrained('excel_files')->nullOnDelete();
            $table->foreignId('excel_row_id')->nullable()->constrained('excel_rows')->nullOnDelete();
            $table->foreignId('booking_po_id')->nullable()->constrained('booking_pos')->nullOnDelete();
            $table->foreignId('material_requisition_id')->nullable()->constrained('material_requisitions')->nullOnDelete();

            $table->string('po_no')->nullable();
            $table->string('buyer_name')->nullable();
            $table->string('season_name')->nullable();
            $table->string('style_name')->nullable();
            $table->text('material_description')->nullable();
            $table->string('sap_code')->nullable();
            $table->string('material_color')->nullable();
            $table->string('size')->nullable();
            $table->string('uom')->nullable();

            $table->string('issue_no')->nullable();
            $table->date('issue_date')->nullable();

            // The four-way split.
            $table->decimal('bulk_qty', 18, 4)->default(0);
            $table->decimal('sample_qty', 18, 4)->default(0);
            $table->decimal('liability_qty', 18, 4)->default(0);
            $table->decimal('dead_qty', 18, 4)->default(0);

            $table->text('remarks')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['excel_row_id', 'size']);
            $table->index(['buyer_name', 'style_name', 'po_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_bulk_issues');
    }
};
