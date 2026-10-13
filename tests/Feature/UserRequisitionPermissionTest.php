<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Giving an ordinary user the right to raise a Purchase Requisition, on top of
 * their own department role, from the Admin > Users screens.
 */
function raiseAdmin(): User
{
    Role::findOrCreate('admin', 'web');

    return User::factory()->create(['status' => 1])->assignRole('admin');
}

it('grants requisition view and create when creating a user', function () {
    $admin = raiseAdmin();
    Role::findOrCreate('production', 'web');

    $this->actingAs($admin)->post(route('admin.users.store'), [
        'name' => 'Line Supervisor',
        'email' => 'line@example.test',
        'password' => 'secret123',
        'status' => 1,
        'department' => 'production',
        'role' => 'production',
        'permissions' => ['store.requisition.view', 'store.requisition.create'],
    ])->assertSessionHasNoErrors();

    $user = User::where('email', 'line@example.test')->firstOrFail();

    expect($user->can('store.requisition.view'))->toBeTrue()
        ->and($user->can('store.requisition.create'))->toBeTrue();
});

it('grants requisition view and create to an existing user without changing their role', function () {
    $admin = raiseAdmin();
    Role::findOrCreate('production', 'web');
    $user = User::factory()->create(['status' => 1])->assignRole('production');

    $this->actingAs($admin)->put(route('admin.users.update', $user), [
        'name' => $user->name,
        'email' => $user->email,
        'status' => 1,
        'department' => 'production',
        'role' => 'production',
        'permissions' => ['store.requisition.view', 'store.requisition.create'],
    ])->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->hasRole('production'))->toBeTrue()
        ->and($user->can('store.requisition.view'))->toBeTrue()
        ->and($user->can('store.requisition.create'))->toBeTrue();
});

it('lets each department role open the requisition screens once granted', function (string $role) {
    $this->withoutVite();
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create(['status' => 1])->assignRole($role);
    $user->givePermissionTo(['store.requisition.view', 'store.requisition.create']);

    $this->actingAs($user)->get(route('store.stock.requisitions.index'))->assertOk();
    $this->actingAs($user)->get(route('store.stock.requisitions.create'))->assertOk();
})->with(['production', 'merchant', 'account', 'commercial', 'supply_chain', 'management', 'store', 'maintenance', 'harc']);

it('shows the raise-requisition switch on the create and edit screens', function () {
    $this->withoutVite();
    $admin = raiseAdmin();
    Role::findOrCreate('merchant', 'web');
    $user = User::factory()->create(['status' => 1])->assignRole('merchant');

    $this->actingAs($admin)->get(route('admin.users.create'))->assertOk()->assertSee('Portal Access')->assertSee('General Stock Requisition');
    $this->actingAs($admin)->get(route('admin.users.edit', $user))->assertOk()->assertSee('Portal Access')->assertSee('General Stock Requisition');
});

it('turns requisition access on and off with the switch, for any department, general stock only', function () {
    $admin = raiseAdmin();
    Role::findOrCreate('merchant', 'web');
    $user = User::factory()->create(['status' => 1])->assignRole('merchant');

    $base = [
        'name' => $user->name, 'email' => $user->email, 'status' => 1,
        'department' => 'merchandising', 'role' => 'merchant',
        'portal_access_control' => 1,
    ];

    $this->actingAs($admin)->put(route('admin.users.update', $user), $base + ['portal_access' => ['general_requisition' => 1]])
        ->assertSessionHasNoErrors();

    $user = $user->fresh();
    expect($user->can('store.requisition.view'))->toBeTrue()
        ->and($user->can('store.requisition.create'))->toBeTrue()
        ->and($user->getAllPermissions()->pluck('name')->filter(fn ($n) => str_starts_with($n, 'material.')))->toBeEmpty();

    // Switch off: the matrix still posts the boxes ticked, the switch wins.
    $this->actingAs($admin)->put(route('admin.users.update', $user), $base + [
        'permissions' => ['store.requisition.view', 'store.requisition.create'],
    ])->assertSessionHasNoErrors();

    $user = $user->fresh();
    expect($user->can('store.requisition.view'))->toBeFalse()
        ->and($user->can('store.requisition.create'))->toBeFalse()
        ->and($user->hasRole('merchant'))->toBeTrue();
});

it('grants requisition access through the switch when creating a user', function () {
    $admin = raiseAdmin();
    Role::findOrCreate('account', 'web');

    $this->actingAs($admin)->post(route('admin.users.store'), [
        'name' => 'Accounts Clerk', 'email' => 'clerk@example.test', 'password' => 'secret123',
        'status' => 1, 'department' => 'accounts', 'role' => 'account',
        'portal_access_control' => 1, 'portal_access' => ['general_requisition' => 1],
    ])->assertSessionHasNoErrors();

    $user = User::where('email', 'clerk@example.test')->firstOrFail();
    expect($user->can('store.requisition.create'))->toBeTrue();
});

it('offers Buyer Access only to Merchandising and Supply Chain, but keeps it for an already-confined user', function () {
    $this->withoutVite();
    $admin = raiseAdmin();
    Role::findOrCreate('account', 'web');
    $buyer = \App\Models\Buyer::create(['buyer_name' => 'ACME', 'status' => 1]);
    $plain = User::factory()->create(['status' => 1])->assignRole('account');
    $confined = User::factory()->create(['status' => 1])->assignRole('account');
    $confined->buyers()->sync([$buyer->id]);
    $confined->forceFill(['buyer_id' => $buyer->id, 'buyer_scope_mode' => 'specific'])->save();

    $dataAttr = fn (string $html) => preg_match('/data-scope-departments="([^"]*)"/', $html, $m) ? $m[1] : null;

    $create = $this->actingAs($admin)->get(route('admin.users.create'))->assertOk()->getContent();
    expect($dataAttr($create))->toBe('merchandising,supply_chain');

    $edit = $this->actingAs($admin)->get(route('admin.users.edit', $plain))->assertOk()->getContent();
    expect($dataAttr($edit))->toBe('merchandising,supply_chain');

    $edit = $this->actingAs($admin)->get(route('admin.users.edit', $confined))->assertOk()->getContent();
    expect($dataAttr($edit))->toBe('merchandising,supply_chain,accounts');
});

it('keeps BOM Upload off the screen of a user outside Merchandising', function () {
    $this->withoutVite();
    $admin = raiseAdmin();
    Role::findOrCreate('account', 'web');
    $user = User::factory()->create(['status' => 1])->assignRole('account');

    $html = $this->actingAs($admin)->get(route('admin.users.edit', $user))->assertOk()->getContent();

    expect($html)->toMatch('/class="mb-4 js-dept-only" data-departments="merchandising"\s+hidden/');
});

it('puts the permission matrix under a collapsed Advanced permissions section with a Relevant view', function () {
    $this->withoutVite();
    $admin = raiseAdmin();
    Role::findOrCreate('account', 'web');
    $user = User::factory()->create(['status' => 1])->assignRole('account');

    $html = $this->actingAs($admin)->get(route('admin.users.edit', $user))->assertOk()->getContent();

    expect($html)->toContain('Advanced permissions')
        ->toContain('data-filter="relevant"')
        ->toContain('From role:')
        // collapsed: nothing granted directly and no errors
        ->not->toMatch('/<details class="gx-adv"\s+open/');
});
