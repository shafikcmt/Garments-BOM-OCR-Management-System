<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Let the Store role correct a recorded receiving line (Edit in Purchase
 * History).
 *
 * Until now only Admin / Management held store.receiving.edit, so the Store
 * keeper who entered a delivery saw no Edit button and had to ask Admin to fix
 * a wrong qty, price or supplier. The owner asked for Store to edit its own
 * receivings.
 *
 * Edit only. store.receiving.delete stays with Admin / Management, and the
 * controller's guards (qty cannot drop below what was already issued, locked
 * GRN / challan fields) are unchanged. Additive: nothing is removed or renamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = Role::where('name', 'store')->where('guard_name', 'web')->first();
        $permission = Permission::firstOrCreate(['name' => 'store.receiving.edit', 'guard_name' => 'web']);

        if ($role && ! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $role = Role::where('name', 'store')->where('guard_name', 'web')->first();

        if ($role && Permission::where('name', 'store.receiving.edit')->where('guard_name', 'web')->exists()) {
            $role->revokePermissionTo('store.receiving.edit');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
