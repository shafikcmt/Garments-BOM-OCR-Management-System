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
        Schema::create('vendors', function (Blueprint $table) {
            $table->id(); // Vendor ID (bigint PK)

            $table->string('vendor_name'); // Vendor name
            $table->string('vendor_type'); // Local / Import
            $table->string('consolidator_name')->nullable(); // Consolidator

            $table->string('contact_email')->nullable(); // Optional email
            $table->string('contact_phone')->nullable(); // Optional phone
            $table->string('address')->nullable(); // Optional address

            $table->tinyInteger('status')->default(1); // Active = 1 / Inactive = 0

            $table->timestamps(); // created_at, updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
