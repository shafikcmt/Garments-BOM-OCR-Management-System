<?php

use App\Models\ItemCategory;
use App\Models\StockItem;
use App\Models\User;
use App\Support\StockSku;
use Spatie\Permission\Models\Permission;

/**
 * Item Master SKU: category code + item initials + 4-digit serial per
 * category, no hyphens (STCPI0001). Generated when left blank, kept when typed.
 *
 * In-memory sqlite. No real record touched.
 */
function skuUser(): User
{
    $permissions = ['store.items.view', 'store.items.create', 'store.edit'];
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }

    return User::factory()->create(['status' => 1])->givePermissionTo($permissions);
}

it('builds the codes the reference sheet uses', function () {
    expect(StockSku::categoryCode('Stationery & Printing'))->toBe('ST')
        ->and(StockSku::categoryCode('Needle'))->toBe('ND')
        ->and(StockSku::categoryCode(null))->toBe(StockSku::NO_CATEGORY_CODE)
        ->and(StockSku::itemCode('Cut Panel Inspection Report'))->toBe('CPI')
        ->and(StockSku::itemCode('Ball Pen Black'))->toBe('BPB')
        ->and(StockSku::itemCode('B/S Needle DPX17'))->toBe('BND')
        ->and(StockSku::itemCode('Scissors'))->toBe('SCI');
});

it('generates an SKU without hyphens, numbered per category', function () {
    $user = skuUser();
    $stationery = ItemCategory::create(['name' => 'Stationery & Printing', 'is_active' => true]);
    $needle = ItemCategory::create(['name' => 'Needle', 'is_active' => true]);

    foreach ([['Cut Panel Inspection Report', $stationery], ['Ball Pen Black', $stationery], ['B/S Needle DPX17', $needle]] as [$name, $category]) {
        $this->actingAs($user)
            ->post(route('store.stock.items.store'), ['name' => $name, 'item_category_id' => (string) $category->id])
            ->assertSessionHasNoErrors();
    }

    expect(StockItem::orderBy('id')->pluck('sku')->all())->toBe(['STCPI0001', 'STBPB0002', 'NDBND0001']);
});

it('keeps a typed SKU, and refuses hyphens and duplicates', function () {
    $user = skuUser();

    $this->actingAs($user)
        ->post(route('store.stock.items.store'), ['name' => 'Air Gun', 'sku' => 'elagu0007'])
        ->assertSessionHasNoErrors();

    expect(StockItem::first()->sku)->toBe('ELAGU0007');

    $this->actingAs($user)
        ->post(route('store.stock.items.store'), ['name' => 'Air Gun 2', 'sku' => 'EL-AGU-0008'])
        ->assertSessionHasErrors('sku');

    $this->actingAs($user)
        ->post(route('store.stock.items.store'), ['name' => 'Air Gun 3', 'sku' => 'ELAGU0007'])
        ->assertSessionHasErrors('sku');
});

it('keeps the existing SKU when an edit leaves the field blank', function () {
    $user = skuUser();
    $item = StockItem::create(['name' => 'Ball Pen Black']);
    $sku = $item->sku;

    $this->actingAs($user)
        ->put(route('store.stock.items.update', $item), ['name' => 'Ball Pen Black Fine'])
        ->assertSessionHasNoErrors();

    expect($item->fresh()->sku)->toBe($sku);
});
