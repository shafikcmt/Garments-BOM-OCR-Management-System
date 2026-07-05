<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Adds the dedicated "management" role and grants it the approve-pra
 * permission so management users get their own dashboard and the PRA Approvals
 * area. Additive and idempotent — safe to run on a live database. Management
 * users still need to be added to the approver pool by an admin before a
 * creator can select them for a specific PRA.
 */
return new class extends Migration
{
    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $role = Role::firstOrCreate(
            ['name' => 'management', 'guard_name' => 'web'],
            ['description' => 'Management / Oversight & PRA Approval']
        );

        $approvePra = Permission::where('name', 'approve-pra')->where('guard_name', 'web')->first();
        if ($approvePra) {
            $role->givePermissionTo($approvePra);
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        $role = Role::where('name', 'management')->where('guard_name', 'web')->first();
        // Only remove the role when no users are assigned, to avoid orphaning
        // accounts on rollback.
        if ($role && $role->users()->count() === 0) {
            $role->delete();
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
