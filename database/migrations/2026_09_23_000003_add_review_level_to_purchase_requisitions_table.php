<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WHICH head a department requisition is with.
 *
 * The check before the Store used to be one step — the department head —
 * so department_review_status (pending / approved / rejected) said it all.
 * A section now has a head of its own, and the sheet goes to the nearest
 * head above the requester: section head if there is one who is not the
 * requester, else department head, else the Store. review_level names that
 * step, so "pending" can be read as "With Section Head" or "With Department
 * Head", and a sheet sent back returns to the SAME level on resubmit.
 *
 *   review_level   'section' | 'department' | NULL (no head step)
 *   section_checked_by / _at   the section head's stamp, beside the
 *                              department head's existing pair
 *
 * Backfill: every row with a review status was decided by a department
 * head — that was the only level — so it is marked 'department'.
 *
 * department_role was stamped with the requester's ROLE (merchant,
 * production). Departments are data now and a department is a group of
 * roles, so the column carries the department KEY from here on; existing
 * values are mapped through department_roles. The column keeps its name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->string('review_level', 20)->nullable()->after('department_review_status')->index();
            $table->foreignId('section_checked_by')->nullable()->after('department_review_note')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('section_checked_at')->nullable()->after('section_checked_by');
        });

        DB::table('purchase_requisitions')
            ->whereNotNull('department_review_status')
            ->whereNull('review_level')
            ->update(['review_level' => 'department']);

        if (Schema::hasTable('department_roles')) {
            foreach (DB::table('department_roles')->get() as $map) {
                DB::table('purchase_requisitions')
                    ->where('department_role', $map->role_name)
                    ->update(['department_role' => $map->department_key]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('section_checked_by');
            $table->dropColumn(['review_level', 'section_checked_at']);
        });
    }
};
