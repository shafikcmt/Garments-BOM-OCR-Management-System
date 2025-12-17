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
        Schema::create('materials', function (Blueprint $table) {
            $table->id(); // Material ID (bigint PK)

            $table->string('material_type'); // Fabric / Trims
            $table->string('description'); // Material description
            $table->string('sap_code')->nullable(); // SAP code
            $table->string('color')->nullable(); // Material color
            $table->string('unit'); // meters, pcs
            $table->decimal('cost_per_unit', 12, 2)->default(0); // Cost per unit

            $table->timestamps(); // created_at, updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('materials');
    }
};
