<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Merchandising, Accounts and Commercial get to work the departmental PRA chain.
 *
 * A SEPARATE PERMISSION, NOT approve-pra. That is the whole point of this
 * migration and the reason it is not two lines.
 *
 * `approve-pra` gates the LEGACY approver screens, whose access rule is "the
 * admin put you in the pra_approvers pool". Granting it to three whole
 * departments would hand every merchant, accountant and commercial user the
 * right to act on the 19 existing hand-routed PRAs — records they were never
 * selected for and that the pool exists precisely to restrict. The two flows
 * have different access rules, so they get different permissions:
 *
 *   approve-pra              flow 1. admin + management, via the pool.
 *                            UNCHANGED by this migration.
 *   pra.departmental.act     flow 2. merchant + account + commercial + admin,
 *                            narrowed further per-PRA by PraStageResolver.
 *
 * The permission is only the OUTER gate — it says "you may open the
 * departmental queue at all". Whether you may act on a given PRA is decided by
 * PraStageResolver, which asks whether you are an eligible actor for that PRA's
 * CURRENT step. Holding the permission grants nothing on its own.
 *
 * management is deliberately NOT granted. It already holds approve-pra and sees
 * the whole factory; adding it here would put the same person on both queues
 * with two different rulebooks, which is how a PRA gets approved twice by
 * accident.
 *
 * ADDITIVE ONLY — findOrCreate + givePermissionTo, never syncPermissions — so
 * running this against live cannot strip a grant an admin made by hand.
 */
return new class extends Migration
{
    private const PERMISSION = 'pra.departmental.act';

    /** The three departments the chain routes to, plus the super admin. */
    private const GRANT_TO = ['merchant', 'account', 'commercial', 'admin'];

    public function up(): void
    {
        DB::transaction(function () {
            $permission = Permission::findOrCreate(self::PERMISSION, 'web');

            foreach (self::GRANT_TO as $roleName) {
                Role::where('name', $roleName)->where('guard_name', 'web')->first()
                    ?->givePermissionTo($permission);
            }

            $this->assertGranted();
            $this->assertLegacyUntouched();
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            // Deleting the permission drops every grant of it, role and direct
            // alike, and the departmental routes then admit nobody.
            Permission::where('name', self::PERMISSION)->get()
                ->each(fn (Permission $permission) => $permission->delete());

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }

    private function assertGranted(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::GRANT_TO as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

            // A missing role is not an error — a fresh install may not have
            // seeded every department yet.
            if ($role && ! $role->fresh()->hasPermissionTo(self::PERMISSION)) {
                throw new RuntimeException(
                    sprintf('Aborted: role %s did not receive %s.', $roleName, self::PERMISSION)
                );
            }
        }
    }

    /**
     * THE SAFETY CHECK THAT MATTERS. The three new departments must not have
     * picked up approve-pra as a side effect — that would open the legacy PRAs
     * to them, which is the one thing this migration is shaped to avoid.
     */
    private function assertLegacyUntouched(): void
    {
        foreach (['merchant', 'account', 'commercial'] as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

            if ($role && $role->fresh()->hasPermissionTo('approve-pra')) {
                throw new RuntimeException(sprintf(
                    'Aborted: role %s holds approve-pra, which would expose legacy PRAs to it.',
                    $roleName
                ));
            }
        }
    }
};
