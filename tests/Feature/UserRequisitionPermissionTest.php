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
