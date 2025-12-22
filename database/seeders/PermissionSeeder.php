<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Carbon\Carbon;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $permissions = [

            // ===== ORDERS =====
            'orders.view',
            'orders.create',
            'orders.edit',
            'orders.approve',
            'orders.delete',

            // ===== MATERIALS =====
            'materials.view',
            'materials.create',
            'materials.edit',
            'materials.delete',

            // ===== VENDORS =====
            'vendors.view',
            'vendors.create',
            'vendors.edit',
            'vendors.delete',

            // ===== SHIPMENTS =====
            'shipments.view',
            'shipments.create',
            'shipments.edit',
            'shipments.delete',

            // ===== PAYMENTS =====
            'payments.view',
            'payments.create',
            'payments.edit',
            'payments.approve',

            // ===== STORE =====
            'store.view',
            'store.issue',
            'store.return',
            'store.adjust',

            // ===== USERS & ROLES =====
            'users.view',
            'users.create',
            'users.edit',
            'users.delete',
            'roles.manage',

            // ===== AUDIT & REPORT =====
            'audit.view',
            'reports.view',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission, 'guard_name' => 'web'],
                ['created_at' => $now, 'updated_at' => $now]
            );
        }
    }
}
