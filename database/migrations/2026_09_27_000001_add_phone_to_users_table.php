<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The user's contact phone number, set by the user on My Profile or by an
 * admin on Admin > Users.
 *
 * Nullable, no default, no backfill: existing users simply have none until
 * someone fills it in. Contact detail only — never a login identifier (the
 * Employee ID on the employees table is that).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('designation');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
