<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Supply Chain gets to maintain its own two template screens.
 *
 * Both screens live under /admin and were reachable by the super admin alone,
 * so every wording fix on a PO instruction or an outbound email had to be
 * queued behind the one admin account. Supply Chain already writes to both in
 * practice — it creates suggestions through the "save as extra suggestion"
 * checkbox on a booking, and it edits every email's subject and body per-send
 * before it goes out — so this grants the missing half rather than new ground.
 *
 * TWO THINGS STAY ADMIN-ONLY, and they are the reason these are permissions
 * instead of simply widening the route's role guard:
 *
 *   is_default            a default instruction prints on every matching PO
 *                         without anyone asking for it. Turning one on is a
 *                         change to what suppliers receive company-wide.
 *   default_to / cc       the standing recipient list. Editing it silently
 *                         redirects future mail.
 *
 * The controllers re-check both server-side; the disabled inputs in the views
 * are a courtesy, not the guard.
 *
 * ADDITIVE ONLY — findOrCreate + givePermissionTo, never syncPermissions — so
 * running it against live cannot strip a grant an admin made by hand. Nobody
 * loses access, which is why the safety check below only asserts the intended
 * holders gained it.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'booking.instructions.manage',
        'email.templates.edit',
    ];

    /** Roles that carry template maintenance as part of the job. */
    private const GRANT_TO = ['supply_chain', 'admin'];

    public function up(): void
    {
        DB::transaction(function () {
            foreach (self::PERMISSIONS as $name) {
                $permission = Permission::findOrCreate($name, 'web');

                foreach (self::GRANT_TO as $roleName) {
                    Role::where('name', $roleName)->where('guard_name', 'web')->first()
                        ?->givePermissionTo($permission);
                }
            }

            $this->assertGranted();
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            // Deleting the permission drops every grant of it, role and direct
            // alike. The route guards then admit the admin role only, which is
            // exactly where these screens started.
            Permission::whereIn('name', self::PERMISSIONS)->get()
                ->each(fn (Permission $permission) => $permission->delete());

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }

    /**
     * Every named role must come out holding both permissions.
     *
     * A missing role is not an error — a fresh install may not have seeded
     * supply_chain yet — but a role that exists and did not receive the grant
     * means the transaction did not do what it says, and is worth aborting on.
     */
    private function assertGranted(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::GRANT_TO as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

            if (! $role) {
                continue;
            }

            foreach (self::PERMISSIONS as $name) {
                if (! $role->fresh()->hasPermissionTo($name)) {
                    throw new RuntimeException(sprintf(
                        'Aborted: role %s did not receive %s.',
                        $roleName,
                        $name
                    ));
                }
            }
        }
    }
};
