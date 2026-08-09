<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// General Stock (module A) — retire two item-master fields.
//
//   code             never part of the agreed item master; the item name is the
//                    identifier the store actually uses.
//   register_page_no a pointer to a page in the physical store register. The
//                    point of this module is to stop depending on that book, so
//                    carrying its page numbers forward keeps the paper alive.
//
// Written as its own migration rather than editing the originals because those
// have already run wherever this is deployed.
//
// `code` carried an index, dropped first: MySQL removes a single-column index
// with its column, but naming it here keeps the migration explicit and portable.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropIndex(['code']);
            $table->dropColumn(['code', 'register_page_no']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->string('code')->nullable()->after('name');
            $table->string('register_page_no', 50)->nullable()->after('category');
            $table->index('code');
        });
    }
};
