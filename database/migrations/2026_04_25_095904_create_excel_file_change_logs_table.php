<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('excel_file_change_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('excel_file_id')->constrained('excel_files')->cascadeOnDelete();
            $table->foreignId('excel_row_id')->nullable()->constrained('excel_rows')->cascadeOnDelete();
            $table->foreignId('excel_header_id')->nullable()->constrained('excel_headers')->nullOnDelete();

            $table->unsignedInteger('row_number')->nullable();
            $table->string('header_name')->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();

            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('batch_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('excel_file_change_logs');
    }
};
