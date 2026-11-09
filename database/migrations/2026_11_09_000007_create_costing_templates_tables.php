<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Costing templates: a ready cost sheet (heads, lines, rates, currency, basis)
 * for a product group, for one buyer or for every buyer, kept on screen. A new
 * pre-costing can start from one instead of the blank starter rows; the lines
 * are copied, so changing a template never changes a costing already made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('costing_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->foreignId('buyer_id')->nullable()->constrained('buyers')->cascadeOnDelete();
            $table->string('product_group', 120)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->string('basis', 2)->default('pc');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['buyer_id', 'product_group']);
        });

        Schema::create('costing_template_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('costing_template_id')->constrained('costing_templates')->cascadeOnDelete();
            $table->string('head', 30);
            $table->string('description', 160)->nullable();
            $table->decimal('qty', 14, 4)->default(1);
            $table->string('unit', 20)->nullable();
            $table->decimal('wastage_pct', 8, 3)->default(0);
            $table->decimal('rate', 14, 4)->default(0);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('costing_template_lines');
        Schema::dropIfExists('costing_templates');
    }
};
