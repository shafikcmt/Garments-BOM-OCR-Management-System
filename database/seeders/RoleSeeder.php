<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [
                'name' => 'merchant',
                'description' => 'Merchant / Order & Buyer Management',
            ],
            [
                'name' => 'supply_chain',
                'description' => 'Supply Chain Operations & Tracking',
            ],
            [
                'name' => 'commercial',
                'description' => 'Commercial & Shipping Documentation',
            ],
            [
                'name' => 'account',
                'description' => 'Accounts & Payment Management',
            ],
            [
                'name' => 'store',
                'description' => 'Store & Inventory Management',
            ],
            [
                'name' => 'admin',
                'description' => 'System Administration & Full Access',
            ],
            [
                'name' => 'management',
                'description' => 'Management / Oversight & PRA Approval',
            ],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(
                [
                    'name' => $role['name'],
                    'guard_name' => 'web',
                ],
                [
                    'description' => $role['description'],
                ]
            );
        }
    }
}
