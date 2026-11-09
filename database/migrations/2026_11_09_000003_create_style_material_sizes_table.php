<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Size-wise consumption of a style's materials: when a size needs more (or
 * less) of a material than the BOM's single consumption figure, the figure for
 * that size is kept here. Keyed by style + material + size (not by BOM line), so
 * it applies to every colour of the style and survives the workspace re-syncing.
 * An empty size means "use the BOM consumption".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('style_material_sizes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mother_style_id')->constrained('mother_styles')->cascadeOnDelete();
            $table->string('material_key', 191);
            $table->string('material_name', 255)->nullable();
            $table->string('size', 40);
            $table->decimal('consumption', 18, 6);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['mother_style_id', 'material_key', 'size'], 'style_material_size_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('style_material_sizes');
    }
};
