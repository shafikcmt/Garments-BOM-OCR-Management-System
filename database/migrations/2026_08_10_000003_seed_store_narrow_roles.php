<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The two narrow Store roles.
 *
 * The `store` role grants General Stock AND Buyer / Style Stock together, so a
 * store user who only ever touches one of them has to be given both. These are
 * ready-made alternatives scoped to one module each, so the common case does
 * not need a permission set hand-built per person.
 *
 * They work through the guards Phase 3 already put on routes/store.php — the
 * store area now accepts a permission as well as a role — so nothing about
 * enforcement changes here. Verified before creating them: a user holding only
 * store_general_stock gets 200 on every General Stock screen and 403 on every
 * Buyer / Style Stock screen, and store_material_stock the exact reverse.
 *
 * The permission sets are SELECTED, not listed: every three-part store.* name
 * for General Stock, every material.* name for Buyer / Style. A sub-section
 * permission added later is picked up by re-running nothing — the shape of the
 * name is the membership rule, which is the same rule the matrix groups by.
 *
 * Purely additive. No existing role's permissions are touched and no user is
 * reassigned; these are new options, and moving anyone onto one is a separate
 * decision per person.
 */
return new class extends Migration
{
    /**
     * role name => [description, permission selector]
     */
    private const ROLES = [
        'store_general_stock' => 'General Stock only',
        'store_material_stock' => 'Buyer / Style Stock only',
    ];

    public function up(): void
    {
        // General Stock: the three-part store.* names — store.issues.view and
        // the like. The two-part legacy ones (store.view, store.edit) are
        // module-wide and deliberately excluded: they are what the broad store
        // role is built from, and granting them here would undo the narrowing.
        $generalStock = Permission::query()
            ->where('guard_name', 'web')
            ->where('name', 'like', 'store.%')
            ->whereRaw("LENGTH(name) - LENGTH(REPLACE(name, '.', '')) = 2")
            ->get();

        $materialStock = Permission::query()
            ->where('guard_name', 'web')
            ->where('name', 'like', 'material.%')
            ->get();

        $this->makeRole('store_general_stock', $generalStock);
        $this->makeRole('store_material_stock', $materialStock);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Only ever removes the roles this created. Deleting a role drops its
        // permission and user pivots with it, so anyone left on one of these
        // would be left with no role at all — which is why the rollback is
        // guarded on the role having no users.
        foreach (array_keys(self::ROLES) as $name) {
            $role = Role::where('name', $name)->where('guard_name', 'web')->first();

            if ($role && $role->users()->count() === 0) {
                $role->delete();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function makeRole(string $name, $permissions): void
    {
        $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);

        // syncPermissions rather than give: re-running lands on exactly this
        // set rather than accumulating.
        $role->syncPermissions($permissions);
    }
};
