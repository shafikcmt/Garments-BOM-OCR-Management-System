<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Authorship on the two template tables.
 *
 * WHY, and why now. booking_instructions and email_templates hold content that
 * prints on POs and goes out to suppliers, and until now they recorded only
 * created_at / updated_at — no author. If a wrong instruction appeared on a PO
 * there was no way to say who wrote it. That is tolerable while a single super
 * admin is the only person who can edit them; it stops being tolerable the
 * moment a second department can, which is the next step. So this lands FIRST,
 * as the precondition for widening access, not as a follow-up to it.
 *
 * Existing rows stay null on purpose. Their author is genuinely unknown and
 * inventing one would make the column a liability rather than a record.
 *
 * nullOnDelete: deleting a user must never delete the instruction they wrote.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = ['booking_instructions', 'email_templates'];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            if (! Schema::hasTable($name)) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) use ($name) {
                if (! Schema::hasColumn($name, 'created_by')) {
                    $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                }

                if (! Schema::hasColumn($name, 'updated_by')) {
                    $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            if (! Schema::hasTable($name)) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) use ($name) {
                foreach (['created_by', 'updated_by'] as $column) {
                    if (Schema::hasColumn($name, $column)) {
                        $table->dropForeign([$column]);
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
