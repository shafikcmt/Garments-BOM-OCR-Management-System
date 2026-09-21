<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * A role for the person whose only job in the system is to raise a General
 * Stock requisition — a line supervisor, a section clerk — and who must see
 * nothing else: no Store, Accounts, Commercial or Admin screen.
 *
 * TWO permissions, not one, and both names already exist:
 *
 *   store.requisition.view    opens the Purchase Requisition list, which is
 *                             the only way to the Create button and the only
 *                             way to follow a requisition once raised. The
 *                             route guard ($secRequisition in routes/store.php)
 *                             asks for it; without it the create screen is
 *                             unreachable however many create rights you hold.
 *   store.requisition.create  the Create screen and the save.
 *
 * Nothing else: not store.view, not any other General Stock section, and no
 * review / approve — a raiser must never move their own sheet along.
 *
 * The role is mapped to no department on purpose. Admin > Users offers such
 * a role under every department and with none, so the account can be made
 * for whoever needs it. A requisition raised by this role has no department
 * head step (no department role means no head to resolve) and goes to the
 * Store directly. Where a department's own head must check first, do not
 * use this role — tick the two permissions on the user's existing department
 * role instead, which the same Admin > Users screen already allows.
 *
 * ADDITIVE ONLY: findOrCreate + givePermissionTo. Running this on live cannot
 * strip anything from anyone.
 */
return new class extends Migration
{
    public const ROLE = 'requisition_raiser';

    private const PERMISSIONS = ['store.requisition.view', 'store.requisition.create'];

    public function up(): void
    {
        DB::transaction(function () {
            $role = Role::findOrCreate(self::ROLE, 'web');

            foreach (self::PERMISSIONS as $name) {
                $role->givePermissionTo(Permission::findOrCreate($name, 'web'));
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Only an unused role is removed. A role somebody holds is left in place —
     * rolling back a migration must not silently lock a real account out.
     */
    public function down(): void
    {
        $role = Role::where('name', self::ROLE)->where('guard_name', 'web')->first();

        if ($role && $role->users()->doesntExist()) {
            $role->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
