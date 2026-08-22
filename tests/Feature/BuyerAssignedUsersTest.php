<?php

use App\Models\Buyer;
use App\Models\User;
use App\Support\BuyerScope;
use Spatie\Permission\Models\Role;

/**
 * The team panel on Admin > Buyers > Edit — the other half of Phase 4d.
 *
 * The whole screen is behind role:admin, so these are not about who may reach
 * it. They are about the ONE ASYMMETRY that makes the two screens safe
 * together: this one assigns and un-assigns, and never writes
 * buyer_scope_mode. Every test below is a way that rule could be broken by a
 * POST that never saw the form.
 */
beforeEach(function () {
    foreach (['admin', 'merchant', 'commercial'] as $role) {
        Role::findOrCreate($role, 'web');
    }

    $this->superAdmin = User::factory()->create()->assignRole('admin');

    $this->buyerA = Buyer::create(['buyer_name' => 'Buyer A', 'is_active' => true]);
    $this->buyerB = Buyer::create(['buyer_name' => 'Buyer B', 'is_active' => true]);

    $this->confined = User::factory()->create([
        'name' => 'Confined Merchant',
        'is_department_admin' => false,
        'buyer_id' => $this->buyerA->id,
        'buyer_scope_mode' => BuyerScope::MODE_SPECIFIC,
    ])->assignRole('merchant');
    $this->confined->buyers()->sync([$this->buyerA->id]);

    $this->unconfined = User::factory()->create([
        'name' => 'All Buyers Merchant',
        'is_department_admin' => false,
        'buyer_id' => null,
        'buyer_scope_mode' => BuyerScope::MODE_ALL,
    ])->assignRole('merchant');
});

it('lists the team members assigned to the buyer', function () {
    $this->actingAs($this->superAdmin)
        ->get(route('admin.buyers.edit', $this->buyerA))
        ->assertOk()
        ->assertSee('Team on this Buyer')
        ->assertSee('Confined Merchant');
});

it('adds a specific-mode user to the buyer', function () {
    $this->actingAs($this->superAdmin)
        ->post(route('admin.buyers.users.attach', $this->buyerB), [
            'user_id' => $this->confined->id,
        ])
        ->assertRedirect(route('admin.buyers.edit', $this->buyerB));

    expect($this->confined->fresh()->scopedBuyerIds())
        ->toBe([$this->buyerA->id, $this->buyerB->id]);
});

it('does not change the mode when it adds somebody', function () {
    $this->actingAs($this->superAdmin)
        ->post(route('admin.buyers.users.attach', $this->buyerB), [
            'user_id' => $this->confined->id,
        ]);

    expect($this->confined->fresh()->buyer_scope_mode)->toBe(BuyerScope::MODE_SPECIFIC);
});

it('refuses to add a user who works across all buyers', function () {
    // THE TRAP. Attaching them leaves the mode at 'all' while they now hold a
    // buyer, and isBuyerConfined() reads the set as well as the mode — so an
    // action that reads as a grant would confine them to this one buyer.
    $this->actingAs($this->superAdmin)
        ->post(route('admin.buyers.users.attach', $this->buyerA), [
            'user_id' => $this->unconfined->id,
        ])
        ->assertSessionHas('error');

    $fresh = $this->unconfined->fresh();

    expect($fresh->buyers()->count())->toBe(0)
        ->and($fresh->isBuyerConfined())->toBeFalse();
});

it('does not offer a user who works across all buyers', function () {
    $this->actingAs($this->superAdmin)
        ->get(route('admin.buyers.edit', $this->buyerA))
        ->assertOk()
        ->assertDontSee('All Buyers Merchant');
});

it('refuses to add a department admin, who owns buyers instead', function () {
    $owner = User::factory()->create([
        'is_department_admin' => true,
        'buyer_scope_mode' => BuyerScope::MODE_SPECIFIC,
    ])->assignRole('merchant');

    $this->actingAs($this->superAdmin)
        ->post(route('admin.buyers.users.attach', $this->buyerA), ['user_id' => $owner->id])
        ->assertSessionHas('error');

    expect($owner->fresh()->buyers()->count())->toBe(0);
});

it('refuses to add a user from a department scoping does not apply to', function () {
    $commercial = User::factory()->create([
        'buyer_scope_mode' => BuyerScope::MODE_SPECIFIC,
    ])->assignRole('commercial');

    $this->actingAs($this->superAdmin)
        ->post(route('admin.buyers.users.attach', $this->buyerA), ['user_id' => $commercial->id])
        ->assertSessionHas('error');

    expect($commercial->fresh()->buyers()->count())->toBe(0);
});

it('fills an empty primary buyer_id when it adds somebody', function () {
    $noPrimary = User::factory()->create([
        'buyer_id' => null,
        'buyer_scope_mode' => BuyerScope::MODE_SPECIFIC,
    ])->assignRole('merchant');
    $noPrimary->buyers()->sync([$this->buyerA->id]);

    $this->actingAs($this->superAdmin)
        ->post(route('admin.buyers.users.attach', $this->buyerB), ['user_id' => $noPrimary->id]);

    expect($noPrimary->fresh()->buyer_id)->toBe($this->buyerB->id);
});

it('leaves an existing primary alone when it adds somebody', function () {
    $this->actingAs($this->superAdmin)
        ->post(route('admin.buyers.users.attach', $this->buyerB), ['user_id' => $this->confined->id]);

    expect($this->confined->fresh()->buyer_id)->toBe($this->buyerA->id);
});

it('removes a user who still has another buyer', function () {
    $this->confined->buyers()->sync([$this->buyerA->id, $this->buyerB->id]);

    $this->actingAs($this->superAdmin)
        ->delete(route('admin.buyers.users.detach', [$this->buyerA, $this->confined]))
        ->assertRedirect(route('admin.buyers.edit', $this->buyerA));

    $fresh = $this->confined->fresh();

    // The primary follows the set. Left on the buyer just removed it would hand
    // back the access this action took away.
    expect($fresh->scopedBuyerIds())->toBe([$this->buyerB->id])
        ->and($fresh->buyer_id)->toBe($this->buyerB->id);
});

it('refuses to remove a user last buyer', function () {
    // 'specific' with an empty set denies them every tagged record while the
    // screen still shows them as scoped. Same invariant the User screen holds.
    $this->actingAs($this->superAdmin)
        ->delete(route('admin.buyers.users.detach', [$this->buyerA, $this->confined]))
        ->assertSessionHas('error');

    $fresh = $this->confined->fresh();

    expect($fresh->scopedBuyerIds())->toBe([$this->buyerA->id])
        ->and($fresh->buyer_scope_mode)->toBe(BuyerScope::MODE_SPECIFIC);
});

it('keeps a non-admin out of the whole screen', function () {
    $this->actingAs($this->confined)
        ->post(route('admin.buyers.users.attach', $this->buyerB), ['user_id' => $this->confined->id])
        ->assertForbidden();

    expect($this->confined->fresh()->buyers()->count())->toBe(1);
});
