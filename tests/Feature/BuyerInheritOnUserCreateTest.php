<?php

use App\Models\Buyer;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Buyer inheritance on the department admin's own "create user" flow.
 *
 * There is no buyer field on that form and there must never be one — the
 * buyer is read off the person doing the creating. These go through the real
 * route rather than the controller method, because the thing worth locking is
 * that a posted buyer_id cannot influence the result.
 */
beforeEach(function () {
    foreach (['admin', 'merchant', 'commercial'] as $role) {
        Role::findOrCreate($role, 'web');
    }

    $this->buyerA = Buyer::create(['buyer_name' => 'Buyer A']);
    $this->buyerB = Buyer::create(['buyer_name' => 'Buyer B']);

    $this->deptAdmin = User::factory()->create(['is_department_admin' => true])->assignRole('merchant');
    $this->buyerA->update(['department_admin_id' => $this->deptAdmin->id]);
});

function createUserPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'New Person',
        'email' => 'new.person@example.com',
        'password' => 'secret123',
        'status' => '1',
        'department' => 'merchandising',
        'role' => 'merchant',
    ], $overrides);
}

it('gives a new user the creating department admin buyer', function () {
    $this->actingAs($this->deptAdmin)
        ->post(route('admin.users.store'), createUserPayload())
        ->assertRedirect();

    expect(User::where('email', 'new.person@example.com')->value('buyer_id'))
        ->toBe($this->buyerA->id);
});

it('ignores a buyer_id posted by hand', function () {
    $this->actingAs($this->deptAdmin)
        ->post(route('admin.users.store'), createUserPayload(['buyer_id' => $this->buyerB->id]))
        ->assertRedirect();

    expect(User::where('email', 'new.person@example.com')->value('buyer_id'))
        ->toBe($this->buyerA->id);
});

it('creates the new user upload-blocked until the admin says otherwise', function () {
    $this->actingAs($this->deptAdmin)
        ->post(route('admin.users.store'), createUserPayload())
        ->assertRedirect();

    $created = User::where('email', 'new.person@example.com')->first();

    expect($created->can_upload)->toBeFalse()
        ->and($created->mayUploadBom())->toBeFalse();
});

it('leaves users created by the super admin unscoped, as today', function () {
    $superAdmin = User::factory()->create()->assignRole('admin');

    $this->actingAs($superAdmin)
        ->post(route('admin.users.store'), createUserPayload())
        ->assertRedirect();

    expect(User::where('email', 'new.person@example.com')->value('buyer_id'))->toBeNull();
});

it('does not scope a non-merchant user a merchant admin somehow creates', function () {
    $this->actingAs($this->deptAdmin)
        ->post(route('admin.users.store'), createUserPayload([
            'department' => 'commercial',
            'role' => 'commercial',
        ]));

    expect(User::where('email', 'new.person@example.com')->value('buyer_id'))->toBeNull();
});

// --- Choosing a subset, once the admin has more than one buyer ------------

it('still assigns silently when the admin has only one buyer', function () {
    $this->actingAs($this->deptAdmin)
        ->post(route('admin.users.store'), createUserPayload())
        ->assertRedirect();

    $new = User::where('email', 'new.person@example.com')->first();

    // Column and pivot agree, so this user reads the same as a backfilled one.
    expect($new->buyer_id)->toBe($this->buyerA->id)
        ->and($new->buyers->pluck('id')->all())->toBe([$this->buyerA->id]);
});

it('assigns only the buyers the admin ticked', function () {
    $this->buyerB->update(['department_admin_id' => $this->deptAdmin->id]);

    $this->actingAs($this->deptAdmin->fresh())
        ->post(route('admin.users.store'), createUserPayload([
            'buyer_ids' => [$this->buyerB->id],
        ]))
        ->assertRedirect();

    $new = User::where('email', 'new.person@example.com')->first();

    expect($new->buyers->pluck('id')->all())->toBe([$this->buyerB->id])
        ->and($new->buyer_id)->toBe($this->buyerB->id)
        ->and($new->merchantBuyerIds())->toBe([$this->buyerB->id]);
});

it('assigns both when both are ticked, primary being the lower id', function () {
    $this->buyerB->update(['department_admin_id' => $this->deptAdmin->id]);

    $this->actingAs($this->deptAdmin->fresh())
        ->post(route('admin.users.store'), createUserPayload([
            // Posted highest-first on purpose.
            'buyer_ids' => [$this->buyerB->id, $this->buyerA->id],
        ]))
        ->assertRedirect();

    $new = User::where('email', 'new.person@example.com')->first();

    expect($new->buyer_id)->toBe($this->buyerA->id)
        ->and($new->merchantBuyerIds())->toBe([$this->buyerA->id, $this->buyerB->id]);
});

/**
 * THE SUBSET RULE AT CREATION. Refused, not silently dropped — the same
 * standard the upload tag is held to.
 */
it('REFUSES a buyer the creating admin does not own', function () {
    $this->buyerB->update(['department_admin_id' => $this->deptAdmin->id]);
    $outside = Buyer::create(['buyer_name' => 'Somebody Elses']);

    $this->actingAs($this->deptAdmin->fresh())
        ->post(route('admin.users.store'), createUserPayload([
            'buyer_ids' => [$outside->id],
        ]))
        ->assertSessionHasErrors('buyer_ids.0');

    expect(User::where('email', 'new.person@example.com')->exists())->toBeFalse();
});

