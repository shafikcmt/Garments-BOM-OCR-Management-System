<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// General Stock (module A) — the supplier list gets contact details back.
//
// Migration 2026_08_03_000012 dropped these when the table held 0 rows and
// nobody had asked for them. That has changed: the store now needs to phone a
// vendor and find their address before placing a purchase, so the fields have
// a real use rather than being empty boxes on a form.
//
// Written fresh rather than reversing that migration, so the history reads as
// a decision made now and not as an undo. `remarks` is deliberately NOT
// brought back - nothing asks for it, and it would be the empty box the
// earlier migration was right to remove.
//
// All nullable: the 122 suppliers already in the list have none of this, and
// Name stays the only thing required to add one.
//
// This stays General Stock's own list. It is NOT merged with, scoped to, or
// linked against the Buyer/Style `suppliers` table - see
// 2026_08_03_000011 for why those two are kept apart.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('general_stock_suppliers', function (Blueprint $table) {
            $table->string('contact_person')->nullable()->after('name');
            $table->string('phone', 50)->nullable()->after('contact_person');
            $table->string('email')->nullable()->after('phone');
            $table->text('address')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('general_stock_suppliers', function (Blueprint $table) {
            $table->dropColumn(['contact_person', 'phone', 'email', 'address']);
        });
    }
};
