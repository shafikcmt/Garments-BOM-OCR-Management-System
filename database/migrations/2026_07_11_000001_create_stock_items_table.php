<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// General Stock module (module A) — item master. Fully independent of BOM:
// no excel_row_id / booking_po_id link here by design.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('code')->nullable();
            $table->string('uom')->nullable();
            $table->string('category')->nullable();

            // Re-order / safety-stock settings (from the Excel "Stock <Month>" sheet).
            $table->decimal('safety_stock_qty', 18, 4)->nullable();
            $table->decimal('reorder_level', 18, 4)->nullable();
            $table->unsignedInteger('lead_time_days')->nullable();

            $table->boolean('is_active')->default(true);
            $table->text('remarks')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['category', 'is_active']);
            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_items');
    }
};
