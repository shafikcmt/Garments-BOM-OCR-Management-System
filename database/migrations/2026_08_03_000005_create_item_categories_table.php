<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// General Stock (module A) — the fourth "Issue Setup" master: Category.
//
// Same shape as indent_sections / indent_persons / issue_approvers, for the
// same reason: Category was free text, so the same category could be spelled
// three ways and split its own stock report grouping.
//
// stock_items.category stays a plain string and is NOT touched — this master
// drives the Issue form's dropdown. The seed below lifts whatever categories
// the item master already uses so the list starts populated rather than empty.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_categories', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->text('remarks')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // No DB unique index, for the same reason as the other three
            // masters: MySQL treats every NULL deleted_at as distinct, so a
            // (name, deleted_at) unique would not stop live duplicates, and a
            // unique on name alone would let one soft-deleted row block that
            // name forever. Uniqueness is enforced in the controller.
            $table->index(['is_active', 'name']);
        });

        // Best-effort seed from the categories already typed into the item
        // master. Runs only if that table exists and has usable values.
        if (! Schema::hasTable('stock_items')) {
            return;
        }

        $existing = DB::table('stock_items')
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->pluck('category')
            ->map(fn ($name) => trim($name))
            ->filter()
            ->unique(fn ($name) => mb_strtolower($name))
            ->values();

        if ($existing->isEmpty()) {
            return;
        }

        DB::table('item_categories')->insert(
            $existing->map(fn ($name) => [
                'name' => $name,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all()
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('item_categories');
    }
};
