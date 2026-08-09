<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// General Stock (module A) — the Excel "Consumption" / "Non Stock" sheet
// columns the table was missing.
//
// The existing free-text `department` and `issued_to` columns are KEPT and are
// still written on every save (with the selected master's name), because they
// hold the only record of who indented what before the masters existed, and
// other screens/exports read them. The new *_id columns are the structured
// version; the text columns are the human-readable snapshot.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_issues', function (Blueprint $table) {
            $table->foreignId('indent_section_id')->nullable()->after('department')
                ->constrained('indent_sections')->nullOnDelete();
            $table->foreignId('indent_person_id')->nullable()->after('indent_section_id')
                ->constrained('indent_persons')->nullOnDelete();
            $table->foreignId('issue_approver_id')->nullable()->after('indent_person_id')
                ->constrained('issue_approvers')->nullOnDelete();

            // "Requisition Type" — New / Replace in the reference file.
            $table->string('requisition_type', 50)->nullable()->after('requisition_no');

            // Present on the Consumption sheet but not on Non Stock: which
            // buyer/style the consumable was burnt against. Reporting only —
            // this does NOT link General Stock to the BOM/PO module.
            $table->string('buyer_name')->nullable()->after('issue_approver_id');
            $table->string('style_name')->nullable()->after('buyer_name');
        });
    }

    public function down(): void
    {
        Schema::table('stock_issues', function (Blueprint $table) {
            $table->dropConstrainedForeignId('indent_section_id');
            $table->dropConstrainedForeignId('indent_person_id');
            $table->dropConstrainedForeignId('issue_approver_id');
            $table->dropColumn(['requisition_type', 'buyer_name', 'style_name']);
        });
    }
};
