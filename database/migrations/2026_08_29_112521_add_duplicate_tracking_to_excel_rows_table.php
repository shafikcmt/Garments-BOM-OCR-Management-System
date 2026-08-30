<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remember which worksheet rows were created by duplicating another row.
 *
 * WHY THIS NEEDS THE DATABASE. A duplicated row was previously indistinguishable
 * from its neighbours the moment the page reloaded — any highlight lived only in
 * the DOM. A merchant who duplicated a row, navigated away and came back had no
 * way to find it again. Persisting the fact is the only thing that survives a
 * refresh, pagination, or another session.
 *
 * WHY TWO COLUMNS, when one looks like it would do:
 *
 *   duplicated_from_row_id  permanent lineage. Lets the badge say which row a
 *                           copy came from, and leaves an audit trail of what
 *                           was copied from what.
 *   duplicated_at           the BADGE STATE. The badge shows while this is set;
 *                           dismissing it writes null here and leaves the
 *                           lineage untouched.
 *
 * Either column alone forces a choice between "the merchant can clear the badge"
 * and "the origin is still traceable". Splitting them keeps both.
 *
 * ADDITIVE AND NULLABLE. Existing rows get null in both and behave exactly as
 * before — null means "not a duplicate", which is true of every row that
 * predates this migration. No existing column or value is touched.
 *
 * nullOnDelete rather than cascade: deleting a source row must NOT delete the
 * copies made from it. They are independent rows carrying real work; they just
 * lose the pointer back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('excel_rows', function (Blueprint $table) {
            $table->foreignId('duplicated_from_row_id')
                ->nullable()
                ->after('row_number')
                ->constrained('excel_rows')
                ->nullOnDelete();

            $table->timestamp('duplicated_at')
                ->nullable()
                ->after('duplicated_from_row_id');
        });
    }

    public function down(): void
    {
        Schema::table('excel_rows', function (Blueprint $table) {
            // The FK has to go before the column it sits on.
            $table->dropConstrainedForeignId('duplicated_from_row_id');
            $table->dropColumn('duplicated_at');
        });
    }
};
