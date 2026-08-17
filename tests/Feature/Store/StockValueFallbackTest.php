<?php

use App\Models\StockIssue;
use App\Models\StockItem;
use App\Models\StockPurchase;
use App\Services\GeneralStockReportService;

/**
 * The Value column's price, and where it comes from.
 *
 * Excel column R has always been the price on the MOST RECENT CHALLAN — money
 * somebody actually paid — and that does not change. What changes is the item
 * that has never been received with a price at all: of 1,240 items, five had
 * one, so the Value column was empty for almost every row and the report's own
 * total described a fraction of the store.
 *
 * The load-bearing guarantee here is therefore a NEGATIVE one: no figure the
 * report already produced may move. The first test is the one that proves it.
 *
 * In-memory sqlite. No real record touched.
 */
function valueItem(string $name = 'Sewing Needle', array $attributes = []): StockItem
{
    return StockItem::create(array_merge([
        'name' => $name, 'uom' => 'Pkt', 'category' => 'Needle', 'opening_qty' => 0,
    ], $attributes));
}

function received(StockItem $item, float $qty, ?float $price, ?string $date = null): StockPurchase
{
    return StockPurchase::create([
        'stock_item_id' => $item->id,
        'purchase_date' => $date ?? now()->startOfMonth()->toDateString(),
        'rcv_date' => $date ?? now()->startOfMonth()->toDateString(),
        'qty' => $qty,
        'unit_price' => $price,
    ]);
}

function reportRow(StockItem $item): array
{
    return app(GeneralStockReportService::class)
        ->rows(now()->startOfMonth(), ['item_ids' => [$item->id], 'only_active' => false])
        ->first();
}

// --- The guarantee: nothing already produced moves ----------------------------

it('keeps the challan price when there is one, even if the master disagrees', function () {
    // THE TEST THAT MATTERS. A master price must never override a real one, or
    // every Value the report has produced would change.
    $item = valueItem('Sewing Needle', ['unit_price' => 999]);
    received($item, 10, 5.0);

    $row = reportRow($item);

    expect($row['unit_price'])->toBe(5.0)
        ->and($row['closing_value'])->toBe(50.0)
        // ...and it is not marked as a standard price, because it is not one.
        ->and($row['unit_price_is_standard'])->toBeFalse();
});

it('still takes the most recent challan price, not the master, across several receipts', function () {
    $item = valueItem('Sewing Needle', ['unit_price' => 999]);
    received($item, 10, 4.0, now()->startOfMonth()->toDateString());
    received($item, 10, 7.0, now()->startOfMonth()->addDays(3)->toDateString());

    // 20 in stock at the latest price of 7.
    expect(reportRow($item)['unit_price'])->toBe(7.0)
        ->and(reportRow($item)['closing_value'])->toBe(140.0);
});

// --- The gap it fills ---------------------------------------------------------

it('falls back to the item master price when nothing was received with one', function () {
    $item = valueItem('Sewing Needle', ['unit_price' => 12.5]);
    received($item, 8, null);

    $row = reportRow($item);

    expect($row['unit_price'])->toBe(12.5)
        // Previously null — this is the blank being filled.
        ->and($row['closing_value'])->toBe(100.0)
        ->and($row['unit_price_is_standard'])->toBeTrue();
});

it('falls back for an item with opening stock and no purchases at all', function () {
    // The commonest shape in the live data: seeded opening stock, nothing ever
    // received against it.
    $item = valueItem('Sewing Needle', ['unit_price' => 3.0, 'opening_qty' => 20]);

    $row = reportRow($item);

    expect($row['unit_price'])->toBe(3.0)
        ->and($row['closing_value'])->toBe(60.0)
        ->and($row['unit_price_is_standard'])->toBeTrue();
});

it('still shows nothing when neither price exists', function () {
    // No invented figure. An item nobody has priced still has no value.
    $item = valueItem('Sewing Needle');
    received($item, 8, null);

    $row = reportRow($item);

    expect($row['unit_price'])->toBeNull()
        ->and($row['closing_value'])->toBeNull()
        ->and($row['unit_price_is_standard'])->toBeFalse();
});

it('ignores a zero or blank challan price and uses the master instead', function () {
    // latestUnitPrices() already skips a price of 0 — a zero on a challan means
    // nobody filled it in, not that the goods were free — so such an item must
    // reach the fallback rather than being treated as priced at nothing.
    $item = valueItem('Sewing Needle', ['unit_price' => 6.0]);
    received($item, 10, 0.0);

    $row = reportRow($item);

    expect($row['unit_price'])->toBe(6.0)
        ->and($row['unit_price_is_standard'])->toBeTrue()
        ->and($row['closing_value'])->toBe(60.0);
});

// --- The total ----------------------------------------------------------------

it('adds the newly valued items into the report total', function () {
    // The summary sums closing_value, so filling blanks necessarily raises it.
    // Asserted so the change to the headline figure is deliberate and recorded.
    $priced = valueItem('Priced By Challan');
    received($priced, 10, 5.0);

    $standard = valueItem('Priced By Master', ['unit_price' => 2.0]);
    received($standard, 10, null);

    $unpriced = valueItem('Never Priced');
    received($unpriced, 10, null);

    $service = app(GeneralStockReportService::class);
    $rows = $service->rows(now()->startOfMonth(), ['only_active' => false]);

    // 10 x 5 from the challan, 10 x 2 from the master, nothing for the third.
    expect($service->summary($rows)['closing_value'])->toBe(70.0);
});

it('values stock at the standard price as the balance moves', function () {
    // The fallback is a price, not a fixed value: it multiplies whatever is on
    // the shelf, exactly as the challan price does.
    $item = valueItem('Sewing Needle', ['unit_price' => 4.0]);
    received($item, 25, null);

    expect(reportRow($item)['closing_value'])->toBe(100.0);

    StockIssue::create([
        'stock_item_id' => $item->id,
        'issue_date' => now()->startOfMonth()->toDateString(),
        'qty' => 10,
    ]);

    expect(reportRow($item)['closing_value'])->toBe(60.0);
});
