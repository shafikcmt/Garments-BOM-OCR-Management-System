<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permissions for General Stock purchasing (Comparative Statement and Internal
 * PO), and the role that works them.
 *
 *   store.purchase_cs.view      open the To Purchase queue and the CS list
 *   store.purchase_cs.create    raise and edit a CS (Prepared by)
 *   store.purchase_cs.review    check a CS (the Manager - SCM box)
 *   store.purchase_cs.approve   final approval of a CS (the Approved By box)
 *   store.purchase_po.view      open the Internal PO list
 *   store.purchase_po.create    make a PO from an approved CS
 *   store.purchase_po.approve   authorise a PO (the Authorised Signatory box)
 *
 * The role is "Supply Chain General": people who only run the general stock
 * purchase desk and must not see buyer-order Supply Chain screens. It gets
 * view + create only. Review and approve are NOT given to the role; Admin
 * ticks them per person (Admin > Users > Portal Access) so a junior can never
 * approve what they prepared.
 *
 * The live server may already have this role under a name typed on Admin >
 * Roles ("Supply chain general"). A role whose name reads the same once case,
 * spaces and underscores are ignored is reused, so no duplicate is created.
 * Otherwise `supply_chain_general` is created.
 *
 * ADDITIVE ONLY: findOrCreate and givePermissionTo. Nothing is removed from
 * anyone, no department mapping is touched.
 */
return new class extends Migration
{
    public const ROLE = 'supply_chain_general';

    private const PERMISSIONS = [
        'store.purchase_cs.view',
        'store.purchase_cs.create',
        'store.purchase_cs.review',
        'store.purchase_cs.approve',
        'store.purchase_po.view',
        'store.purchase_po.create',
        'store.purchase_po.approve',
    ];

    private const ROLE_PERMISSIONS = [
        'store.purchase_cs.view',
        'store.purchase_cs.create',
        'store.purchase_po.view',
        'store.purchase_po.create',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () {
            foreach (self::PERMISSIONS as $name) {
                Permission::findOrCreate($name, 'web');
            }

            $role = Role::where('guard_name', 'web')->get()
                ->first(fn (Role $r) => self::normalise($r->name) === 'supplychaingeneral')
                ?? Role::findOrCreate(self::ROLE, 'web');

            foreach (self::ROLE_PERMISSIONS as $name) {
                $role->givePermissionTo($name);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // The role is left alone if anybody holds it: rolling back must never
        // lock a real account out. Only the permissions this created go.
        $role = Role::where('name', self::ROLE)->where('guard_name', 'web')->first();

        if ($role && $role->users()->doesntExist()) {
            $role->delete();
        }

        Permission::whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private static function normalise(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($name));
    }
};
