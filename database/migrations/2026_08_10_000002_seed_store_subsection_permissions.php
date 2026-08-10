<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sub-section permissions for General Stock and Buyer / Style Stock.
 *
 * Both modules were a single flat bucket — store.view, store.edit, store.delete
 * and so on — which meant access could only ever be granted to the whole of
 * General Stock at once. There was no way to give somebody Issues without also
 * giving them Receiving, because no permission described the difference. This
 * creates one per sidebar entry and action, so a grant can finally be as narrow
 * as the screen it is about.
 *
 * NOTHING ENFORCES THESE YET. Every route in both modules is still guarded by
 * role:store,admin,management, so creating and granting these changes nobody's
 * access today. They are the data the admin permission matrix draws, and what
 * the route guards will be switched onto in a later phase — the same order the
 * super-admin gate and the perm:/role_or_perm: aliases were added in.
 *
 * The grants mirror what each role can reach TODAY, so that switching the
 * guards later is a no-op rather than a redistribution:
 *
 *   view    store, admin, management   (the route guard's own list)
 *   create  store, admin               (store does the data entry)
 *   edit    admin, management          (mirrors store.edit)
 *   delete  admin, management          (mirrors store.delete)
 *   export  store, admin, management   (anyone who can open a report can
 *                                       already download it)
 *
 * supply_chain is deliberately excluded even though it holds the old flat
 * store.view: the route guard does not admit it to any General Stock screen, so
 * granting these would quietly WIDEN its access the moment enforcement moves.
 *
 * Additive only. No existing permission is renamed, removed or re-granted, and
 * no user's direct grants are touched.
 */
return new class extends Migration
{
    /** Roles that receive each action. */
    private const GRANTS = [
        'view' => ['store', 'admin', 'management'],
        'create' => ['store', 'admin'],
        'edit' => ['admin', 'management'],
        'delete' => ['admin', 'management'],
        'export' => ['store', 'admin', 'management'],
    ];

    /**
     * Sub-section => actions, in sidebar order.
     *
     * Purchase Requisition gets the ordinary five; its existing review,
     * approve and accounts permissions are left exactly as they are.
     * Setup takes only view and edit — there is nothing to create or delete on
     * a settings screen. The two read-only ledgers take view and export.
     */
    private const SECTIONS = [
        // General Stock
        'store.stock_report' => ['view', 'export'],
        'store.items' => ['view', 'create', 'edit', 'delete', 'export'],
        'store.receiving' => ['view', 'create', 'edit', 'delete', 'export'],
        'store.issues' => ['view', 'create', 'edit', 'delete', 'export'],
        'store.requisition' => ['view', 'create', 'edit', 'delete', 'export'],
        'store.setup' => ['view', 'edit'],

        // Buyer / Style Stock
        'material.closing_stock' => ['view', 'export'],
        'material.receiving' => ['view', 'create', 'edit', 'delete', 'export'],
        'material.bulk_issue' => ['view', 'create', 'edit', 'delete', 'export'],
        'material.requisitions' => ['view', 'create', 'edit', 'delete', 'export'],
    ];

    public function up(): void
    {
        $roles = Role::where('guard_name', 'web')->get()->keyBy('name');

        foreach (self::SECTIONS as $section => $actions) {
            foreach ($actions as $action) {
                $permission = Permission::firstOrCreate([
                    'name' => $section.'.'.$action,
                    'guard_name' => 'web',
                ]);

                foreach (self::GRANTS[$action] as $roleName) {
                    $role = $roles->get($roleName);

                    if ($role && ! $role->hasPermissionTo($permission)) {
                        $role->givePermissionTo($permission);
                    }
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $names = [];

        foreach (self::SECTIONS as $section => $actions) {
            foreach ($actions as $action) {
                $names[] = $section.'.'.$action;
            }
        }

        // Deleting the permission drops its role and user pivots with it.
        Permission::whereIn('name', $names)->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
