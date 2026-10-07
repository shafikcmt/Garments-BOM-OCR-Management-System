<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A department's sheet ends with "Received by Store", which ANY Store user
 * may give — the Store Head signs the merged final sheet instead. The
 * department sheet's Store box therefore moves from the Store department
 * head (store_head) to the Store team (store_team).
 *
 * Settings rows, plus the Store box of department sheets still waiting at
 * it, so they do not stay with the Store head alone. The box keeps its
 * stamp (store_ack_by / at, status Received by Store), so lists, counting
 * and reports are unchanged. A caption still at its seeded default reads
 * "Received by / Store"; one the Admin renamed is left as it is.
 *
 * No schema change. down() puts the settings rows back.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('requisition_signature_blocks')
            ->where('level', 'department')
            ->where('assignee_type', 'store_head')
            ->where('stamp_source', 'store_head')
            ->update(['assignee_type' => 'store_team', 'user_id' => null, 'role_name' => null, 'updated_at' => $now]);

        DB::table('requisition_signature_blocks')
            ->where('level', 'department')
            ->where('assignee_type', 'store_team')
            ->where('label', 'Checked by')
            ->where('title', 'Store Head')
            ->update(['label' => 'Received by', 'title' => 'Store', 'updated_at' => $now]);

        $waiting = DB::table('purchase_requisition_stages')
            ->where('status', 'pending')
            ->where('assignee_type', 'store_head')
            ->where('stamp_source', 'store_head')
            ->whereIn('purchase_requisition_id', DB::table('purchase_requisitions')->where('level', 'department')->select('id'));

        (clone $waiting)->where('label', 'Checked by')->where('title', 'Store Head')
            ->update(['label' => 'Received by', 'title' => 'Store']);

        $waiting->update([
            'assignee_type' => 'store_team',
            'assignee_user_id' => null,
            'assignee_name' => 'Store team',
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('requisition_signature_blocks')
            ->where('level', 'department')
            ->where('assignee_type', 'store_team')
            ->update(['assignee_type' => 'store_head', 'updated_at' => now()]);
    }
};
