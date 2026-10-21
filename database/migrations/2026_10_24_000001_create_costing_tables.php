<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Pre-costing: a cost sheet per style, by cost head, in two modes (what is quoted
 * to the buyer / what the factory expects to spend) and in numbered revisions.
 * A finalized revision is never edited; a change is a new revision. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('costings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mother_style_id')->constrained('mother_styles')->cascadeOnDelete();
            $table->string('mode', 10);                       // buyer | inhouse
            $table->unsignedSmallInteger('revision');
            $table->string('status', 10)->default('draft');    // draft | final
            $table->string('currency', 3)->default('USD');
            $table->string('basis', 3)->default('pc');         // pc | dz: what every amount is "per"
            $table->decimal('quoted_price', 14, 4)->nullable();
            $table->decimal('total_cost', 14, 4)->default(0);  // kept in step with the lines on every save
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique(['mother_style_id', 'mode', 'revision']);
        });

        Schema::create('costing_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('costing_id')->constrained('costings')->cascadeOnDelete();
            $table->string('head', 20);
            $table->string('description', 160)->nullable();
            $table->decimal('qty', 14, 4)->default(1);
            $table->string('unit', 20)->nullable();
            $table->decimal('wastage_pct', 7, 3)->default(0);
            $table->decimal('rate', 14, 4)->default(0);
            $table->decimal('amount', 14, 4)->default(0);      // qty x (1 + wastage%) x rate
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index('costing_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('costing_lines');
        Schema::dropIfExists('costings');
    }
};
