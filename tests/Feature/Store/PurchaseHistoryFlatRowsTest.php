<?php

use App\Models\StockItem;
use App\Models\StockPurchase;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Record Receiving — Purchase History prints one row per ITEM.
 *
 * It used to print one row per delivery with the items folded into a Bootstrap
 * collapse pane behind a "View Items" button, so checking a morning's receipts
 * meant clicking through every GRN on the page. The rows are flat now, in the
 * same column set and the same order the Receiving Report uses.
 *
 * In-memory sqlite. No real record touched.
 */

function purchaseHistoryUser(): User
{
    Role::findOrCreate('store', 'web');

    return User::factory()->create()->assignRole('store');
}

/** A three-item challan and a one-item challan. */
function purchaseHistoryFixture(): void
{
    $needle = StockItem::create([
        'name' => 'Needle DBx1 90/14', 'uom' => 'Pcs',
        'brand' => 'Organ', 'category' => 'Machine Needles', 'is_active' => true,
    ]);
    $oil = StockItem::create([
        'name' => 'Machine Oil', 'uom' => 'Ltr',
        'brand' => 'Shell', 'category' => 'Lubricants', 'is_active' => true,
    ]);
    $thread = StockItem::create([
        'name' => 'Sewing Thread 40/2', 'uom' => 'Cone',
        'brand' => 'Coats', 'category' => 'Threads', 'is_active' => true,
    ]);

    foreach ([[$needle, 10, 4], [$oil, 5, 200], [$thread, 25, 60]] as [$item, $qty, $price]) {
        StockPurchase::create([
            'stock_item_id' => $item->id,
            'supplier_name' => 'Star Traders',
            'purchase_date' => '2026-08-10',
            'rcv_date' => '2026-08-11',
            'challan_no' => 'CH-100',
            'rv_no' => 'RV-100',
            'qty' => $qty,
            'unit_price' => $price,
        ]);
    }

    StockPurchase::create([
        'stock_item_id' => $needle->id,
        'supplier_name' => 'Delta Supply',
        'purchase_date' => '2026-08-12',
        'rcv_date' => '2026-08-12',
        'challan_no' => 'CH-200',
        'rv_no' => 'RV-200',
        'qty' => 7,
        'unit_price' => 4,
    ]);
}

/** Body rows of the history table, each as its list of cell texts. */
function purchaseHistoryRows(string $html): array
{
    preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/s', $html, $matches);

    $rows = [];

    foreach ($matches[1] as $row) {
        if (! str_contains($row, 'RV-')) {
            continue;
        }

        preg_match_all('/<td\b[^>]*>(.*?)<\/td>/s', $row, $cells);
        $rows[] = array_map(fn ($c) => trim(html_entity_decode(strip_tags($c))), $cells[1]);
    }

    return $rows;
}

it('prints one row per item with no expand toggle left', function () {
    purchaseHistoryFixture();

    $html = $this->actingAs(purchaseHistoryUser())
        ->get(route('store.stock.purchases.index'))
        ->assertOk()
        ->getContent();

    // The toggle button and its collapse pane are gone. Asserted on the
    // MARKUP rather than the words: "Items" and "View" appear elsewhere on
    // this page quite legitimately — the Items nav button, the entry form's
    // own Items heading — and matching those would fail for the wrong reason.
    expect($html)->not->toContain('data-rv-toggle')
        ->and($html)->not->toContain('gx-rv-detail')
        ->and($html)->not->toContain('data-bs-toggle="collapse"');

    // Four item lines across two deliveries — not two rows.
    expect(purchaseHistoryRows($html))->toHaveCount(4);
});

it('repeats the delivery on each item row, in the agreed column order', function () {
    purchaseHistoryFixture();

    $rows = purchaseHistoryRows(
        $this->actingAs(purchaseHistoryUser())
            ->get(route('store.stock.purchases.index'))
            ->assertOk()
            ->getContent()
    );

    $ch100 = array_values(array_filter($rows, fn ($r) => str_contains($r[1], 'RV-100')));

    expect($ch100)->toHaveCount(3);

    // SL, GRN, Challan, Challan Date, RCV Date, Supplier, Item Name,
    // Brand/Specification, UOM, Category, Received Qty, Unit Price,
    // Received Value, Remarks — the Receiving Report's order.
    expect(array_column($ch100, 6))
        ->toBe(['Needle DBx1 90/14', 'Machine Oil', 'Sewing Thread 40/2']);
    expect(array_column($ch100, 7))->toBe(['Organ', 'Shell', 'Coats']);
    expect(array_column($ch100, 8))->toBe(['Pcs', 'Ltr', 'Cone']);
    expect(array_column($ch100, 9))->toBe(['Machine Needles', 'Lubricants', 'Threads']);
    expect(array_column($ch100, 10))->toBe(['10', '5', '25']);

    // The delivery's own fields repeat on every one of its item rows.
    foreach ($ch100 as $row) {
        expect($row[2])->toBe('CH-100')
            ->and($row[3])->toBe('10-Aug-2026')
            ->and($row[4])->toBe('11-Aug-2026')
            ->and($row[5])->toBe('Star Traders');
    }

    // SL runs across the whole table, not per delivery.
    expect(array_column($rows, 0))->toBe(['1', '2', '3', '4']);
});

it('carries the renamed headers and a page total that still adds up', function () {
    purchaseHistoryFixture();

    $html = $this->actingAs(purchaseHistoryUser())
        ->get(route('store.stock.purchases.index'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('>Item Name<')
        ->and($html)->toContain('>Brand/Specification<')
        ->and($html)->toContain('>Category<')
        ->and($html)->toContain('>Received Qty<')
        ->and($html)->toContain('>Received Value<')
        // The per-delivery Items COUNT column went with the flattening. Matched
        // as the header cell it was, because the page carries an Items nav
        // button and the entry form an Items heading, both of which stay.
        ->and($html)->not->toContain('<th class="text-end">Items</th>');

    // 10 + 5 + 25 + 7 = 47 received; 40 + 1000 + 1500 + 28 = 2,568.00 value.
    expect($html)->toContain('Page total-')
        ->and($html)->toContain('>47<')
        ->and($html)->toContain('2,568.00');
});

it('keeps the month and search filters working on the flat list', function () {
    purchaseHistoryFixture();
    $user = purchaseHistoryUser();

    // Search narrows to the matching delivery, and still prints every item of
    // it — a delivery is shown whole when any of its lines matches.
    $html = $this->actingAs($user)
        ->get(route('store.stock.purchases.index', ['search' => 'CH-200']))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('RV-200')->not->toContain('RV-100');
    expect(purchaseHistoryRows($html))->toHaveCount(1);

    // A month with nothing in it says so rather than listing everything.
    $empty = $this->actingAs($user)
        ->get(route('store.stock.purchases.index', ['month' => '2026-07']))
        ->assertOk()
        ->getContent();

    expect(purchaseHistoryRows($empty))->toHaveCount(0);
    expect($empty)->toContain('No receivings match this filter');
});
