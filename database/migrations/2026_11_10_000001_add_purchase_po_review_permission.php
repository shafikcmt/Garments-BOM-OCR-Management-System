<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Internal PO has two approval points: Checked By, then Authorised
 * Signatory. "Authorised" already had store.purchase_po.approve; this adds
 * store.purchase_po.review for the Checked By box.
 *
 * Given per person on Admin > Users > Portal Access ("Internal PO: Check"),
 * never to the Supply Chain General role itself. ADDITIVE ONLY.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        DB::transaction(fn () => Permission::findOrCreate('store.purchase_po.review', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $p = Permission::where('name', 'store.purchase_po.review')->where('guard_name', 'web')->first();
        if ($p && ! DB::table('role_has_permissions')->where('permission_id', $p->id)->exists()
            && ! DB::table('model_has_permissions')->where('permission_id', $p->id)->exists()) {
            $p->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
