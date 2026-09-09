<?php

use App\Models\Buyer;
use App\Models\User;
use App\Support\BuyerScope;
use Spatie\Permission\Models\Role;

/**
 * What a DEPARTMENT ADMIN is confined to.
 *
 * THE FAIL-OPEN THIS PINS SHUT. scopedBuyerIds() used to answer a department
 * admin with ownedBuyers() alone. Buyer Access — the buyer_user pivot the user
 * form's tick boxes write — was never read for them, so an admin granted one
 * buyer and owning none came back with an EMPTY set. Empty, for an admin, is
 * what isBuyerConfined() reads as "not confined", and an unconfined user passes
 * hasBuyerAccess() for every buyer there is. The single grant meant to narrow
 * them to one buyer handed them all of them instead.
 *
 * Nobody in production was standing on it: every real department admin is mode
 * 'all', owning at most one buyer and granted none. That is why this shipped as
 * an ordinary fix rather than an emergency. It is also why the 'all'-mode cases
 * below are here — they are the four live users, and they must read exactly as
 * they did before.
 */
function dsUser(string $role = 'supply_chain', bool $departmentAdmin = true): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['is_department_admin' => $departmentAdmin]);
    $user->assignRole($role);

    return $user->fresh();
}

/** Ownership — buyers.department_admin_id. Set from Admin > Buyers. */
function dsOwn(User $user, Buyer ...$buyers): void
{
    foreach ($buyers as $buyer) {
        $buyer->forceFill(['department_admin_id' => $user->id])->save();
    }
}

/** Buyer Access — the pivot the user form writes. NOT ownership. */
function dsGrant(User $user, Buyer ...$buyers): void
{
    $user->buyer_scope_mode = BuyerScope::MODE_SPECIFIC;
    $user->save();
    $user->buyers()->sync(collect($buyers)->pluck('id')->all());
}

beforeEach(function () {
    $this->alpha = Buyer::create(['buyer_name' => 'Alpha']);
    $this->beta = Buyer::create(['buyer_name' => 'Beta']);
    $this->gamma = Buyer::create(['buyer_name' => 'Gamma']);
});

// --- The tightening case: the reported defect --------------------------------

it('confines a department admin to a buyer they were granted but do not own', function () {
    // THE DEFECT. Before the fix this returned [] and isBuyerConfined() said
    // false, which is every buyer in the system rather than this one.
    $user = dsUser();
    dsGrant($user, $this->alpha);
    $user = $user->fresh();

    expect($user->scopedBuyerIds())->toBe([$this->alpha->id])
        ->and($user->isBuyerConfined())->toBeTrue()
        ->and($user->hasBuyerAccess($this->alpha->id))->toBeTrue()
        // The half that was wide open: a buyer they hold no claim on at all.
        ->and($user->hasBuyerAccess($this->beta->id))->toBeFalse();
});

it('reads users.buyer_id as access too, not only the pivot', function () {
    // The column is authoritative on its own for anyone whose pivot row is
    // missing — created before the backfill, or added straight to the table.
    $user = dsUser();
    $user->forceFill([
        'buyer_id' => $this->alpha->id,
        'buyer_scope_mode' => BuyerScope::MODE_SPECIFIC,
    ])->save();

    expect($user->fresh()->scopedBuyerIds())->toBe([$this->alpha->id]);
});

// --- The widening case: correct, and nobody in production sees it ------------

it('gives a department admin both what they own and what they are granted', function () {
    // owns 2 + 1 more granted = 3. A widening against the old behaviour, and
    // the right answer: both columns are real claims on a buyer.
    //
    // Per the production audit no live user is in this shape — every real
    // department admin is mode 'all' with no pivot rows — so this widening
    // changes nobody's access today. It is pinned so it stays deliberate.
    $user = dsUser();
    dsOwn($user, $this->alpha, $this->beta);
    dsGrant($user, $this->gamma);
    $user = $user->fresh();

    expect($user->scopedBuyerIds())->toBe([$this->alpha->id, $this->beta->id, $this->gamma->id])
        ->and($user->isBuyerConfined())->toBeTrue();
});

it('lists a buyer once when it is both owned and granted', function () {
    $user = dsUser();
    dsOwn($user, $this->alpha);
    dsGrant($user, $this->alpha);

    expect($user->fresh()->scopedBuyerIds())->toBe([$this->alpha->id]);
});

it('keeps owned buyers at the front of the set', function () {
    // Anything reading the first element — merchantBuyerId() and its callers —
    // must still get the admin's own buyer, not one they were granted.
    $user = dsUser();
    dsOwn($user, $this->gamma);
    dsGrant($user, $this->alpha);

    expect($user->fresh()->scopedBuyerIds())->toBe([$this->gamma->id, $this->alpha->id]);
});

// --- No regression for the users who actually exist --------------------------

it('leaves an all-mode department admin owning nothing unconfined', function () {
    // Three of the four live department admins, exactly as they read before.
    $user = dsUser();

    expect($user->buyer_scope_mode)->not->toBe(BuyerScope::MODE_SPECIFIC)
        ->and($user->scopedBuyerIds())->toBe([])
        ->and($user->isBuyerConfined())->toBeFalse()
        ->and($user->hasBuyerAccess($this->alpha->id))->toBeTrue();
});

it('leaves an all-mode department admin owning one buyer confined to it', function () {
    // The fourth live one: owns 1, granted 0. Ownership answered before the
    // fix and answers now.
    $user = dsUser();
    dsOwn($user, $this->alpha);
    $user = $user->fresh();

    expect($user->scopedBuyerIds())->toBe([$this->alpha->id])
        ->and($user->isBuyerConfined())->toBeTrue()
        ->and($user->hasBuyerAccess($this->beta->id))->toBeFalse();
});

it('leaves a team member who is not a department admin untouched', function () {
    // The branch this fix did not enter. Primary first, then the rest.
    $member = dsUser(departmentAdmin: false);
    dsGrant($member, $this->alpha, $this->gamma);
    $member->forceFill(['buyer_id' => $this->gamma->id])->save();

    expect($member->fresh()->scopedBuyerIds())->toBe([$this->gamma->id, $this->alpha->id]);
});

it('leaves a never-scoped role out of it entirely', function (string $role) {
    // Ownership and access both, still nothing. The role gate comes first and
    // the fix must not have slipped underneath it — Management reading the
    // whole floor is the case that would break loudest.
    $user = dsUser($role);
    dsOwn($user, $this->alpha);
    dsGrant($user, $this->beta);
    $user = $user->fresh();

    expect($user->scopedBuyerIds())->toBe([])
        ->and($user->isBuyerConfined())->toBeFalse()
        ->and($user->hasBuyerAccess($this->gamma->id))->toBeTrue();
})->with(BuyerScope::NEVER_SCOPED_ROLES);
