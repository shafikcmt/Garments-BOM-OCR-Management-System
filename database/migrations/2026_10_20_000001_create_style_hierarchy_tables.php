<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Buyer -> Season -> Mother Style -> Child Style.
 *
 * Purely additive: nothing existing is altered, so Order / BOM / Purchase keep
 * working untouched. Later phases link BOM files and rows to these tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('buyers')->cascadeOnDelete();
            $table->string('code');
            $table->string('name')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['buyer_id', 'code']);
        });

        Schema::create('mother_styles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('buyers')->cascadeOnDelete();
            $table->foreignId('buyer_season_id')->constrained('buyer_seasons')->cascadeOnDelete();
            $table->string('style_no');
            $table->string('product_group')->nullable();
            $table->string('description')->nullable();
            $table->string('status')->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['buyer_season_id', 'style_no']);
        });

        Schema::create('child_styles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mother_style_id')->constrained('mother_styles')->cascadeOnDelete();
            $table->string('style_no');
            $table->string('kind')->default('variant');
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['mother_style_id', 'style_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('child_styles');
        Schema::dropIfExists('mother_styles');
        Schema::dropIfExists('buyer_seasons');
    }
};
