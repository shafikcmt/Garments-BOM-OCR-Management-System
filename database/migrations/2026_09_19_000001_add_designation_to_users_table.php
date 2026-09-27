<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The user's job title — "Senior Merchandiser", "Supply Chain Officer" — as
 * distinct from their system role. It is what prints under a signer's name on
 * an official document; the role ("Merchant") is a system category, not a
 * title a company would put on a payment request.
 *
 * Nullable, no default, no backfill: an unset designation falls back to the
 * department-derived label the documents already print, so nothing changes for
 * an existing user until an admin fills it in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('designation', 120)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('designation');
        });
    }
};
