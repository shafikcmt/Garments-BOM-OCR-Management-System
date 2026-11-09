<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consumption master: the standard materials, consumption and wastage of a
 * product group (for one buyer, or for every buyer), kept on screen. Create BOM
 * can fill a style's materials from it ("Fill from Consumption Master"); the
 * merchant checks and edits the figures before saving, so the master proposes
 * and never writes a BOM by itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumption_masters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->nullable()->constrained('buyers')->cascadeOnDelete();
            $table->string('product_group', 120)->nullable();
            $table->string('material_type', 60)->nullable();
            $table->string('material_description', 255);
            $table->string('sap_code', 60)->nullable();
            $table->string('material_color', 120)->nullable();
            $table->string('uom', 20)->nullable();
            $table->decimal('consumption', 18, 6);
            $table->decimal('wastage_percent', 8, 3)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['buyer_id', 'product_group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumption_masters');
    }
};
