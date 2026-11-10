<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The item category printed as a group heading on a long Comparative Statement. Nullable: old statements simply have none. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gs_cs_items', function (Blueprint $table) {
            $table->string('category', 120)->nullable()->after('item_name');
        });
    }

    public function down(): void
    {
        Schema::table('gs_cs_items', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
