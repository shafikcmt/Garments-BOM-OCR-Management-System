<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('excel_files', function (Blueprint $table) {
            $table->id();

            $table->string('file_name');
            $table->string('original_file_name');
            $table->string('file_path');

            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();

            $table->string('upload_batch_no')->nullable();
            $table->unsignedInteger('total_rows')->default(0);

            $table->string('status')->default('draft'); // draft, submitted, processing, completed, rejected
            $table->text('remarks')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excel_files');
    }
};