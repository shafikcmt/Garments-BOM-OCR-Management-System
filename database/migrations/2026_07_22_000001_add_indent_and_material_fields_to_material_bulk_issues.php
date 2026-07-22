<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Align material_bulk_issues with the Excel "Bulk Issuing" register. Purely
// additive + nullable: no existing column is renamed or re-typed, and the
// four-way split / stock ledger keep working unchanged.
//
//  - material_name / gmts_color_name / art_no: identity already resolvable from
//    the BOM row (BookingPoSourceService), just not stored on the issue before.
//  - indent_section / indent_person / requisition_number: the indent header the
//    Excel register carries per issue. Season is already `season_name`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_bulk_issues', function (Blueprint $table) {
            // Identity columns copied from the Booking PO / BOM row (auto-filled).
            $table->string('material_name')->nullable()->after('style_name');
            $table->string('gmts_color_name')->nullable()->after('material_description');
            $table->string('art_no')->nullable()->after('gmts_color_name');

            // Indent header — entered by Store per issue.
            $table->string('indent_section')->nullable()->after('material_requisition_id');
            $table->string('indent_person')->nullable()->after('indent_section');
            $table->string('requisition_number')->nullable()->after('indent_person');
        });
    }

    public function down(): void
    {
        Schema::table('material_bulk_issues', function (Blueprint $table) {
            $table->dropColumn([
                'material_name',
                'gmts_color_name',
                'art_no',
                'indent_section',
                'indent_person',
                'requisition_number',
            ]);
        });
    }
};
