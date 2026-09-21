<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The section a user raises General Stock requisitions FOR.
 *
 * A department is a role (merchant, production …); a SECTION is the finer
 * unit the Store's paper form has always carried — Cutting, Line-04, CAD —
 * and it is an Issue Setup master (indent_sections), not a role. Nothing on
 * users said which section a person belongs to, so a department-level
 * requisition could only offer the whole company's list and number itself
 * "ALL".
 *
 * Nullable, no backfill: a user with no section keeps the open dropdown
 * they have today. Set from Admin > Users. nullOnDelete: removing a section
 * from the master must not delete anyone's account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('requisition_section_id')->nullable()->after('is_department_admin')
                ->constrained('indent_sections')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requisition_section_id');
        });
    }
};
