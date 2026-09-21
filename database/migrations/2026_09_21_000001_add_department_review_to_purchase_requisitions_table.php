<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Department Head step of a department-raised requisition.
 *
 * A requisition now comes in two LEVELS:
 *
 *   department  raised by a department for its own needs. Goes to that
 *               department's head first, then to the Store.
 *   final       raised by the Store itself — the merged Purchase Requisition
 *               that goes on to Audit / Finance / Operation for signature.
 *
 * None of this touches the `status` enum. `status` still walks
 * draft → submitted → store_reviewed → approved exactly as before; the
 * Department Head's decision sits BESIDE it in department_review_status, so a
 * Submitted department requisition is "waiting for the department head" while
 * that column is pending and "waiting for the Store" once it is approved.
 * Altering a MySQL enum / PostgreSQL check constraint on a live table is not
 * an additive change, and every screen that reads `status` keeps working.
 *
 * `department_role` is the requester's department at the moment they raised
 * it — a Spatie role name, because that is how this project models a
 * department. Stamped rather than re-derived so that a user who later moves
 * department does not drag their old requisitions to the new head's queue.
 *
 * Backfill: existing rows get a level by the same rule the queue has always
 * used — raised by store / admin / management means final, anything else
 * means department. Their department review is left NULL, which every reader
 * treats as "no department step" — so a requisition already on the Store's
 * queue stays there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->string('level', 20)->nullable()->after('mode')->index();
            $table->string('department_role', 60)->nullable()->after('level');

            $table->string('department_review_status', 20)->nullable()->after('submitted_at')->index();
            $table->foreignId('department_checked_by')->nullable()->after('department_review_status')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('department_checked_at')->nullable()->after('department_checked_by');
            $table->text('department_review_note')->nullable()->after('department_checked_at');
        });

        $this->backfillLevels();
    }

    public function down(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_checked_by');
            $table->dropColumn([
                'level',
                'department_role',
                'department_review_status',
                'department_checked_at',
                'department_review_note',
            ]);
        });
    }

    /**
     * Plain query builder, driver-neutral: no enum, no raw SQL, and the role
     * lookup is the same join Spatie itself uses.
     */
    private function backfillLevels(): void
    {
        $storeSide = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', 'App\\Models\\User')
            ->whereIn('roles.name', ['store', 'admin', 'management'])
            ->pluck('model_has_roles.model_id')
            ->unique()
            ->values()
            ->all();

        DB::table('purchase_requisitions')
            ->whereNull('level')
            ->where(function ($q) use ($storeSide) {
                $q->whereNull('created_by');
                if ($storeSide !== []) {
                    $q->orWhereIn('created_by', $storeSide);
                }
            })
            ->update(['level' => 'final']);

        DB::table('purchase_requisitions')
            ->whereNull('level')
            ->update(['level' => 'department']);
    }
};
