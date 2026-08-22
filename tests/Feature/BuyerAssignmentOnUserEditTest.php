<?php

use App\Models\Buyer;
use App\Models\User;
use App\Support\BuyerScope;
use Spatie\Permission\Models\Role;

/**
 * Changing a team member's buyer set from Admin > Users > Edit.
 *
 * Before this, the set could only be decided at creation and the edit screen
 * showed a read-only badge, so every correction was a delete-and-recreate.
 *
 * These go through the real route rather than the controller method, because
 * the thing worth locking is what a crafted POST can and cannot do. The
 * standard throughout is "refused, not merely absent from a list": a buyer the
 * actor does not hold must fail the save, not just be missing from the form.
 */
beforeEach(function () {
    foreach (['admin', 'merchant', 'commercial'] as $role) {
        Role::findOrCreate($role, 'web');
    }

    $this->buyerA = Buyer::create(['buyer_name' => 'Buyer A', 'is_active' => true]);
    $this->buyerB = Buyer::create(['buyer_name' => 'Buyer B', 'is_active' => true]);
    $this->buyerC = Buyer::create(['buyer_name' => 'Buyer C', 'is_active' => true]);

    // Owns A and B. C belongs to nobody the admin can reach.
    $this->deptAdmin = User::factory()->create(['is_department_admin' => true])->assignRole('merchant');
    $this->buyerA->update(['department_admin_id' => $this->deptAdmin->id]);
    $this->buyerB->update(['department_admin_id' => $this->deptAdmin->id]);

    $this->member = User::factory()->create([
        'is_department_admin' => false,
        'buyer_id' => $this->buyerA->id,
        'buyer_scope_mode' => BuyerScope::MODE_SPECIFIC,
    ])->assignRole('merchant');
    $this->member->buyers()->sync([$this->buyerA->id]);

    $this->superAdmin = User::factory()->create()->assignRole('admin');
});

function buyerEditPayload(User $user, array $overrides = []): array
{
    return array_merge([
        'name' => $user->name,
        'email' => $user->email,
        'status' => '1',
        'department' => 'merchandising',
        'role' => 'merchant',
    ], $overrides);
}

it('lets the owning department admin move a team member onto a second buyer', function () {
    $this->actingAs($this->deptAdmin)
        ->put(route('admin.users.update', $this->member), buyerEditPayload($this->member, [
            'buyer_assignment_control' => '1',
            'buyer_ids' => [$this->buyerA->id, $this->buyerB->id],
        ]))
        ->assertRedirect();

    expect($this->member->fresh()->scopedBuyerIds())
        ->toBe([$this->buyerA->id, $this->buyerB->id]);
});

it('lets the owning department admin take a team member off a buyer', function () {
    $this->member->buyers()->sync([$this->buyerA->id, $this->buyerB->id]);

    $this->actingAs($this->deptAdmin)
        ->put(route('admin.users.update', $this->member), buyerEditPayload($this->member, [
            'buyer_assignment_control' => '1',
            'buyer_ids' => [$this->buyerB->id],
        ]))
        ->assertRedirect();

    $fresh = $this->member->fresh();

    // buyer_id follows the set. Left pointing at the removed buyer it would
    // contradict the pivot and hand back access that was just taken away.
    expect($fresh->scopedBuyerIds())->toBe([$this->buyerB->id])
        ->and($fresh->buyer_id)->toBe($this->buyerB->id);
});

it('refuses a buyer the acting admin does not hold', function () {
    $this->actingAs($this->deptAdmin)
        ->put(route('admin.users.update', $this->member), buyerEditPayload($this->member, [
            'buyer_assignment_control' => '1',
            'buyer_ids' => [$this->buyerC->id],
        ]))
        ->assertSessionHasErrors('buyer_ids.0');

    expect($this->member->fresh()->scopedBuyerIds())->toBe([$this->buyerA->id]);
});

it('refuses an empty set from a department admin, who may not un-confine anyone', function () {
    $this->actingAs($this->deptAdmin)
        ->put(route('admin.users.update', $this->member), buyerEditPayload($this->member, [
            'buyer_assignment_control' => '1',
            'buyer_ids' => [],
        ]))
        ->assertSessionHasErrors('buyer_ids');

    expect($this->member->fresh()->isBuyerConfined())->toBeTrue();
});

it('ignores a buyer_scope_mode posted by a department admin', function () {
    // The field is not theirs, so it goes unread rather than being refused -
    // the same shape as can_upload. Posting 'all' must not free the user.
    $this->actingAs($this->deptAdmin)
        ->put(route('admin.users.update', $this->member), buyerEditPayload($this->member, [
            'buyer_assignment_control' => '1',
            'buyer_scope_mode' => BuyerScope::MODE_ALL,
            'buyer_ids' => [$this->buyerB->id],
        ]))
        ->assertRedirect();

    $fresh = $this->member->fresh();

    expect($fresh->buyer_scope_mode)->toBe(BuyerScope::MODE_SPECIFIC)
        ->and($fresh->isBuyerConfined())->toBeTrue();
});

