<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// GRN No is now system-generated and must be unique across every receiving,
// regardless of PO/vendor. A DB-level UNIQUE index is the race-safe guard
// against concurrent inserts (app-level checks alone can collide). Existing
// manual GRNs keep their values; multiple NULLs remain allowed in MySQL.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_receivings', function (Blueprint $table) {
            $table->unique('grn_no', 'material_receivings_grn_no_unique');
        });
    }

    public function down(): void
    {
        Schema::table('material_receivings', function (Blueprint $table) {
            $table->dropUnique('material_receivings_grn_no_unique');
        });
    }
};
