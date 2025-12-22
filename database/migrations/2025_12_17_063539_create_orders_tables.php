<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id(); // bigint PK
            $table->string('buyer_name'); // Buyer
            $table->string('season_name'); // Season
            $table->string('order_number')->unique(); // Unique order code/number
            $table->string('style_name'); // Style
            $table->integer('quantity'); // Quantity
            $table->date('shipment_date'); // Contract shipment date
            $table->string('contract_number'); // Contract No
            $table->string('status')->default('Open'); // Open / Processing / Completed
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade'); // Admin ID
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null'); // Approver
            $table->timestamps(); // created_at, updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
