<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The roles a HARC or Maintenance user signs in with, and the requisition
 * rights every Production-side role needs.
 *
 * `harc` and `maintenance` are new: one per department, mapped to it by
 * 2026_09_23_000001, holding the same two rights as requisition_raiser so
 * an account made in either department can raise a requisition the moment
 * it exists, with nothing further to configure.
 *
 * `production` held only an OCR right; `cutting` held nothing. Both gain
 * the same two rights, additively — the section heads and Production's
 * own head will be created on these roles and must be able to open the
 * requisition module at once. Nothing is taken from anyone.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['store.requisition.view', 'store.requisition.create'];

    private const ROLES = ['harc', 'maintenance', 'production', 'cutting'];

    public function up(): void
    {
        DB::transaction(function () {
            $permissions = array_map(fn ($name) => Permission::findOrCreate($name, 'web'), self::PERMISSIONS);

            foreach (self::ROLES as $name) {
                $role = Role::findOrCreate($name, 'web');

                foreach ($permissions as $permission) {
                    $role->givePermissionTo($permission);
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Only the two NEW roles go, and only while nobody holds them. */
    public function down(): void
    {
        foreach (['harc', 'maintenance'] as $name) {
            $role = Role::where('name', $name)->where('guard_name', 'web')->first();

            if ($role && $role->users()->doesntExist()) {
                $role->delete();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
