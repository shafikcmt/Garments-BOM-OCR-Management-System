<?php

namespace Database\Seeders;

use App\Models\Buyer;
use App\Models\User;
use App\Support\BuyerScope;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * DEV / TEST FIXTURES for the 6-step departmental PRA chain.
 *
 * The live user table cannot exercise this workflow: Accounts and Commercial
 * have no department head at all, and Merchandising has no team member holding
 * the demo buyer. This seeder fills exactly those posts and nothing else.
 *
 * NOT FOR PRODUCTION. Every account is on the @pra-demo.test domain so it is
 * greppable and removable in one statement, and it is registered in
 * DatabaseSeeder only if you add it there deliberately — it is not wired into
 * the default seed.
 *
 * Idempotent: re-running updates the same rows rather than creating more.
 */
class PraWorkflowDemoUsersSeeder extends Seeder
{
    public const EMAIL_DOMAIN = '@pra-demo.test';

    public const DEMO_BUYER = 'PRA Demo Buyer';

    /**
     * Shared password for every demo account. Dev convenience only; these
     * accounts are not meant to exist anywhere real.
     */
    public const PASSWORD = 'PraDemo!2026';

    public function run(): void
    {
        // A buyer of its own rather than Hugo Boss or MACYS, so the demo head's
        // ownership cannot disturb the two real assignments already in place.
        $buyer = Buyer::firstOrCreate(['buyer_name' => self::DEMO_BUYER]);

        $creator = $this->user('sc.creator', 'PRA Demo — Supply Chain Creator', 'supply_chain');

        $merchantTeam = $this->user('merchant.team', 'PRA Demo — Merchant Team', 'merchant');
        // A SECOND team member on the same buyer, so step 2 has a real choice:
        // the primary-assignee feature is only demonstrable when naming one
        // person leaves another eligible person who can still act.
        $merchantTeam2 = $this->user('merchant.team2', 'PRA Demo — Merchant Team 2', 'merchant');
        $merchantHead = $this->user('merchant.head', 'PRA Demo — Merchant Head', 'merchant', head: true);

        $accountsTeam = $this->user('accounts.team', 'PRA Demo — Accounts Team', 'account');
        $this->user('accounts.head', 'PRA Demo — Accounts Head', 'account', head: true);

        $this->user('commercial.head', 'PRA Demo — Commercial Head', 'commercial', head: true);

        // Step 3 resolves the merchant head through buyers.department_admin_id,
        // so the head must actually own the demo buyer.
        $buyer->forceFill(['department_admin_id' => $merchantHead->id])->save();

        // Steps 2 and 3 are buyer-scoped: the team member needs Buyer Access to
        // the demo buyer, and so does the creator to raise the PRA at all.
        $this->confineToBuyer($merchantTeam, $buyer);
        $this->confineToBuyer($merchantTeam2, $buyer);
        $this->confineToBuyer($creator, $buyer);

        // Accounts and Commercial are deliberately unscoped — no buyer access is
        // granted, and their steps must still resolve. Leaving this blank is the
        // assertion, not an omission.
        unset($accountsTeam);
    }

    protected function user(string $handle, string $name, string $role, bool $head = false): User
    {
        Role::findOrCreate($role, 'web');

        $user = User::updateOrCreate(
            ['email' => $handle . self::EMAIL_DOMAIN],
            [
                'name' => $name,
                'password' => self::PASSWORD, // hashed by the model cast
                'is_department_admin' => $head,
                'status' => 1, // tinyint, not a string — 1 is active, as UserSeeder writes it
            ]
        );

        $user->syncRoles([$role]);

        return $user->fresh();
    }

    protected function confineToBuyer(User $user, Buyer $buyer): void
    {
        $user->forceFill(['buyer_scope_mode' => BuyerScope::MODE_SPECIFIC])->save();
        $user->buyers()->sync([$buyer->id]);
    }
}
