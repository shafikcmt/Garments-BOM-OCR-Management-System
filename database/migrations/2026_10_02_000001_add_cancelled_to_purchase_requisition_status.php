<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds "cancelled" to purchase_requisitions.status — a requisition closed for
 * good (Step 3c). The column was created as an ENUM, so the allowed values
 * live in a different place on each database:
 *
 *   MySQL       the ENUM type itself        → MODIFY the column
 *   PostgreSQL  a CHECK constraint           → drop it, add it back wider
 *   SQLite      a CHECK in the table DDL     → Laravel rebuilds the table
 *
 * On PostgreSQL the constraint is found by what it checks, not by an assumed
 * name, so a differently named constraint on live is still the one replaced.
 *
 * Existing rows are untouched. down() refuses while any row is cancelled,
 * because narrowing the column would otherwise fail or lose data.
 */
return new class extends Migration
{
    private const OLD = ['draft', 'submitted', 'store_reviewed', 'approved', 'purchase_action_taken'];

    public function up(): void
    {
        $this->allow([...self::OLD, 'cancelled']);
    }

    public function down(): void
    {
        $cancelled = DB::table('purchase_requisitions')->where('status', 'cancelled')->count();

        if ($cancelled > 0) {
            throw new RuntimeException(
                "Cannot roll back: {$cancelled} purchase requisition(s) are cancelled. "
                .'Their status would have no allowed value. Resolve them first.'
            );
        }

        $this->allow(self::OLD);
    }

    /** @param list<string> $values */
    private function allow(array $values): void
    {
        $quoted = implode(', ', array_map(fn ($v) => "'".$v."'", $values));

        switch (DB::getDriverName()) {
            case 'mysql':
            case 'mariadb':
                DB::statement("ALTER TABLE purchase_requisitions MODIFY status ENUM({$quoted}) NOT NULL DEFAULT 'draft'");
                break;

            case 'pgsql':
                // Every CHECK constraint on the table that mentions "status".
                $constraints = DB::select(
                    "SELECT c.conname FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid
                     WHERE t.relname = 'purchase_requisitions' AND c.contype = 'c'
                       AND pg_get_constraintdef(c.oid) LIKE '%status%'"
                );

                foreach ($constraints as $constraint) {
                    DB::statement('ALTER TABLE purchase_requisitions DROP CONSTRAINT "'.$constraint->conname.'"');
                }

                DB::statement("ALTER TABLE purchase_requisitions ADD CONSTRAINT purchase_requisitions_status_check CHECK (status::text IN ({$quoted}))");
                break;

            default:
                Schema::table('purchase_requisitions', function (Blueprint $table) use ($values) {
                    $table->enum('status', $values)->default('draft')->change();
                });
        }
    }
};
