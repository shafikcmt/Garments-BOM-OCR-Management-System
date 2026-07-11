<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// General Stock module (module A) — requisition-style issue (Excel "Consumption"
// and "Non Stock" sheets). is_stock_item = false + null stock_item_id covers the
// old "Non Stock" case (issued item that is not tracked in the item master).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_issues', function (Blueprint $table) {
            $table->id();

            $table->foreignId('stock_item_id')->nullable()->constrained('stock_items')->nullOnDelete();
            $table->boolean('is_stock_item')->default(true);

            // Free-text description for the Non Stock case (no item master row).
            $table->string('item_description')->nullable();

            $table->string('requisition_no')->nullable();
            $table->date('issue_date')->nullable();
            $table->decimal('qty', 18, 4)->default(0);
            $table->string('issued_to')->nullable();
            $table->string('department')->nullable();
            $table->text('remarks')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['stock_item_id', 'issue_date']);
            $table->index(['is_stock_item', 'issue_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_issues');
    }
};
