<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item SKU — the code itself, and what makes it safe to hand out.
 *
 *   stock_items.sku           nullable until the backfill, then set once and
 *                             never changed; UNIQUE (many NULLs are allowed on
 *                             MySQL, PostgreSQL and SQLite alike).
 *   stock_item_sku_sequences  the running serial per prefix ("ST-BAL-"),
 *                             taken under a row lock so two items created at
 *                             once cannot get the same number; the unique
 *                             index on sku is the backstop.
 *   stock_item_sku_changes    the log of an Admin correction: old, new,
 *                             reason, who, when.
 *
 * Additive only. Constraint and index names are given explicitly and kept
 * under PostgreSQL's 63-character limit. No SKU is written here — existing
 * items get theirs from `php artisan stock:generate-skus` (dry-run first).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->string('sku', 40)->nullable()->after('id');
            $table->unique('sku', 'stock_items_sku_unique');
        });

        Schema::create('stock_item_sku_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 40);
            $table->unsignedInteger('last_seq')->default(0);
            $table->timestamps();

            $table->unique('prefix', 'sku_sequences_prefix_unique');
        });

        Schema::create('stock_item_sku_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_item_id')->nullable();
            $table->string('old_sku', 40)->nullable();
            $table->string('new_sku', 40);
            $table->text('reason');
            $table->foreignId('user_id')->nullable();
            $table->string('user_name')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('stock_item_id', 'sku_changes_item_fk')->references('id')->on('stock_items')->nullOnDelete();
            $table->foreign('user_id', 'sku_changes_user_fk')->references('id')->on('users')->nullOnDelete();
            $table->index('stock_item_id', 'sku_changes_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_item_sku_changes');
        Schema::dropIfExists('stock_item_sku_sequences');

        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropUnique('stock_items_sku_unique');
            $table->dropColumn('sku');
        });
    }
};
