<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Store — General Stock keeps View, Create/Entry and Export, and nothing else.
 *
 * The role was built to carry a whole department's worth of rights at once,
 * which meant a new General Stock user could correct or remove a recorded
 * movement from their first day. View and Create/Entry are what the job needs
 * by default; correcting a record that other departments already read is a
 * decision someone should make per person.
 *
 * Two kinds of right are removed, for the same reason from opposite ends:
 *
 *   edit / delete    changing a record that is already recorded, which
 *                    recomputes closing stock other departments read.
 *   approve /        moving a requisition through the purchase workflow.
 *   accounts /       More consequential than a correction, not less, and they
 *   review           were sitting in the default bundle unnoticed.
 *
 * Export stays. It reads what the user can already see and writes nothing.
 *
 * SAFE ONLY BECAUSE OF PHASE B. The backfill (2026_08_11_000002) copied every
 * role-derived permission down to a direct grant, so the one current holder
 * already owns all nine of these directly. Removing them from the role costs
 * them nothing. Run this without that backfill in place and it would strip the
 * correction rights of every holder — which is why the assertion at the end
 * checks effective access rather than trusting the reasoning above.
 *
 * A pleasant side effect: the matrix stops lying. Their Edit/Delete now shows
 * as "granted to this user" in blue and editable, which is what it actually is
 * after the backfill, instead of a locked grey tick claiming it comes with the
 * role.
 *
 * Only store_general_stock is touched. store_material_stock keeps its own
 * edit/delete for now — same argument applies to it, but it is a separate
 * decision about a different team.
 */
return new class extends Migration
{
    private const ROLE = 'store_general_stock';

    /** app_settings key recording exactly what was removed, so down() is exact. */
    private const RECEIPT_KEY = 'general_stock_role_edit_delete_removed';

    /**
     * Workflow-stage rights that are not corrections and so are not caught by
     * the .edit / .delete suffix rule, but are no more a default than those
     * are. Named rather than pattern-matched: each one is a deliberate choice,
     * and a future permission ending in .approve should not be swept up by
     * this migration without somebody deciding it should be.
     */
    private const APPROVAL_STAGE = [
        'store.requisition.approve',
        'store.requisition.accounts',
        'store.requisition.review',
    ];

    public function up(): void
    {
        DB::transaction(function () {
            $before = $this->effectivePermissions();

            $role = Role::where('name', self::ROLE)->first();

            if (! $role) {
                return;
            }

            $removed = $role->permissions
                ->pluck('name')
                ->filter(fn (string $n) => str_ends_with($n, '.edit')
                    || str_ends_with($n, '.delete')
                    || in_array($n, self::APPROVAL_STAGE, true))
                ->sort()
                ->values();

            foreach ($removed as $name) {
                $role->revokePermissionTo($name);
            }

            DB::table('app_settings')->updateOrInsert(
                ['key' => self::RECEIPT_KEY],
                ['value' => json_encode($removed->all()), 'created_at' => now(), 'updated_at' => now()]
            );

            $this->assertUnchanged($before);
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $before = $this->effectivePermissions();

            $row = DB::table('app_settings')->where('key', self::RECEIPT_KEY)->first();
            $role = Role::where('name', self::ROLE)->first();

            if (! $row || ! $role) {
                return;
            }

            foreach (json_decode($row->value, true) ?: [] as $name) {
                $role->givePermissionTo($name);
            }

            DB::table('app_settings')->where('key', self::RECEIPT_KEY)->delete();

            $this->assertUnchanged($before);
        });
    }

    /** @return array<int, list<string>> */
    private function effectivePermissions(): array
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $out = [];

        foreach (User::orderBy('id')->get() as $user) {
            $out[$user->id] = $user->getAllPermissions()->pluck('name')->sort()->values()->all();
        }

        return $out;
    }

    /**
     * The whole safety case in one check: no user's effective access may move.
     *
     * A holder who was relying on the role rather than a direct grant shows up
     * here as a loss, and the throw rolls the change back rather than leaving
     * somebody quietly unable to correct their own records.
     */
    private function assertUnchanged(array $before): void
    {
        $after = $this->effectivePermissions();

        foreach ($before as $id => $names) {
            $now = $after[$id] ?? [];

            if ($names !== $now) {
                throw new RuntimeException(sprintf(
                    'Aborted: effective permissions changed for user %d. Gained [%s], lost [%s].',
                    $id,
                    implode(', ', array_diff($now, $names)) ?: 'none',
                    implode(', ', array_diff($names, $now)) ?: 'none'
                ));
            }
        }
    }
};
