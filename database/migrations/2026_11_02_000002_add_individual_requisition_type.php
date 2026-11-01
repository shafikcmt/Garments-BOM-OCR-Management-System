<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two kinds of purchase requisition (owner, 2026-11-02):
 *
 *   monthly     the department's sheet is received by the Store and merged
 *               with the others into the month's Final Requisition (as before);
 *   individual  a one-off requisition: received by the Store, NOT merged, then
 *               Finance & Commercial Head and Operation Head approve it itself.
 *
 * Also: the HR Audit box is dropped from the Final Requisition's approval
 * boxes (monthly and individual both skip it). Sheets already on their way
 * keep their own stages; only new sheets use the new plan.
 *
 * Additive: one defaulted column, plus settings rows (Admin > Requisition
 * Signatures edits them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->string('requisition_type', 20)->default('monthly')->after('level');
        });

        // 1. No HR Audit box on the Final Requisition; close the gap in the numbering.
        DB::table('requisition_signature_blocks')->where('level', 'final')->where('label', 'Audit by')->delete();
        $n = 0;
        foreach (DB::table('requisition_signature_blocks')->where('level', 'final')->orderBy('sequence')->get() as $row) {
            DB::table('requisition_signature_blocks')->where('id', $row->id)->update(['sequence' => ++$n]);
        }

        // 2. The Individual chain: the department's three boxes (Prepared,
        //    Department Head, Store Head = Received), then Finance & Commercial
        //    Head and Operation Head — the same people as the Final chain.
        if (DB::table('requisition_signature_blocks')->where('level', 'individual')->exists()) {
            return;
        }

        $now = now();
        $copy = function ($row, int $sequence) use ($now) {
            DB::table('requisition_signature_blocks')->insert([
                'level' => 'individual',
                'sequence' => $sequence,
                'label' => $row->label,
                'title' => $row->title,
                'assignee_type' => $row->assignee_type,
                'role_name' => $row->role_name,
                'user_id' => $row->user_id,
                'stamp_source' => $row->stamp_source,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        };

        $seq = 0;
        foreach (DB::table('requisition_signature_blocks')->where('level', 'department')->orderBy('sequence')->get() as $row) {
            $copy($row, ++$seq);
        }

        $final = DB::table('requisition_signature_blocks')->where('level', 'final')->orderBy('sequence')->get();
        $finance = $final->first(fn ($r) => stripos((string) $r->title, 'Finance') !== false);
        $operation = $final->first(fn ($r) => $r->stamp_source === 'approved');

        $copy($finance ?? (object) ['label' => 'Checked by', 'title' => 'Finance & Commercial Head', 'assignee_type' => 'user', 'role_name' => null, 'user_id' => null, 'stamp_source' => null], ++$seq);
        $copy($operation ?? (object) ['label' => 'Approved by', 'title' => 'Operation Head', 'assignee_type' => 'user', 'role_name' => null, 'user_id' => null, 'stamp_source' => 'approved'], ++$seq);
    }

    public function down(): void
    {
        DB::table('requisition_signature_blocks')->where('level', 'individual')->delete();

        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->dropColumn('requisition_type');
        });
    }
};
