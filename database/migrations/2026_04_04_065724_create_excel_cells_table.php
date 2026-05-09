<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('excel_cells', function (Blueprint $table) {
            $table->id();

            $table->foreignId('row_id')->constrained('excel_rows')->cascadeOnDelete();
            $table->foreignId('header_id')->constrained('excel_headers')->cascadeOnDelete();

            $table->longText('value')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['row_id', 'header_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excel_cells');
    }
};