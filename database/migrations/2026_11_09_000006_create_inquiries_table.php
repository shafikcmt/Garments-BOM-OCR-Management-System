<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buyer inquiries and their quotation: a buyer asks for a price on a style
 * (before any order exists), the merchant costs it, quotes, and the buyer
 * accepts (won) or not (lost). Linked to the Style Library and pre-costing
 * once costing starts; a won inquiry is followed by the buyer's order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiries', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->nullable()->unique();
            $table->foreignId('buyer_id')->constrained('buyers')->cascadeOnDelete();
            $table->foreignId('buyer_season_id')->nullable()->constrained('buyer_seasons')->nullOnDelete();
            $table->foreignId('mother_style_id')->nullable()->constrained('mother_styles')->nullOnDelete();
            $table->string('style_ref', 80);
            $table->string('description', 255)->nullable();
            $table->string('product_group', 60)->nullable();
            $table->unsignedInteger('est_qty')->nullable();
            $table->decimal('target_price', 14, 4)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->date('received_date');
            $table->date('quote_due_date')->nullable();
            $table->string('status', 20)->default('new');
            $table->decimal('quoted_price', 14, 4)->nullable();
            $table->date('quoted_date')->nullable();
            $table->date('decision_date')->nullable();
            $table->string('lost_reason', 255)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['buyer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiries');
    }
};
