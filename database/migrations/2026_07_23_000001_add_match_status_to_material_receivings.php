<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Independent" receiving — material that physically arrived but whose paperwork
// does not yet match any PO / PI / Invoice, so there is no Booking PO or BOM row
// to book it against.
//
// Deliberately a NEW column rather than a third value on `source_type`:
// source_type already means booking | internal_po and MaterialStockLedgerService
// splits Booking Receive vs Internal Receive on it. Overloading it would have
// silently changed every closing-stock figure in the system.
//
// Purely additive and nullable. Existing rows keep match_status = NULL, which
// reads as "was never independent" — the normal, PO-linked case.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_receivings', function (Blueprint $table) {
            // null = ordinary PO-linked receiving (every existing row)
            // independent = arrived, not yet matched to a PO/PI/Invoice
            // linked = was independent, since attached to a real PO
            $table->string('match_status')->nullable()->after('source_type');

            // Audit for the re-match: who attached it to a PO, and when.
            $table->timestamp('matched_at')->nullable()->after('match_status');
            $table->foreignId('matched_by')->nullable()->after('matched_at')
                ->constrained('users')->nullOnDelete();

            // Receiving History filters on this to surface what still needs
            // matching, so it is worth an index rather than a table scan.
            $table->index(['match_status', 'receive_date']);
        });
    }

    public function down(): void
    {
        Schema::table('material_receivings', function (Blueprint $table) {
            $table->dropIndex(['match_status', 'receive_date']);
            $table->dropConstrainedForeignId('matched_by');
            $table->dropColumn(['match_status', 'matched_at']);
        });
    }
};
