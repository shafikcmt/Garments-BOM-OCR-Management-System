<?php

use App\Models\StockItem;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The requisition form reads each line's stock and last-purchase figures from the
 * server. A long requisition (a Final one has over a hundred lines) asks for them
 * all in ONE request; it must give exactly what the one-item endpoint gives, and
 * hold back the Store's figures from a department user just the same.
 */
function snapshotUser(string $role, array $permissions = []): User
{
    $r = Role::findOrCreate($role, 'web');
    foreach ($permissions as $name) {
        $r->givePermissionTo(Permission::findOrCreate($name, 'web'));
    }

    return User::factory()->create()->assignRole($r);
}

it('returns the same figures in bulk as one item at a time, keyed by item id', function () {
    $a = StockItem::create(['name' => 'Machine Oil', 'uom' => 'Ltr', 'is_active' => true, 'unit_price' => 120]);
    $b = StockItem::create(['name' => 'Paper Weight', 'uom' => 'Piece', 'is_active' => true, 'unit_price' => 15]);
    $store = snapshotUser('store');

    $bulk = $this->actingAs($store)->getJson(route('store.stock.requisitions.item-snapshots', ['ids' => [$a->id, $b->id]]))
        ->assertOk()->json();

    expect(array_keys($bulk))->toEqualCanonicalizing([(string) $a->id, (string) $b->id]);

    foreach ([$a, $b] as $item) {
        $single = $this->actingAs($store)->getJson(route('store.stock.requisitions.item-snapshot', $item))->json();
        expect($bulk[$item->id])->toEqual($single);
    }

    expect($bulk[$a->id])->toHaveKeys(['sku', 'uom', 'stock_in_hand', 'safety_stock', 'last_purchase_rate'])
        ->and($bulk[$a->id]['uom'])->toBe('Ltr');
});

it('holds the Store figures back from a department user, in bulk as for one item', function () {
    $item = StockItem::create(['name' => 'Machine Oil', 'uom' => 'Ltr', 'is_active' => true, 'unit_price' => 120]);
    $raiser = snapshotUser('requisition_raiser', ['store.requisition.view', 'store.requisition.create']);

    $bulk = $this->actingAs($raiser)->getJson(route('store.stock.requisitions.item-snapshots', ['ids' => [$item->id]]))
        ->assertOk()->json();

    expect(array_keys($bulk[$item->id]))->toEqualCanonicalizing(['sku', 'uom', 'specification', 'item_category_id']);
});

it('asks for ids, and for no more than 500', function () {
    $store = snapshotUser('store');

    $this->actingAs($store)->getJson(route('store.stock.requisitions.item-snapshots'))->assertStatus(422);
    $this->actingAs($store)->getJson(route('store.stock.requisitions.item-snapshots', ['ids' => range(1, 501)]))->assertStatus(422);
    $this->actingAs($store)->getJson(route('store.stock.requisitions.item-snapshots', ['ids' => [999999]]))->assertOk()->assertExactJson([]);
});
