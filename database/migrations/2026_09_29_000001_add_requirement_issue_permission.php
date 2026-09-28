<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * store.requirement_issue.view — the Requirement vs Issue view.
 *
 * Granted DIRECTLY to the current Store department head only (permissions
 * in this project are per-user grants), never to a role. Nobody else is
 * touched; with no Store head set, only the permission row is created.
 * The controller also checks the user is still the Store head, so a grant
 * left behind when the head changes opens nothing.
 *
 * Additive and safe to re-run: findOrCreate, and a grant already held is a
 * no-op.
 */
return new class extends Migration
{
    private const PERMISSION = 'store.requirement_issue.view';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () {
            $permission = Permission::findOrCreate(self::PERMISSION, 'web');

            $headId = DB::table('departments')->where('key', 'store')->value('head_user_id');
            $head = $headId ? User::find($headId) : null;

            if ($head) {
                $head->givePermissionTo($permission);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', self::PERMISSION)->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
