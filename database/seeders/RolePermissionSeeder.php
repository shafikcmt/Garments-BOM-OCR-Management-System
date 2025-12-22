<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Fetch all permissions once
        $permissions = Permission::all()->keyBy('name');

        $rolePermissions = [

            // ===== ADMIN =====
            'admin' => [
                '*', // full access
            ],

            // ===== MERCHANT =====
            'merchant' => [
                'orders.view',
                'orders.create',
                'orders.edit',
                'materials.view',
                'vendors.view',
                'reports.view',
            ],

            // ===== SUPPLY CHAIN =====
            'supply_chain' => [
                'orders.view',
                'materials.view',
                'materials.create',
                'materials.edit',
                'vendors.view',
                'shipments.view',
                'shipments.create',
                'shipments.edit',
                'store.view',
                'reports.view',
            ],

            // ===== COMMERCIAL =====
            'commercial' => [
                'orders.view',
                'shipments.view',
                'payments.view',
                'payments.create',
                'payments.edit',
                'reports.view',
            ],

            // ===== ACCOUNT =====
            'account' => [
                'orders.view',
                'payments.view',
                'payments.approve',
                'reports.view',
            ],

            // ===== STORE =====
            'store' => [
                'orders.view',
                'store.view',
                'store.issue',
                'store.return',
                'store.adjust',
            ],
        ];

        foreach ($rolePermissions as $roleName => $permissionNames) {

            $role = Role::where('name', $roleName)->first();

            if (! $role) {
                continue;
            }

            // ADMIN gets everything
            if (in_array('*', $permissionNames)) {
                $role->syncPermissions(Permission::all());
                continue;
            }

            $assignPermissions = [];

            foreach ($permissionNames as $permName) {
                if (isset($permissions[$permName])) {
                    $assignPermissions[] = $permissions[$permName];
                }
            }

            $role->syncPermissions($assignPermissions);
        }
    }
}
