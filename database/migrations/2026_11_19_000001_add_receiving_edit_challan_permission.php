<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * store.receiving.edit_challan — lets a user correct the Challan No of a
 * recorded receiving from the Edit Receiving Line dialog.
 *
 * Admin has it through Gate::before. No role is given it here: Admin ticks it
 * for the users (or roles) that should have it, under General Stock ->
 * Receiving in the permission matrix. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'store.receiving.edit_challan', 'guard_name' => 'web']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'store.receiving.edit_challan')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
