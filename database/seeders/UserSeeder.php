<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\User;
use Spatie\Permission\Models\Role;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Fetch roles by name (NO hardcoded IDs)
        $roles = Role::whereIn('name', [
            'merchant',
            'supply_chain',
            'commercial',
            'account',
            'store',
            'admin',
        ])->get()->keyBy('name');

        $users = [
            [
                'name' => 'Merchant User',
                'email' => 'merchant@humanaapparels.com',
                'role' => 'merchant',
            ],
            [
                'name' => 'Supply Chain User',
                'email' => 'supplychain@humanaapparels.com',
                'role' => 'supply_chain',
            ],
            [
                'name' => 'Commercial User',
                'email' => 'commercial@humanaapparels.com',
                'role' => 'commercial',
            ],
            [
                'name' => 'Account User',
                'email' => 'account@humanaapparels.com',
                'role' => 'account',
            ],
            [
                'name' => 'Store User',
                'email' => 'store@humanaapparels.com',
                'role' => 'store',
            ],
            [
                'name' => 'Admin User',
                'email' => 'admin@humanaapparels.com',
                'role' => 'admin',
            ],
        ];

        foreach ($users as $data) {

                $user = User::firstOrCreate(
                    ['email' => $data['email']],
                    [
                        'name' => $data['name'],
                        'password' => Hash::make('password123'),
                        'status' => 1,
                        'remember_token' => Str::random(10),
                    ]
                );

                // Assign Spatie role
                if (isset($roles[$data['role']])) {
                    $user->syncRoles([$roles[$data['role']]]);
                }
            }
    }
}
