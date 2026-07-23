<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
            UserSeeder::class,
            ExcelHeaderSeeder::class,
            // Grants store.edit / store.delete to Admin and Management. The
            // store controllers abort 403 without it, so a fresh install that
            // skipped this would lock Admin out of its own corrections.
            StoreIssueControlPermissionSeeder::class,
        ]);
    }
}