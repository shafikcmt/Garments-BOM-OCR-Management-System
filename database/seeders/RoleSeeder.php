<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run() {
    $roles = ['admin','merchant','supply_chain','commercial','account','store','gmts'];
    foreach($roles as $role){
        Role::create(['name'=>$role]);
    }
}
}
