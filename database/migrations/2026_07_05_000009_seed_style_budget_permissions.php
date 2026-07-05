<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Permissions for the style budget feature:
 *  - manage-style-budgets : set/edit budgets (admin + merchant/merchandising).
 *  - override-style-budget : create a PRA past a style's budget (admin only;
 *    can be granted to others from the role screen).
 * Additive and idempotent — safe on a live database.
 */
return new class extends Migration
{
    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['manage-style-budgets', 'override-style-budget'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $admin = Role::where('name', 'admin')->where('guard_name', 'web')->first();
        if ($admin) {
            $admin->givePermissionTo(['manage-style-budgets', 'override-style-budget']);
        }

        $merchant = Role::where('name', 'merchant')->where('guard_name', 'web')->first();
        if ($merchant) {
            $merchant->givePermissionTo('manage-style-budgets');
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (['manage-style-budgets', 'override-style-budget'] as $name) {
            $permission = Permission::where('name', $name)->where('guard_name', 'web')->first();
            $permission?->delete();
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
