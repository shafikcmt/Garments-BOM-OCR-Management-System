<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// General Stock (module A) — identifying detail on the item master.
//
// A consumable store carries the same nominal item in several brands and sizes
// ("Needle DPX17" in 14 and 16, from two suppliers), and the name alone stops
// being enough to pick the right one off the shelf.
//
//   brand          the maker, free text.
//   size           free text on purpose — "14", "M", "40/2" are labels, not
//                  numbers, and a numeric column would refuse most of them.
//   specification  a longer note, so TEXT rather than a short string.
//
// All three additive and nullable: existing items keep working untouched.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->string('brand')->nullable()->after('name');
            $table->string('size', 100)->nullable()->after('brand');
            $table->text('specification')->nullable()->after('size');
        });
    }

    public function down(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropColumn(['brand', 'size', 'specification']);
        });
    }
};
