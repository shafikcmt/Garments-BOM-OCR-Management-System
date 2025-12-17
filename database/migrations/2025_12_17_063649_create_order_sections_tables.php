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
        Schema::create('order_sections', function (Blueprint $table) {
            $table->id(); // Section ID
            $table->string('name'); // Supplier, Commercial, Store
            $table->foreignId('role_id')->constrained('roles')->onDelete('cascade'); // Linked role
            $table->text('description')->nullable(); // Optional notes about section
            $table->integer('sort_order')->default(0); // Display order
            $table->boolean('is_active')->default(true); // Enable / Disable
            $table->timestamps(); // created_at, updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_sections');
    }
};
