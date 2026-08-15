<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one Merchant department-admin who owns a buyer.
 *
 * Single nullable FK rather than a pivot: only Merchandising is scoped by
 * buyer, and a buyer has at most one owning admin. Null means nobody owns it
 * yet, which is the state every buyer starts in and a perfectly valid one —
 * an unowned buyer's files stay editable by every merchant, as today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->foreignId('department_admin_id')
                ->nullable()
                ->after('buyer_name')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_admin_id');
        });
    }
};
