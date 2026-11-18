<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Vendor Master workflow permissions. Additive only (findOrCreate + givePermissionTo):
 * nobody loses an existing grant. The admin role passes every check through Gate::before,
 * so it needs no explicit grant. Everything else is assigned on Admin > Roles.
 *
 *   vendors.approve       approve / reject / start review of a vendor
 *   vendors.block         block, inactivate, reactivate a vendor
 *   vendors.bank.view     see bank accounts (masked: last 4 digits only)
 *   vendors.bank.manage   raise a bank change request (add / change / remove)
 *   vendors.bank.approve  approve / reject a bank change request (never the requester)
 */
return new class extends Migration
{
    private const GRANTS = [
        'vendors.approve' => [],
        'vendors.block' => [],
        'vendors.bank.view' => ['account', 'commercial', 'supply_chain'],
        'vendors.bank.manage' => ['account'],
        'vendors.bank.approve' => [],
    ];

    public function up(): void
    {
        DB::transaction(function () {
            foreach (self::GRANTS as $name => $roles) {
                $permission = Permission::findOrCreate($name, 'web');

                foreach ($roles as $roleName) {
                    Role::where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', array_keys(self::GRANTS))->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
