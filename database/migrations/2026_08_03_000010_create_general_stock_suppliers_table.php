<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// General Stock (module A) — its own supplier list.
//
// General Stock buys consumables (needles, chemicals, spares) from local
// vendors. The Buyer/Style module's `suppliers` table is a different business
// relationship entirely — nominated fabric and trim suppliers tied to bookings,
// with incoterms, ship modes and tolerances. Sharing one table mixed two lists
// that have nothing to do with each other and made the general store's supplier
// dropdown unusable.
//
// This table is completely independent: the Buyer/Style `suppliers` table is
// not read, written or altered anywhere by General Stock.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('general_stock_suppliers', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();

            $table->boolean('is_active')->default(true);
            $table->text('remarks')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // No DB unique index, for the same reason as the Issue Setup
            // masters: MySQL treats every NULL deleted_at as distinct, so a
            // (name, deleted_at) unique would not stop live duplicates, and a
            // unique on name alone would let one soft-deleted row block that
            // name forever. Uniqueness is enforced in the controller.
            $table->index(['is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('general_stock_suppliers');
    }
};
