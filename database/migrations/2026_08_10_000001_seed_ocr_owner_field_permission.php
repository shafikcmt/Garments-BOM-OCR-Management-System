<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The Production role's one permission: update the Owner field on OCR data.
 *
 * Production has carried a role and no permissions at all since it was created,
 * so its single user can reach nothing that is permission-checked. This gives it
 * exactly one thing and nothing else.
 *
 * NOTHING ENFORCES THIS YET, and that is on purpose. No screen checks it, so
 * granting it changes what nobody can do today. What "the Owner field" refers to
 * is still open — there is no OCR column named Owner among the 133 in
 * excel_headers; the only owner concept is excel_headers.owner_role_id, which
 * describes who owns a column rather than being data on a row. The permission is
 * created now so the admin UI has something real to show and grant, and the
 * screen it guards is decided when enforcement is fitted.
 *
 * Additive: no other role, user or permission is touched.
 */
return new class extends Migration
{
    private const PERMISSION = 'ocr.update_owner_field';

    private const ROLE = 'production';

    public function up(): void
    {
        $permission = Permission::firstOrCreate([
            'name' => self::PERMISSION,
            'guard_name' => 'web',
        ]);

        $role = Role::where('name', self::ROLE)->where('guard_name', 'web')->first();

        // A missing role is not an error worth failing a deploy over — the
        // permission still exists and can be granted by hand.
        if ($role && ! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', self::PERMISSION)->where('guard_name', 'web')->delete();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
