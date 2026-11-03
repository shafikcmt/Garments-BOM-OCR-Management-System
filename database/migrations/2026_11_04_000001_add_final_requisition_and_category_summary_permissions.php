<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Two permissions Admin can tick per user on Admin > Users, so a person outside the
 * Store role can do the Store's requisition desk work without a role change:
 *
 *   store.requisition.final       Prepare the Final Requisition: raise the final sheet,
 *                                 see the Requested queue and merge the departments' sheets
 *                                 (the Store side of Purchase Requisition).
 *   store.category_summary.view   Open the Category Summary and its PDF / Excel.
 *
 * Only the permission rows are created; nobody is granted anything here. Additive and
 * safe to re-run.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['store.requisition.final', 'store.category_summary.view'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
