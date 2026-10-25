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
