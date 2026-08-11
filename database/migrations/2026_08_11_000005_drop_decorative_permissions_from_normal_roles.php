<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Tidy-up, not a security change: eight permissions that gate nothing are
 * removed from four ordinary roles.
 *
 * Every one of these was audited and found to have no reference anywhere in
 * app/, routes/ or resources/views/ — they appear only in the seeders that
 * created them and in the catalogue that labels them. Removing them therefore
 * changes no access whatsoever, today or after any of the earlier phases. What
 * it changes is the permission matrix, which currently shows a locked grey tick
 * implying a right the role does not actually confer.
 *
 * Deliberately NOT included, because they gate real behaviour and are core to
 * the role rather than corrections:
 *
 *   store.issue            Bulk Issuing — the store role's actual job.
 *   manage-style-budgets   the Style Budgets screen for merchant.
 *
 * Also untouched: admin and management, whose broad edit/delete IS their
 * function, and store_general_stock / store_material_stock, handled by
 * 2026_08_11_000003 and 2026_08_11_000004.
 *
 * A note for whoever reads this after building one of these features. Several
 * of these names look like placeholders for work not yet done — store.adjust,
 * store.return, payments.approve. If that feature later ships, the permission
 * should be granted to whoever needs it rather than assumed to be back on the
 * role: under the model these phases are moving towards, an unbuilt feature's
 * permission is exactly the kind of thing that should not be a default.
 */
return new class extends Migration
{
    /** app_settings key recording exactly what was removed, so down() is exact. */
    private const RECEIPT_KEY = 'decorative_role_permissions_removed';

    /**
     * role => permissions to drop. Named explicitly rather than pattern
     * matched: each was individually confirmed to gate nothing, and a rule
     * like "every .edit" would sweep up rights that do.
     */
    private const REMOVALS = [
        'supply_chain' => ['materials.edit', 'shipments.edit'],
        'commercial' => ['payments.edit'],
        'account' => ['payments.approve', 'store.requisition.accounts'],
        'store' => ['store.requisition.review', 'store.adjust', 'store.return'],
    ];

    public function up(): void
    {
        DB::transaction(function () {
            $before = $this->effectivePermissions();

            $receipt = [];

            foreach (self::REMOVALS as $roleName => $names) {
                $role = Role::where('name', $roleName)->first();

                if (! $role) {
                    continue;
                }

                $held = $role->permissions->pluck('name');

                foreach ($names as $name) {
                    // Only record what was actually there, so down() cannot add
                    // a permission the role never had.
                    if (! $held->contains($name)) {
                        continue;
                    }

                    $role->revokePermissionTo($name);
                    $receipt[$roleName][] = $name;
                }
            }

            DB::table('app_settings')->updateOrInsert(
                ['key' => self::RECEIPT_KEY],
                ['value' => json_encode($receipt), 'created_at' => now(), 'updated_at' => now()]
            );

            $this->assertUnchanged($before);
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $before = $this->effectivePermissions();

            $row = DB::table('app_settings')->where('key', self::RECEIPT_KEY)->first();

            if (! $row) {
                return;
            }

            foreach (json_decode($row->value, true) ?: [] as $roleName => $names) {
                $role = Role::where('name', $roleName)->first();

                if (! $role) {
                    continue;
                }

                foreach ($names as $name) {
                    $role->givePermissionTo($name);
                }
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
     * The claim is that this changes nothing. This is what checks it, and a
     * holder who was relying on the role rather than a direct grant rolls the
     * whole migration back rather than quietly losing a right.
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
