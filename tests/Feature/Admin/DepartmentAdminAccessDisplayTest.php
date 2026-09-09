<?php

use App\Models\Buyer;
use App\Models\User;
use App\Support\BuyerScope;
use Spatie\Permission\Models\Role;

/**
 * What a department admin is shown about their own team and their own buyers.
 *
 * TWO THINGS ARE STORED, ON PURPOSE, AND THE SCREEN CONFLATED THEM.
 *
 *   BUYER ACCESS  buyer_user pivot + users.buyer_id — which buyers you may SEE.
 *                 This is what the tick boxes on the user form write.
 *   OWNERSHIP     buyers.department_admin_id — which buyers you HEAD.
 *                 This is set from Admin > Buyers.
 *
 * The profile screen read ownership and labelled it "Not assigned yet", so an
 * admin who had just granted Buyer Access was told the opposite of what they
 * had done and sent back to the control they had already used.
 *
 * The sidebar had a different shape of the same mistake: the flag that decides
 * Team Management was department-agnostic and correct, but the link itself was
 * an <li> copied inside the Merchant block and again inside the Store block.
 * A Supply Chain, Accounts or Commercial department admin renders neither, so
 * the entry they were entitled to did not exist for them.
 */
function daUser(string $role, bool $departmentAdmin = true): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['is_department_admin' => $departmentAdmin]);
    $user->assignRole($role);

    return $user->fresh();
}

/** Buyer Access — the pivot the user form writes. NOT ownership. */
function daGrantAccess(User $user, Buyer $buyer): User
{
    $user->buyer_scope_mode = BuyerScope::MODE_SPECIFIC;
    $user->save();
    $user->buyers()->sync([$buyer->id]);

    return $user->fresh();
}

/** Ownership — what buyers.department_admin_id records. NOT access. */
function daGrantOwnership(User $user, Buyer $buyer): void
{
    $buyer->forceFill(['department_admin_id' => $user->id])->save();
}

function daSidebar(User $user): string
{
    test()->actingAs($user);

    return view('layouts.sidebar')->render();
}

beforeEach(function () {
    $this->buyer = Buyer::create(['buyer_name' => 'Hugo Boss']);
});

// --- The sidebar entry ------------------------------------------------------

it('offers Team Management to a department admin of any department', function (string $role) {
    // The reported case was Supply Chain, but the link lived inside the Merchant
    // and Store blocks only — so Accounts and Commercial were equally locked
    // out, and fixing just the reported one would have left them that way.
    expect(daSidebar(daUser($role)))->toContain('Team Management');
})->with(['supply_chain', 'account', 'commercial', 'merchant', 'store']);

it('withholds it from someone who is not a department admin', function (string $role) {
    expect(daSidebar(daUser($role, departmentAdmin: false)))->not->toContain('Team Management');
})->with(['supply_chain', 'account', 'commercial', 'merchant', 'store']);

it('withholds it from admin, who reaches the same screen another way', function () {
    // Admin already has "Users & Roles" pointing here. Burying the link inside
    // role blocks was the original way of keeping it out of that menu, so the
    // exclusion has to survive moving the link out of them.
    $page = daSidebar(daUser('admin'));

    expect($page)->not->toContain('Team Management')
        // Admin's own way in: a "Users" entry under the Users & Roles submenu,
        // pointing at this very route.
        ->and($page)->toContain(route('admin.users.index'));
});

it('renders the entry once, not once per department block', function () {
    // Two copies is what let one department be fixed while others stayed
    // broken. A user in two roles must still see one link.
    Role::findOrCreate('store', 'web');

    $user = daUser('supply_chain');
    $user->assignRole('store');

    expect(substr_count(daSidebar($user->fresh()), 'Team Management'))->toBe(1);
});

// --- The profile screen -----------------------------------------------------

it('names buyer access rather than claiming nothing is assigned', function () {
    // THE REPORTED BUG. Buyer Access set, ownership not — the screen said
    // "Not assigned yet".
    $user = daGrantAccess(daUser('supply_chain'), $this->buyer);

    $page = $this->actingAs(daUser('admin'))
        ->get(route('admin.users.edit', $user->id))
        ->assertOk()
        ->getContent();

    expect($page)->toContain('Hugo Boss')
        ->toContain('Buyer access')
        // The two facts, each by its own name.
        ->toContain('Owns no buyer yet')
        ->not->toContain('Not assigned yet');
});

it('still says so plainly when there is neither access nor ownership', function () {
    $user = daUser('supply_chain');

    $page = $this->actingAs(daUser('admin'))
        ->get(route('admin.users.edit', $user->id))
        ->assertOk()
        ->getContent();

    expect($page)->toContain('owns no buyer and has no buyer access')
        ->not->toContain('Not assigned yet');
});

it('shows ownership as ownership when the admin does own a buyer', function () {
    // The branch that already worked, pinned so the change above did not
    // disturb it.
    $user = daUser('supply_chain');
    daGrantOwnership($user, $this->buyer);

    $page = $this->actingAs(daUser('admin'))
        ->get(route('admin.users.edit', $user->fresh()->id))
        ->assertOk()
        ->getContent();

    expect($page)->toContain('Hugo Boss')
        ->toContain('Owns this buyer');
});

it('leaves a non-admin team member reading as before', function () {
    // For everybody else $assignedBuyers is Buyer Access and always was. The
    // new variable must not have changed what they see.
    $member = daGrantAccess(daUser('supply_chain', departmentAdmin: false), $this->buyer);

    $page = $this->actingAs(daUser('admin'))
        ->get(route('admin.users.edit', $member->id))
        ->assertOk()
        ->getContent();

    expect($page)->toContain('Hugo Boss')
        ->not->toContain('Not assigned yet');
});

it('keeps access and ownership as two separate stored facts', function () {
    // The display fix changed no storage: access is still the pivot, ownership
    // is still buyers.department_admin_id, and granting one leaves the other
    // empty. That is the whole reason the screen has to name both.
    //
    // This test used to assert scopedBuyerIds() === [] here as well, pinning
    // what the screen was showing on top of a fail-open: an admin granted one
    // buyer and owning none came back unconfined, so hasBuyerAccess() waved
    // them through for EVERY buyer. That is fixed separately — the scoping
    // half now lives in DepartmentAdminBuyerScopeTest, which asserts the
    // opposite answer on purpose.
    $user = daGrantAccess(daUser('supply_chain'), $this->buyer);

    expect($user->ownedBuyers()->count())->toBe(0)
        ->and($user->buyers()->count())->toBe(1)
        ->and($user->scopedBuyerIds())->toBe([$this->buyer->id]);
});