it('leaves buyers outside the acting admin reach untouched', function () {
    // C was assigned by somebody else. A save by an admin who cannot see it
    // must not strip it - the form never showed the field.
    $this->member->buyers()->sync([$this->buyerA->id, $this->buyerC->id]);

    $this->actingAs($this->deptAdmin)
        ->put(route('admin.users.update', $this->member), buyerEditPayload($this->member, [
            'buyer_assignment_control' => '1',
            'buyer_ids' => [$this->buyerB->id],
        ]))
        ->assertRedirect();

    expect($this->member->fresh()->scopedBuyerIds())
        ->toBe([$this->buyerB->id, $this->buyerC->id]);
});

it('does nothing at all when the widget was not on the page', function () {
    // No marker: the absence of the fields must read as "not shown", never as
    // "clear their buyers".
    $this->actingAs($this->deptAdmin)
        ->put(route('admin.users.update', $this->member), buyerEditPayload($this->member))
        ->assertRedirect();

    expect($this->member->fresh()->scopedBuyerIds())->toBe([$this->buyerA->id]);
});

it('lets a super admin un-confine a user, clearing the assignments', function () {
    $this->actingAs($this->superAdmin)
        ->put(route('admin.users.update', $this->member), buyerEditPayload($this->member, [
            'buyer_assignment_control' => '1',
            'buyer_scope_mode' => BuyerScope::MODE_ALL,
        ]))
        ->assertRedirect();

    $fresh = $this->member->fresh();

    // Clearing the set is what makes the mode mean anything: isBuyerConfined()
    // asks the set as well as the mode, so rows left in buyer_user would keep
    // the user confined after a save that reported success.
    expect($fresh->buyer_scope_mode)->toBe(BuyerScope::MODE_ALL)
        ->and($fresh->buyer_id)->toBeNull()
        ->and($fresh->scopedBuyerIds())->toBe([])
        ->and($fresh->isBuyerConfined())->toBeFalse();
});

it('lets a super admin confine an unscoped user', function () {
    $unscoped = User::factory()->create(['buyer_id' => null])->assignRole('merchant');

    $this->actingAs($this->superAdmin)
        ->put(route('admin.users.update', $unscoped), buyerEditPayload($unscoped, [
            'buyer_assignment_control' => '1',
            'buyer_scope_mode' => BuyerScope::MODE_SPECIFIC,
            'buyer_ids' => [$this->buyerC->id],
        ]))
        ->assertRedirect();

    expect($unscoped->fresh()->scopedBuyerIds())->toBe([$this->buyerC->id]);
});

it('refuses specific with no buyers even from a super admin', function () {
    $this->actingAs($this->superAdmin)
        ->put(route('admin.users.update', $this->member), buyerEditPayload($this->member, [
            'buyer_assignment_control' => '1',
            'buyer_scope_mode' => BuyerScope::MODE_SPECIFIC,
            'buyer_ids' => [],
        ]))
        ->assertSessionHasErrors('buyer_ids');
});

it('does not offer an inactive buyer, and refuses one posted by hand', function () {
    $this->buyerC->update(['is_active' => false]);

    $this->actingAs($this->superAdmin)
        ->put(route('admin.users.update', $this->member), buyerEditPayload($this->member, [
            'buyer_assignment_control' => '1',
            'buyer_scope_mode' => BuyerScope::MODE_SPECIFIC,
            'buyer_ids' => [$this->buyerC->id],
        ]))
        ->assertSessionHasErrors('buyer_ids.0');
});

it('never assigns buyers to a department admin, who owns them instead', function () {
    $otherAdmin = User::factory()->create(['is_department_admin' => true])->assignRole('merchant');

    $this->actingAs($this->superAdmin)
        ->put(route('admin.users.update', $otherAdmin), buyerEditPayload($otherAdmin, [
            'buyer_assignment_control' => '1',
            'buyer_scope_mode' => BuyerScope::MODE_SPECIFIC,
            'buyer_ids' => [$this->buyerA->id],
        ]))
        ->assertRedirect();

    expect($otherAdmin->fresh()->buyers()->count())->toBe(0);
});

it('never assigns buyers in a department scoping does not apply to', function () {
    $commercial = User::factory()->create()->assignRole('commercial');

    $this->actingAs($this->superAdmin)
        ->put(route('admin.users.update', $commercial), buyerEditPayload($commercial, [
            'department' => 'commercial',
            'role' => 'commercial',
            'buyer_assignment_control' => '1',
            'buyer_scope_mode' => BuyerScope::MODE_SPECIFIC,
            'buyer_ids' => [$this->buyerA->id],
        ]))
        ->assertRedirect();

    expect($commercial->fresh()->buyers()->count())->toBe(0);
});

it('shows the widget on the edit screen for a team member', function () {
    $this->actingAs($this->deptAdmin)
        ->get(route('admin.users.edit', $this->member))
        ->assertOk()
        ->assertSee('Buyer Access')
        ->assertSee('buyer_assignment_control', false)
        ->assertSee('Buyer B');
});

it('does not show the mode choice to a department admin', function () {
    $this->actingAs($this->deptAdmin)
        ->get(route('admin.users.edit', $this->member))
        ->assertOk()
        ->assertDontSee('buyerScopeModeAll', false);
});

it('shows the mode choice to a super admin', function () {
    $this->actingAs($this->superAdmin)
        ->get(route('admin.users.edit', $this->member))
        ->assertOk()
        ->assertSee('buyerScopeModeAll', false);
});
