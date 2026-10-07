<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 4 — the merge. A department sheet the Store has received is merged
 * into the Store's final Purchase Requisition; this records which one.
 *
 *   merged_into_id  the final sheet it went into. NULL = not merged. Freed
 *                   again if that final sheet is deleted (a draft) or
 *                   cancelled, so the department sheet can be merged anew.
 *   merged_at / merged_by  when and by whom.
 *
 * Additive, nullable, no backfill: existing sheets read as not merged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->foreignId('merged_into_id')->nullable()->after('current_stage')
                ->constrained('purchase_requisitions')->nullOnDelete();
            $table->timestamp('merged_at')->nullable()->after('merged_into_id');
            $table->foreignId('merged_by')->nullable()->after('merged_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_by');
            $table->dropColumn('merged_at');
            $table->dropConstrainedForeignId('merged_into_id');
        });
    }
};
