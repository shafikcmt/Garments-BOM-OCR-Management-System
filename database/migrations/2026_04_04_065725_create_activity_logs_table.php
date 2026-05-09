<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('excel_file_id')->constrained('excel_files')->cascadeOnDelete();
            $table->foreignId('row_id')->nullable()->constrained('excel_rows')->nullOnDelete();
            $table->foreignId('header_id')->nullable()->constrained('excel_headers')->nullOnDelete();

            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();

            $table->string('action'); // update, submit, assign, complete

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};