it('refuses a mix of one of theirs and one outside, rather than taking the good half', function () {
    $this->buyerB->update(['department_admin_id' => $this->deptAdmin->id]);
    $outside = Buyer::create(['buyer_name' => 'Somebody Elses']);

    $this->actingAs($this->deptAdmin->fresh())
        ->post(route('admin.users.store'), createUserPayload([
            'buyer_ids' => [$this->buyerA->id, $outside->id],
        ]))
        ->assertSessionHasErrors();

    expect(User::where('email', 'new.person@example.com')->exists())->toBeFalse();
});

it('makes a multi-buyer admin choose at least one', function () {
    $this->buyerB->update(['department_admin_id' => $this->deptAdmin->id]);

    $this->actingAs($this->deptAdmin->fresh())
        ->post(route('admin.users.store'), createUserPayload())
        ->assertSessionHasErrors('buyer_ids');

    expect(User::where('email', 'new.person@example.com')->exists())->toBeFalse();
});

it('offers the picker only when there is a choice to make', function () {
    // One buyer — nothing to decide, so no field.
    $this->actingAs($this->deptAdmin)
        ->get(route('admin.users.create'))
        ->assertOk()
        ->assertDontSee('buyer_ids[]', false);

    $this->buyerB->update(['department_admin_id' => $this->deptAdmin->id]);

    $this->actingAs($this->deptAdmin->fresh())
        ->get(route('admin.users.create'))
        ->assertOk()
        ->assertSee('buyer_ids[]', false)
        ->assertSee('Buyer A')
        ->assertSee('Buyer B');
});

it('offers no picker to a super admin, whose users stay unscoped', function () {
    $super = User::factory()->create()->assignRole('admin');

    $this->actingAs($super)
        ->get(route('admin.users.create'))
        ->assertOk()
        ->assertDontSee('buyer_ids[]', false);

    $this->actingAs($super)
        ->post(route('admin.users.store'), createUserPayload(['email' => 'unscoped@example.com']));

    $new = User::where('email', 'unscoped@example.com')->first();

    expect($new->buyer_id)->toBeNull()
        ->and($new->buyers)->toHaveCount(0);
});

/*
 * Supply Chain, once BuyerScope::SCOPED_DEPARTMENTS listed it.
 *
 * The create path used to ask `departmentOf($role) !== 'merchandising'` — the
 * last hardcoded department literal in the application — which would have given
 * a Supply Chain admin a create form that silently refused to scope anybody,
 * while the edit form (already asking BuyerScope) offered the same control. It
 * asks BuyerScope now, and these pin that the two forms agree.
 */
it('gives a new supply chain user the creating supply chain admin buyer', function () {
    Role::findOrCreate('supply_chain', 'web');

    $scAdmin = User::factory()->create(['is_department_admin' => true])->assignRole('supply_chain');
    $this->buyerB->update(['department_admin_id' => $scAdmin->id]);

    $this->actingAs($scAdmin->fresh())
        ->post(route('admin.users.store'), createUserPayload([
            'department' => 'supply_chain',
            'role' => 'supply_chain',
            'email' => 'sc.person@example.com',
        ]))
        ->assertRedirect();

    expect(User::where('email', 'sc.person@example.com')->value('buyer_id'))
        ->toBe($this->buyerB->id);
});

it('lets a supply chain admin holding several buyers assign a subset on create', function () {
    Role::findOrCreate('supply_chain', 'web');

    $scAdmin = User::factory()->create(['is_department_admin' => true])->assignRole('supply_chain');
    $this->buyerA->update(['department_admin_id' => $scAdmin->id]);
    $this->buyerB->update(['department_admin_id' => $scAdmin->id]);

    $this->actingAs($scAdmin->fresh())
        ->post(route('admin.users.store'), createUserPayload([
            'department' => 'supply_chain',
            'role' => 'supply_chain',
            'email' => 'sc.multi@example.com',
            'buyer_ids' => [$this->buyerB->id],
        ]))
        ->assertRedirect();

    $new = User::where('email', 'sc.multi@example.com')->first();

    expect($new->buyer_id)->toBe($this->buyerB->id)
        ->and($new->buyers->pluck('id')->all())->toBe([$this->buyerB->id]);
});

it('refuses a supply chain admin a buyer they do not hold', function () {
    Role::findOrCreate('supply_chain', 'web');

    $scAdmin = User::factory()->create(['is_department_admin' => true])->assignRole('supply_chain');
    $this->buyerA->update(['department_admin_id' => $scAdmin->id]);
    $this->buyerB->update(['department_admin_id' => $scAdmin->id]);

    $outside = Buyer::create(['buyer_name' => 'Outside Buyer']);

    $this->actingAs($scAdmin->fresh())
        ->post(route('admin.users.store'), createUserPayload([
            'department' => 'supply_chain',
            'role' => 'supply_chain',
            'email' => 'sc.outside@example.com',
            'buyer_ids' => [$outside->id],
        ]))
        ->assertSessionHasErrors('buyer_ids.0');
});

it('ignores buyer_ids posted for a non-merchant new user', function () {
    $this->buyerB->update(['department_admin_id' => $this->deptAdmin->id]);

    $this->actingAs($this->deptAdmin->fresh())
        ->post(route('admin.users.store'), createUserPayload([
            'department' => 'commercial',
            'role' => 'commercial',
            'buyer_ids' => [$this->buyerA->id],
        ]));

    $new = User::where('email', 'new.person@example.com')->first();

    // Either the create was refused outright or the user exists unscoped —
    // both are correct. What must never happen is a buyer being attached to a
    // department that has no buyer concept.
    expect($new?->buyer_id)->toBeNull()
        ->and(DB::table('buyer_user')->count())->toBe(0);
});
