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
        Schema::create('payments', function (Blueprint $table) {
            $table->id(); // Payment ID (bigint PK)

            $table->foreignId('order_id')
                ->constrained('orders')
                ->onDelete('cascade'); // Linked order

            $table->string('payment_term'); // LC / TT
            $table->string('document_no')->nullable(); // Payment document
            $table->decimal('amount', 14, 2)->default(0); // Payment amount
            $table->string('status')->default('Pending'); // Paid / Pending
            $table->timestamp('paid_at')->nullable(); // Actual payment date

            $table->timestamps(); // created_at, updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
