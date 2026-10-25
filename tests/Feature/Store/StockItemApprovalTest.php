<?php

use App\Models\StockItem;
use App\Models\User;
use Spatie\Permission\Models\Permission;

function approvalUser(array $permissions): User
{
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $user = User::factory()->create(['status' => 1]);
    $user->givePermissionTo($permissions);

    return $user;
}

it('keeps a new item pending and inactive when the creator cannot approve', function () {
    $this->actingAs(approvalUser(['store.items.view', 'store.items.create']))
        ->post(route('store.stock.items.store'), ['name' => 'Chalk', 'uom' => 'Box'])
        ->assertRedirect();

    $item = StockItem::firstWhere('name', 'Chalk');

    expect($item->approval_status)->toBe('pending')->and($item->is_active)->toBeFalse();
});

it('approves a new item at once when the creator can approve', function () {
    $this->actingAs(approvalUser(['store.items.view', 'store.items.create', 'store.items.approve']))
        ->post(route('store.stock.items.store'), ['name' => 'Chalk', 'uom' => 'Box'])
        ->assertRedirect();

    $item = StockItem::firstWhere('name', 'Chalk');

    expect($item->approval_status)->toBe('approved')->and($item->is_active)->toBeTrue();
});

it('lets an approver approve a pending item and refuses everyone else', function () {
    $item = StockItem::create(['name' => 'Chalk', 'uom' => 'Box', 'approval_status' => 'pending', 'is_active' => false]);

    $this->actingAs(approvalUser(['store.items.view']))
        ->post(route('store.stock.items.approve', $item))
        ->assertForbidden();

    $this->actingAs(approvalUser(['store.items.view', 'store.items.approve']))
        ->post(route('store.stock.items.approve', $item))
        ->assertRedirect();

    expect($item->fresh()->approval_status)->toBe('approved')->and($item->fresh()->is_active)->toBeTrue();
});

it('merges a duplicate UOM into the one to keep', function () {
    StockItem::create(['name' => 'A', 'uom' => 'pcs']);
    StockItem::create(['name' => 'B', 'uom' => 'Piece']);

    $this->actingAs(approvalUser(['store.items.view', 'store.items.approve']))
        ->post(route('store.stock.items.uom.merge'), ['from_uom' => 'pcs', 'to_uom' => 'Piece'])
        ->assertRedirect();

    expect(StockItem::where('uom', 'Piece')->count())->toBe(2)->and(StockItem::where('uom', 'pcs')->count())->toBe(0);
});

// --- Store admin: approves new items and maintains the Item Master -----------

function itemApprovalStoreUser(bool $departmentAdmin): User
{
    $user = User::factory()->create(['status' => 1, 'is_department_admin' => $departmentAdmin]);
    $user->assignRole(\Spatie\Permission\Models\Role::findOrCreate('store', 'web'));

    return $user;
}

it('sends a Store user\'s new item to the Store admin, who approves it', function () {
    $storeAdmin = itemApprovalStoreUser(true);
    $clerk = itemApprovalStoreUser(false);

    $this->actingAs($clerk)
        ->post(route('store.stock.items.store'), ['name' => 'Pencil Battery AA', 'uom' => 'Pcs'])
        ->assertRedirect();

    $item = StockItem::firstWhere('name', 'Pencil Battery AA');
    expect($item->approval_status)->toBe('pending')->and($item->is_active)->toBeFalse()
        ->and(\App\Models\AppNotification::where('user_id', $storeAdmin->id)->where('type', 'stock_item_approval')->exists())->toBeTrue();

    // The clerk cannot approve their own item.
    $this->actingAs($clerk)->post(route('store.stock.items.approve', $item))->assertForbidden();

    $this->actingAs($storeAdmin)->post(route('store.stock.items.approve', $item))->assertRedirect();
    expect($item->fresh()->approval_status)->toBe('approved')->and($item->fresh()->is_active)->toBeTrue();
});

it('approves an item the Store admin or the super admin adds at once', function () {
    $this->actingAs(itemApprovalStoreUser(true))
        ->post(route('store.stock.items.store'), ['name' => 'Chalk', 'uom' => 'Box'])->assertRedirect();

    $admin = User::factory()->create(['status' => 1]);
    $admin->assignRole(\Spatie\Permission\Models\Role::findOrCreate('admin', 'web'));
    $this->actingAs($admin)
        ->post(route('store.stock.items.store'), ['name' => 'Duster', 'uom' => 'Pcs'])->assertRedirect();

    expect(StockItem::firstWhere('name', 'Chalk')->approval_status)->toBe('approved')
        ->and(StockItem::firstWhere('name', 'Duster')->approval_status)->toBe('approved');
});

it('lets the Store admin edit items, but not a Store user', function () {
    $item = StockItem::create(['name' => 'Chalk', 'uom' => 'Box']);

    $this->actingAs(itemApprovalStoreUser(false))
        ->put(route('store.stock.items.update', $item), ['name' => 'Chalk White', 'uom' => 'Box'])
        ->assertForbidden();

    $this->actingAs(itemApprovalStoreUser(true))->get(route('store.stock.items.index'))
        ->assertOk()->assertViewHas('canEdit', true);

    $this->actingAs(itemApprovalStoreUser(true))
        ->put(route('store.stock.items.update', $item), ['name' => 'Chalk White', 'uom' => 'Box'])
        ->assertRedirect();

    expect($item->fresh()->name)->toBe('Chalk White');
});
