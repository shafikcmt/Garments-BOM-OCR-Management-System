<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Permissions for the General Stock Purchase Requisition workflow.
 *
 * Deliberately ADDITIVE (firstOrCreate + givePermissionTo, never
 * syncPermissions) so it is safe to run against a live database — it cannot
 * strip a permission an admin assigned by hand through the Roles screen.
 *
 * Separation of duties, mirroring the signatures on the paper form:
 *
 *   Draft / Submit      store.view                     Store user raises it
 *   Store Reviewed      store.requisition.review       Store lead checks stock
 *   Approved            store.requisition.approve      Admin / Management sign
 *   Store Ack (GRN)     store.requisition.review       replaces the store signature
 *   Accounts Ack (Pmt)  store.requisition.accounts     replaces the accounts signature
 *
 * The store role can raise and submit but cannot approve its own requisition,
 * which is the whole point of the three signature boxes on the printed sheet.
 */
class PurchaseRequisitionPermissionSeeder extends Seeder
{
    /** Permission => the roles that should hold it. */
    private const GRANTS = [
        // Checking the stock position on a submitted requisition. Store leads
        // do this, and Admin / Management can do anything Store can.
        'store.requisition.review' => ['store', 'admin', 'management'],

        // The final approval to buy. Deliberately NOT granted to store: the
        // person who raises a requisition must not be the person who approves
        // the spend.
        'store.requisition.approve' => ['admin', 'management'],

        // Accounts acknowledgement on payment, the S:T column group of the
        // printed sheet.
        'store.requisition.accounts' => ['account', 'admin', 'management'],
    ];

    public function run(): void
    {
        foreach (array_keys(self::GRANTS) as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (self::GRANTS as $permission => $roles) {
            foreach ($roles as $roleName) {
                $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

                if (! $role) {
                    continue;
                }

                if (! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }
    }
}
