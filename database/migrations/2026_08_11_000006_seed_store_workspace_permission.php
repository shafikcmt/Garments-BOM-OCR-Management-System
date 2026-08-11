<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Workspace stops being something every Store user reaches by default.
 *
 * The Store workspace opens BOM files. Until now /store/workspace sat inside
 * the store area's entry guard, which asks "may this person enter Store at
 * all" — so holding any single General Stock permission, such as
 * store.items.view, was enough. That guard was never meant to answer "may this
 * person use Workspace", and nothing else asked.
 *
 * store.workspace.view now answers it. The name follows the existing
 * module.section.action convention, so PermissionCatalog files it under
 * General Stock / Workspace on its own and it appears in the per-user matrix
 * with no code change — the toggle panel added alongside this is a focused
 * view of the same grant, not a second system.
 *
 * DELIBERATE ACCESS REMOVAL, unlike the migrations before it. Those asserted
 * that nobody's effective access moved; this one removes access on purpose, so
 * its safety check is an ALLOWLIST instead: after this runs, exactly the
 * holders of store, management and admin keep Workspace, and anyone who
 * reached it only through a narrow role loses it. Verified on dev before
 * applying — one user is affected, on store_general_stock, and that is the
 * intent.
 *
 * Not granted to store_general_stock or store_material_stock. A Store Admin
 * hands it to those users one at a time from the Workspace Access panel.
 */
return new class extends Migration
{
    private const PERMISSION = 'store.workspace.view';

    /** Roles that carry Workspace as part of the job. */
    private const GRANT_TO = ['store', 'management', 'admin'];

    public function up(): void
    {
        DB::transaction(function () {
            $permission = Permission::findOrCreate(self::PERMISSION, 'web');

            foreach (self::GRANT_TO as $roleName) {
                Role::where('name', $roleName)->first()?->givePermissionTo($permission);
            }

            $this->assertAllowlist();
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            // Removing the permission drops every grant of it, role and direct
            // alike, and the route guard falls back to the entry guard it had
            // before — which is exactly the old behaviour.
            Permission::where('name', self::PERMISSION)->first()?->delete();

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }

    /**
     * The safety check for a change that is meant to take access away.
     *
     * Equality would fail here by design, so instead: everyone holding one of
     * the three roles must end up with the permission, and nobody else may
     * have picked it up. A narrow-role user quietly retaining Workspace would
     * mean the grant leaked somewhere it was not intended.
     */
    private function assertAllowlist(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (User::all() as $user) {
            $shouldHave = false;

            foreach (self::GRANT_TO as $roleName) {
                if ($user->hasRole($roleName)) {
                    $shouldHave = true;
                    break;
                }
            }

            $does = $user->can(self::PERMISSION);

            // Super admin answers true to everything through Gate::before, so
            // it can only ever be on the allowed side of this check.
            if ($shouldHave && ! $does) {
                throw new RuntimeException(sprintf(
                    'Aborted: %s holds an allowed role but did not receive %s.',
                    $user->name,
                    self::PERMISSION
                ));
            }

            if (! $shouldHave && $does) {
                throw new RuntimeException(sprintf(
                    'Aborted: %s should not have %s but does.',
                    $user->name,
                    self::PERMISSION
                ));
            }
        }
    }
};
