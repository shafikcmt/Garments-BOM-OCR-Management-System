<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_pos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('excel_file_id')->nullable()->constrained('excel_files')->nullOnDelete();
            $table->foreignId('excel_row_id')->constrained('excel_rows')->cascadeOnDelete();

            $table->string('po_no')->unique();
            $table->string('buyer_code', 10)->nullable();
            $table->string('season_code', 20)->nullable();

            $table->string('buyer_name')->nullable();
            $table->string('season_name')->nullable();
            $table->string('ihod')->nullable();
            $table->string('vendor_name')->nullable();
            $table->string('style_name')->nullable();
            $table->string('item_name')->nullable();
            $table->decimal('qty', 15, 4)->nullable();
            $table->string('uom')->nullable();
            $table->string('item_type')->nullable();
            $table->text('description')->nullable();
            $table->string('color')->nullable();
            $table->string('size_width')->nullable();
            $table->string('supplier_article')->nullable();
            $table->decimal('consumption', 15, 4)->nullable();
            $table->text('remarks')->nullable();

            $table->json('booking_data')->nullable();

            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();

            $table->timestamps();

            $table->unique('excel_row_id');
            $table->index(['buyer_name', 'season_name', 'vendor_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_pos');
    }
};
