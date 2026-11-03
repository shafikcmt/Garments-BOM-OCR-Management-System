<?php

use App\Models\ItemCategory;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Models\StockItem;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * A Final Requisition has a few hundred lines. Its edit form used to repeat the whole item
 * list inside every line (hundreds of thousands of <option> elements) and ask the server for
 * each line's figures one by one, so it took minutes to open. The item list is now in the
 * page once, lines take what they need from it, and the figures come in one request.
 */
function loadFinalSheet(int $lines, int $items): array
{
    $store = User::factory()->create()->assignRole(Role::findOrCreate('store', 'web'));
    $category = ItemCategory::create(['name' => 'Stationery']);

    $stock = collect(range(1, $items))->map(fn ($i) => StockItem::create([
        'name' => 'Item '.$i, 'uom' => 'Piece', 'category' => 'Stationery', 'item_category_id' => $category->id, 'is_active' => true,
    ]));

    $sheet = PurchaseRequisition::create([
        'requisition_no' => 'HAPL/ALL/2026/October/09', 'requisition_date' => '2026-10-31', 'mode' => PurchaseRequisition::MODE_MULTI,
        'level' => PurchaseRequisition::LEVEL_FINAL, 'status' => PurchaseRequisition::STATUS_DRAFT, 'created_by' => $store->id,
    ]);

    foreach ($stock->take($lines) as $i => $item) {
        PurchaseRequisitionItem::create([
            'purchase_requisition_id' => $sheet->id, 'stock_item_id' => $item->id, 'item_category_id' => $category->id, 'sort_order' => $i,
            'qty_requested' => 5, 'rate_appx' => 10, 'type' => 'new', 'stock_in_hand_snapshot' => 0, 'to_be_procured_qty' => 5, 'amount' => 50,
        ]);
    }

    return [$store, $sheet];
}

it('puts the item list in the page once, not in every line', function () {
    [$store, $sheet] = loadFinalSheet(lines: 60, items: 80);

    $html = $this->actingAs($store)->get(route('store.stock.requisitions.edit', $sheet))->assertOk()->getContent();

    // 80 items once in the script: the list is there, each item a single time.
    expect(substr_count($html, 'var ITEM_LIST'))->toBe(1)
        ->and(substr_count($html, 'Item 79"'))->toBe(1)
        // Not 60 lines x 80 items of <option> markup: the line template's item select is empty.
        ->and(substr_count($html, '<option value="'))->toBeLessThan(150)
        ->and($html)->toContain('data-lazy-items');
});

it('previews To Be Procured with the Safety Stock, as the saved figure has it', function () {
    [$store, $sheet] = loadFinalSheet(lines: 3, items: 3);

    $html = $this->actingAs($store)->get(route('store.stock.requisitions.edit', $sheet))->assertOk()->getContent();

    // Qty - Stock in Hand + Safety Stock (rounded), whole, never below 0: the same rule as
    // PurchaseRequisitionService::toBeProcured, so the form's total matches what is saved.
    expect($html)->toContain('Math.round(group.qty - group.stock + Math.round(group.safety))')
        ->and($html)->toContain('row.dataset.safetyStock');

    $service = app(\App\Services\PurchaseRequisitionService::class);
    expect($service->toBeProcured(420, 180, 42.54))->toBe(283.0)   // the owner's worked example
        ->and($service->toBeProcured(10, 15, 4))->toBe(0.0);
});

it('loads every line\'s figures with one request, not one per line', function () {
    [$store, $sheet] = loadFinalSheet(lines: 5, items: 5);

    $html = $this->actingAs($store)->get(route('store.stock.requisitions.edit', $sheet))->assertOk()->getContent();

    // @json escapes the slashes of the address it writes into the script.
    expect($html)->toContain(json_encode(route('store.stock.requisitions.item-snapshots')))
        ->and($html)->toContain('function loadSnapshots(list)')
        // The first paint adds the lines without a request each.
        ->and($html)->toContain('firstPaint.push(addLine(');
});
