<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot of the creator's personal signature taken when the PRA is created,
 * used for the "Prepared By" box on the PDF/Excel. Stored on the PRA (rather
 * than read live from the creator) so historic documents stay unchanged if the
 * creator later updates their signature. Nullable and additive: when empty the
 * static admin signature fallback is used exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->string('prepared_signature_path')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->dropColumn('prepared_signature_path');
        });
    }
};
