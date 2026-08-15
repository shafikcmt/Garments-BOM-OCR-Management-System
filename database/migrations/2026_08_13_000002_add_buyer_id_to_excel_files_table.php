<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files uploaded before this migration keep a null buyer_id. The workspace
 * list still resolves their buyer from the first row's "Buyer Name" cell, so
 * nothing in the existing workflow depends on this column being filled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('excel_files', function (Blueprint $table) {
            $table->foreignId('buyer_id')
                ->nullable()
                ->after('uploaded_by')
                ->constrained('buyers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('excel_files', function (Blueprint $table) {
            $table->dropConstrainedForeignId('buyer_id');
        });
    }
};
