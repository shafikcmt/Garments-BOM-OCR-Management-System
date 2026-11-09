<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Column titles that came in a buyer's Excel and that the system has no header
 * for (and that no header answers to as an Other Name). Recorded when a file is
 * imported, so Admin can see what the buyers send that the workspace cannot hold
 * and either teach the system the name or ignore it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_unmapped_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->nullable()->constrained('buyers')->nullOnDelete();
            $table->string('title', 191);
            $table->string('title_key', 191);
            $table->unsignedInteger('times_seen')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->foreignId('last_file_id')->nullable()->constrained('excel_files')->nullOnDelete();
            $table->string('status', 12)->default('open');
            $table->foreignId('mapped_header_id')->nullable()->constrained('excel_headers')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['buyer_id', 'title_key']);
            $table->index(['status', 'title_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_unmapped_columns');
    }
};
