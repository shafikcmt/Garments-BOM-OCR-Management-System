<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per "add rows to an existing file" import, so a mistaken one can be
 * undone as a whole, and so the file shows where each block of rows came from.
 * Additive: nothing existing changes; excel_rows only gains a nullable pointer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('excel_file_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('excel_file_id')->constrained('excel_files')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_name');
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('skipped_duplicates')->default(0);
            $table->unsignedInteger('first_row_number')->nullable();
            $table->unsignedInteger('last_row_number')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->foreignId('undone_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('excel_rows', function (Blueprint $table) {
            $table->unsignedBigInteger('excel_file_import_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('excel_rows', function (Blueprint $table) {
            $table->dropColumn('excel_file_import_id');
        });

        Schema::dropIfExists('excel_file_imports');
    }
};
