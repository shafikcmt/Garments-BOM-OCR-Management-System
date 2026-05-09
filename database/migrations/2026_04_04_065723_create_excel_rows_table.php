<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('excel_rows', function (Blueprint $table) {
            $table->id();

            $table->foreignId('excel_file_id')->constrained('excel_files')->cascadeOnDelete();
            $table->unsignedInteger('row_number');

            $table->timestamps();

            $table->unique(['excel_file_id', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excel_rows');
    }
};