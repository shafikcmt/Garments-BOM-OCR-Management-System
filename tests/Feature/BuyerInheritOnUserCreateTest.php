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
