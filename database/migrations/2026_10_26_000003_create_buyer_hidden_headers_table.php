<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workspace columns a buyer does not use, hidden from that buyer's files on
 * the workspace (Admin > Buyers > Workspace Columns). Display only: nothing
 * is deleted, formulas and saving are unchanged, and anyone can switch to
 * "Show all columns".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_hidden_headers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('buyers')->cascadeOnDelete();
            $table->foreignId('excel_header_id')->constrained('excel_headers')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['buyer_id', 'excel_header_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_hidden_headers');
    }
};
