<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// General Stock module (module A) — challan-level receive (Excel "Purchase" sheet).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_purchases', function (Blueprint $table) {
            $table->id();

            $table->foreignId('stock_item_id')->constrained('stock_items')->cascadeOnDelete();

            $table->string('challan_no')->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('qty', 18, 4)->default(0);
            $table->decimal('unit_price', 18, 4)->nullable();
            $table->string('supplier_name')->nullable();
            $table->text('remarks')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['stock_item_id', 'purchase_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_purchases');
    }
};
