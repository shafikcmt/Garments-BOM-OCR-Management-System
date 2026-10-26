<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Costing approval: a finalized revision waits for the Merchandising Head,
 * Management or Admin, who approve it or return it (back to draft) with a
 * reason. Costings finalized before this have no approval status and can be
 * sent for approval from the costing page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('costings', function (Blueprint $table) {
            $table->string('approval_status', 20)->nullable()->after('finalized_at');
            $table->foreignId('approved_by')->nullable()->after('approval_status')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('approval_comment')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('costings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['approval_status', 'approved_at', 'approval_comment']);
        });
    }
};
