<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A section belongs to a department and may have a head.
 *
 * indent_sections is the Issue Setup master the Store has always used
 * (Cutting, Line-04, CAD …) and users.requisition_section_id already points
 * at it, so it IS the section list — no second table. Two columns are added:
 * which department a section sits under (null = a plain issue-register
 * section belonging to nobody, as every row is today) and who heads it.
 *
 * Then, when the master already has rows (a fresh install skips this and
 * the admin builds the list on the settings screen), the Production
 * structure is applied to the rows that exist:
 *
 *   Production   CAD & Sample, Quality, Quilting, Finishing, Technical, Cutting
 *   HARC         its one section, spelled as the company spells it
 *   Maintenance  its one section
 *
 * DUPLICATES. The master carries each of these names twice: a row from the
 * 03-Aug setup that nothing ever referenced, and a row from the 16-Aug Issue
 * import that every issue points at. The row WITH data is kept — verified
 * per name below by counting references, never by assuming the smaller id —
 * and the twin is soft-deleted, but only AFTER every reference it does hold
 * is repointed to the keeper. That order matters: IndentSection soft-deletes
 * and a belongsTo does not load a trashed row, so soft-deleting a section
 * that anything still points at would blank the section on those issues.
 *
 * CAD & Sample: "CAD" and "Sample" become one section. The CAD row with data
 * is renamed and every reference to the other two rows is repointed to it
 * before they are soft-deleted. HRAC: the referenced row is renamed "HARC",
 * the company's spelling, and linked under the HARC department.
 *
 * Renames are visible on old issues — the register reads the section name
 * through the relation, not a snapshot — and were accepted as such.
 *
 * Robeul Islam's account is placed in Cutting, so his requisitions route
 * through its head once one is named.
 */
return new class extends Migration
{
    /** department key => [canonical name => aliases merged into it] */
    private const STRUCTURE = [
        'production' => [
            'CAD & Sample' => ['CAD', 'Sample'],
            'Quality' => ['Quality'],
            'Quilting' => ['Quilting'],
            'Finishing' => ['Finishing'],
            'Technical' => ['Technical'],
            'Cutting' => ['Cutting'],
        ],
        'harc' => [
            'HARC' => ['HRAC', 'HARC'],
        ],
        'maintenance' => [
            'Maintenance' => ['Maintenance'],
        ],
    ];

    /** Every column that points at indent_sections. */
    private const REFERENCES = [
        ['stock_issues', 'indent_section_id'],
        ['purchase_requisitions', 'indent_section_id'],
        ['users', 'requisition_section_id'],
    ];

    public function up(): void
    {
        Schema::table('indent_sections', function (Blueprint $table) {
            $table->string('department_key', 40)->nullable()->after('name')->index();
            $table->foreignId('head_user_id')->nullable()->after('department_key')->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0)->after('head_user_id');
        });

        DB::transaction(function () {
            // An empty master is a fresh install (or the test suite): there
            // is nothing to structure, and the admin will add sections on
            // Admin > Settings > Departments. Only an existing master is
            // consolidated and filled in.
            if (! DB::table('indent_sections')->exists()) {
                return;
            }

            $order = 0;

            foreach (self::STRUCTURE as $department => $sections) {
                foreach ($sections as $canonical => $aliases) {
                    $this->consolidate($department, $canonical, $aliases, ++$order * 10);
                }
            }

            $this->placeUser('cutting@humanaapparels.com', 'Cutting');
        });
    }

    public function down(): void
    {
        Schema::table('indent_sections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('head_user_id');
            $table->dropColumn(['department_key', 'sort_order']);
        });
    }

    /**
     * Keep ONE live row for a section — the most-referenced of every row that
     * carries one of its names — repoint the rest to it, soft-delete them,
     * and file it under its department.
     *
     * @param  list<string>  $aliases
     */
    private function consolidate(string $department, string $canonical, array $aliases, int $sortOrder): void
    {
        $rows = DB::table('indent_sections')
            ->whereNull('deleted_at')
            ->whereIn('name', array_unique(array_merge([$canonical], $aliases)))
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            // Nothing to merge: a fresh install, or a master trimmed by hand.
            DB::table('indent_sections')->insert([
                'name' => $canonical,
                'department_key' => $department,
                'sort_order' => $sortOrder,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        // The keeper is the row with the most references; on a tie, the one
        // already carrying the canonical name, then the oldest.
        $keeper = $rows
            ->sortBy([
                fn ($a, $b) => $this->referenceCount($b->id) <=> $this->referenceCount($a->id),
                fn ($a, $b) => ($b->name === $canonical) <=> ($a->name === $canonical),
                fn ($a, $b) => $a->id <=> $b->id,
            ])
            ->first();

        foreach ($rows as $row) {
            if ($row->id === $keeper->id) {
                continue;
            }

            foreach (self::REFERENCES as [$table, $column]) {
                DB::table($table)->where($column, $row->id)->update([$column => $keeper->id]);
            }

            DB::table('indent_sections')->where('id', $row->id)->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('indent_sections')->where('id', $keeper->id)->update([
            'name' => $canonical,
            'department_key' => $department,
            'sort_order' => $sortOrder,
            'is_active' => true,
            'updated_at' => now(),
        ]);
    }

    private function referenceCount(int $sectionId): int
    {
        $count = 0;

        foreach (self::REFERENCES as [$table, $column]) {
            $count += DB::table($table)->where($column, $sectionId)->count();
        }

        return $count;
    }

    private function placeUser(string $email, string $sectionName): void
    {
        $sectionId = DB::table('indent_sections')
            ->whereNull('deleted_at')->where('name', $sectionName)->value('id');

        if ($sectionId) {
            DB::table('users')->where('email', $email)->whereNull('requisition_section_id')
                ->update(['requisition_section_id' => $sectionId]);
        }
    }
};
