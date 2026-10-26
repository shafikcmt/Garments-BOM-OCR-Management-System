<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Standard model, Phase 2: the BOM workspace row split into proper tables.
 *
 *   order_lines  one style x color x customer PO of one workspace file
 *   bom_items    one material requirement of an order line (one workspace row)
 *
 * Additive and read-model first: the workspace stays the place users edit, and
 * these tables are filled from it (BomTableSync). Nothing existing is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('excel_file_id')->constrained('excel_files')->cascadeOnDelete();
            $table->string('line_key', 40);
            $table->foreignId('buyer_id')->nullable()->constrained('buyers')->nullOnDelete();
            $table->foreignId('buyer_season_id')->nullable()->constrained('buyer_seasons')->nullOnDelete();
            $table->foreignId('mother_style_id')->nullable()->constrained('mother_styles')->nullOnDelete();
            $table->foreignId('child_style_id')->nullable()->constrained('child_styles')->nullOnDelete();
            $table->string('style_no');
            $table->string('gmts_color')->nullable();
            $table->string('customer_po_no')->nullable();
            $table->date('ship_date')->nullable();
            $table->decimal('order_qty', 18, 4)->nullable();
            $table->json('custom_values')->nullable();
            $table->timestamps();

            $table->unique(['excel_file_id', 'line_key']);
            $table->index('style_no');
        });

        Schema::create('bom_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_line_id')->constrained('order_lines')->cascadeOnDelete();
            $table->foreignId('excel_row_id')->constrained('excel_rows')->cascadeOnDelete();
            $table->string('material_type')->nullable();
            $table->text('material_description')->nullable();
            $table->string('sap_code')->nullable();
            $table->string('material_color')->nullable();
            $table->string('uom')->nullable();
            $table->decimal('bom_qty', 18, 4)->nullable();
            $table->decimal('initial_consumption', 18, 6)->nullable();
            $table->decimal('wastage_percent', 10, 4)->nullable();
            $table->decimal('consumption_incl_yy', 18, 6)->nullable();
            $table->json('custom_values')->nullable();
            $table->timestamps();

            $table->unique('excel_row_id');
            $table->index('sap_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bom_items');
        Schema::dropIfExists('order_lines');
    }
};
