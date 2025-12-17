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
        Schema::create('order_values', function (Blueprint $table) {
            $table->id(); // Value ID (bigint PK)

            $table->foreignId('order_id')
                  ->constrained('orders')
                  ->onDelete('cascade'); // Linked order

            $table->foreignId('field_id')
                  ->constrained('order_fields')
                  ->onDelete('cascade'); // Linked field

            $table->text('value')->nullable(); // User input value

            $table->foreignId('role_id')
                  ->constrained('roles')
                  ->onDelete('cascade'); // Role who entered

            $table->foreignId('user_id')
                  ->constrained('users')
                  ->onDelete('cascade'); // User who entered

            $table->boolean('is_locked')->default(false); // Prevent editing after approval

            $table->timestamps(); // created_at, updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_values');
    }
};
