<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How a worksheet row came into being.
 *
 * Row deletion asks "is this a row somebody just added and has not worked on
 * yet". Until now the second half was answered from excel_file_change_logs —
 * no log entries meant nobody had edited it. That is wrong for the rows that
 * matter most: the IMPORT writes rows and cells straight into the database and
 * creates no change-log entries at all, so a row that arrived from the upload
 * carrying forty-two filled cells looked exactly like a blank row created ten
 * seconds ago. Those rows were deletable.
 *
 * Nothing about a row's contents or timestamps distinguishes the two reliably,
 * so this records it at creation instead of inferring it afterwards.
 *
 * THE DEFAULT IS THE SAFE ONE. 'imported' is not deletable, so a future code
 * path that creates rows and forgets to say how produces rows that cannot be
 * deleted, rather than rows that can. Every existing row is backfilled to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('excel_rows', function (Blueprint $table) {
            $table->string('origin', 20)
                ->default('imported')
                ->after('row_number');
        });

        // Explicit, not merely relying on the column default: existing rows
        // were written before the default existed.
        DB::table('excel_rows')->update(['origin' => 'imported']);

        // The one thing that IS known about history: a row carrying duplication
        // lineage was made by the duplicate action, whenever that was. Those
        // are the rows this feature was built for, so they keep their
        // eligibility rather than being frozen by the backfill.
        DB::table('excel_rows')
            ->whereNotNull('duplicated_from_row_id')
            ->update(['origin' => 'duplicated']);
    }

    public function down(): void
    {
        Schema::table('excel_rows', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
    }
};
