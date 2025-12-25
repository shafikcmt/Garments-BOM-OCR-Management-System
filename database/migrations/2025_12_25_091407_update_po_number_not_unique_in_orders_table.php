<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up(): void
{
    Schema::table('orders', function (Blueprint $table) {
        $table->dropUnique(['po_number']); // remove unique index
        $table->string('po_number')->nullable()->change();
    });
}

public function down(): void
{
    Schema::table('orders', function (Blueprint $table) {
        $table->unique('po_number');
    });
}

};
