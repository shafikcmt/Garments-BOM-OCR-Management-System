<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * "Edit the Store columns on a BOM" — a per-user grant.
 *
 * Workspace column ownership is recorded on excel_headers as a ROLE id, so a
 * column is reachable only by whoever holds the role that owns it. That left no
 * way to give one person the Store columns without making them a Store user
 * everywhere else, which is what the narrow Store roles ran into: a user on
 * Store — General Stock owns no columns at all and can read a BOM but change
 * nothing on it.
 *
 * ExcelFileController::getRoleIds() reads this permission and adds the Store
 * role's id for that user alone.
 *
 * GRANTED TO NO ROLE, deliberately. A role grant would hand the Store columns
 * to everyone who has that role, which is exactly the blunt behaviour this is
 * meant to avoid. It arrives per user, from the Additional Permissions matrix.
 */
return new class extends Migration
{
    private const PERMISSION = 'bom.store_columns.edit';

    public function up(): void
    {
        Permission::firstOrCreate([
            'name' => self::PERMISSION,
            'guard_name' => 'web',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', self::PERMISSION)->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
