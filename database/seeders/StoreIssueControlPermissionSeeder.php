<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Adds the two store correction permissions and grants them to the roles that
 * are allowed to fix a recorded issue.
 *
 * Deliberately ADDITIVE (firstOrCreate + givePermissionTo, never syncPermissions)
 * so it is safe to run against a live database — it cannot strip a permission an
 * admin assigned by hand through the Roles screen.
 *
 * Business rule: a Store user records a bulk issue but may not edit or delete it
 * afterwards, because every change recomputes closing stock. Corrections are an
 * Admin / Management responsibility.
 */
class StoreIssueControlPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = ['store.edit', 'store.delete'];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // Management can review and correct store records but had no store
        // permissions at all, so it needs view alongside the two new ones.
        $grants = [
            'admin' => ['store.view', 'store.edit', 'store.delete'],
            'management' => ['store.view', 'store.edit', 'store.delete'],
        ];

        foreach ($grants as $roleName => $names) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

            if (! $role) {
                continue;
            }

            foreach ($names as $name) {
                if (! $role->hasPermissionTo($name)) {
                    $role->givePermissionTo($name);
                }
            }
        }
    }
}
