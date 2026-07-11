<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user digital signature image, uploaded from the user's own profile page.
 * Used to auto-populate the Prepared / Checked / Approved By boxes on the PRA
 * PDF/Excel when that user performs the matching action. Additive and nullable
 * so existing users and the static admin signature fallback are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('signature_path')->nullable()->after('profile_photo');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('signature_path');
        });
    }
};
