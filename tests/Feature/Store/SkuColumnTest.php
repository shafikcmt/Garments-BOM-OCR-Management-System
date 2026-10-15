<?php

use App\Models\StockItem;
use App\Models\StockIssue;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The SKU in a column of its own on the General Stock screens, and findable by
 * the SKU in every search. Screen only: PDF and Excel are unchanged.
 */
beforeEach(function () {
    $this->withoutVite();
    $role = Role::findOrCreate('store', 'web');
    foreach (['store.view', 'store.requisition.view', 'store.requisition.create', 'store.issues.view', 'store.issues.create', 'store.receiving.view', 'store.receiving.create'] as $p) {
        $role->givePermissionTo(Permission::findOrCreate($p, 'web'));
    }
    $this->store = User::factory()->create()->assignRole($role);
    $this->item = StockItem::create(['name' => 'Machine Oil', 'uom' => 'Ltr', 'is_active' => true]);
    $this->other = StockItem::create(['name' => 'Sewing Thread', 'uom' => 'Cone', 'is_active' => true]);
});

it('gives every new item a SKU to show', function () {
    expect($this->item->fresh()->sku)->not->toBeEmpty();
});

it('shows the SKU as its own Stock Report column, not under the item name', function () {
    $html = $this->actingAs($this->store)
        ->get(route('store.stock.ledger', ['partial' => 1]))
        ->assertOk()->getContent();

    expect($html)->toContain('<th class="gx-ledger-quiet text-nowrap">SKU</th>')
        ->toContain($this->item->fresh()->sku);
});

it('finds a Stock Report row by its SKU', function () {
    $html = $this->actingAs($this->store)
        ->get(route('store.stock.ledger', ['partial' => 1, 'search' => $this->item->fresh()->sku]))
        ->assertOk()->getContent();

    expect($html)->toContain('Machine Oil')->not->toContain('Sewing Thread');
});

it('finds a requisition by the SKU of one of its items', function () {
    $this->actingAs($this->store)->post(route('store.stock.requisitions.store'), [
        'action' => 'submit',
        'mode' => 'multi',
        'requisition_date' => '2026-10-05',
        'items' => [['stock_item_id' => $this->item->id, 'qty_requested' => 2]],
    ]);

    $sku = $this->item->fresh()->sku;
    $number = \App\Models\PurchaseRequisition::latest('id')->value('requisition_no');

    $this->actingAs($this->store)
        ->get(route('store.stock.requisitions.index', ['search' => $sku]))
        ->assertOk()->assertSee($number);

    $this->actingAs($this->store)
        ->get(route('store.stock.requisitions.index', ['search' => $this->other->fresh()->sku]))
        ->assertOk()->assertDontSee($number);
});

it('sends the SKU with the requisition item snapshot', function () {
    $this->actingAs($this->store)
        ->getJson(route('store.stock.requisitions.item-snapshot', $this->item))
        ->assertOk()
        ->assertJsonPath('sku', $this->item->fresh()->sku);
});

it('offers a read-only SKU field on the requisition, issue and receiving lines', function () {
    $req = $this->actingAs($this->store)->get(route('store.stock.requisitions.create'))->assertOk()->getContent();
    expect($req)->toContain('js-line-sku');

    foreach (['store.stock.issues.index', 'store.stock.purchases.index'] as $route) {
        expect($this->actingAs($this->store)->get(route($route))->assertOk()->getContent())->toContain('js-line-sku');
    }
});

it('keeps the chosen item visible while its select is focused, until the user types', function () {
    // The shared select hid the chosen name on focus, so the box looked empty
    // while the SKU and Uom beside it were still filled.
    $html = $this->actingAs($this->store)->get(route('store.stock.requisitions.create'))->assertOk()->getContent();

    expect($html)->toContain('.ts-wrapper.single.input-active:not(.gx-typing) .ts-control > .item')
        ->toContain("classList.toggle('gx-typing'")
        ->toContain("classList.remove('gx-typing')");
});
