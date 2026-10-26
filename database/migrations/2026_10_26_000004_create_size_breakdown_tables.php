<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colour x size order breakdown.
 *
 * size_sets: the size runs a buyer uses (XS-XXL, 0-16, 28-40 ...), entered on
 * screen, never coded. A style picks one (mother_styles.size_set_id).
 *
 * order_size_quantities: pieces per size for one colour / customer PO of a
 * style. Keyed by style + colour + PO rather than by order line, so the
 * figures survive the workspace re-syncing its order lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('size_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->nullable()->constrained('buyers')->nullOnDelete();
            $table->string('name', 80);
            $table->json('sizes');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('mother_styles', function (Blueprint $table) {
            $table->foreignId('size_set_id')->nullable()->after('product_group')->constrained('size_sets')->nullOnDelete();
        });

        Schema::create('order_size_quantities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mother_style_id')->constrained('mother_styles')->cascadeOnDelete();
            $table->string('gmts_color', 191)->default('');
            $table->string('customer_po_no', 191)->default('');
            $table->string('size', 40);
            $table->decimal('qty', 14, 2)->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['mother_style_id', 'gmts_color', 'customer_po_no', 'size'], 'order_size_qty_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_size_quantities');
        Schema::table('mother_styles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('size_set_id');
        });
        Schema::dropIfExists('size_sets');
    }
};
