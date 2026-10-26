<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sample tracking: each sample of a style (type, colour, size, qty), when the
 * buyer needs it, when it was sent (courier / AWB), and the buyer's verdict
 * and comments. Sample types are a list kept on screen (Samples > Sample
 * Types); the common ones are added here as a starting point and can be
 * renamed or switched off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sample_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mother_style_id')->constrained('mother_styles')->cascadeOnDelete();
            $table->foreignId('child_style_id')->nullable()->constrained('child_styles')->nullOnDelete();
            $table->foreignId('sample_type_id')->constrained('sample_types');
            $table->string('gmts_color', 120)->nullable();
            $table->string('size', 40)->nullable();
            $table->unsignedInteger('qty')->default(1);
            $table->date('required_date')->nullable();
            $table->date('sent_date')->nullable();
            $table->string('courier_awb', 120)->nullable();
            $table->string('status', 20)->default('requested');
            $table->text('buyer_comment')->nullable();
            $table->date('comment_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['mother_style_id', 'status']);
        });

        $now = now();
        DB::table('sample_types')->insert(collect(['Proto', 'Fit', 'Size Set', 'PP (Pre-production)', 'Photo', 'TOP (Top of Production)', 'Shipment'])
            ->map(fn ($name, $i) => ['name' => $name, 'position' => $i + 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now])->all());
    }

    public function down(): void
    {
        Schema::dropIfExists('samples');
        Schema::dropIfExists('sample_types');
    }
};
