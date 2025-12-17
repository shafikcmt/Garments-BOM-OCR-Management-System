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
        Schema::create('order_fields', function (Blueprint $table) {
            $table->id(); // Field ID
            $table->foreignId('section_id')->constrained('order_sections')->onDelete('cascade'); // order_sections.id
            $table->string('field_label'); // Field label
            $table->string('field_key')->unique(); // Unique key for internal reference
            $table->string('field_type'); // text / number / date / select / dropdown
            $table->json('options')->nullable(); // Dropdown / select options (nullable)
            $table->boolean('is_required')->default(false); // Mandatory or not
            $table->text('formula')->nullable(); // Calculation logic (optional)
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
        Schema::dropIfExists('order_fields');
    }
};
