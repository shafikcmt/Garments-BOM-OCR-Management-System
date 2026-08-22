<?php

use App\Models\GeneralStockSupplier;
use App\Models\StockIssue;
use App\Models\StockItem;
use App\Models\StockPurchase;
use App\Models\User;
use App\Services\GeneralStockReportService;
use Spatie\Permission\Models\Role;

/**
 * Stock Report — the Date Range and Supplier filters.
 *
 * Two guarantees carry this feature, and the first is a NEGATIVE one:
 *
 *   1. With neither filter set, every figure is what it was before. The month
 *      path is untouched, so any drift here is a regression in the report, not
 *      in the filter.
 *   2. The rate columns — Consumption/day, Safety Stock, Re-order Level and
 *      therefore Status — stay on CALENDAR MONTHS whatever the range is. Those
 *      thresholds are shared with the Item Master through statusFor(), and a
 *      five-day window divided by 26 working days would have this report
 *      calling an item Ok while the Item Master calls it Place Order.
 *
 * Supplier narrows WHICH ITEMS are listed and never the figures on them:
 * stock_issues has no supplier, so a partially filtered row would stop tying.
 *
 * In-memory sqlite. No real record touched.
 */
function rangeItem(string $name, array $attributes = []): StockItem
{
    return StockItem::create(array_merge([
        'name' => $name, 'uom' => 'Pcs', 'category' => 'General', 'opening_qty' => 0,
    ], $attributes));
}

function rangeReceipt(StockItem $item, float $qty, string $date, ?int $supplierId = null): StockPurchase
{
    return StockPurchase::create([
        'stock_item_id' => $item->id,
        'purchase_date' => $date,
        'rcv_date' => $date,
        'qty' => $qty,
        'unit_price' => 10,
        'general_stock_supplier_id' => $supplierId,
    ]);
}

function rangeIssue(StockItem $item, float $qty, string $date): StockIssue
{
    return StockIssue::create([
        'stock_item_id' => $item->id,
        'issue_date' => $date,
        'qty' => $qty,
    ]);
}

/** @return array<string, mixed>|null */
function rangeRow(StockItem $item, array $filters = []): ?array
{
    return app(GeneralStockReportService::class)
        ->rows(
            \Illuminate\Support\Carbon::parse('2026-08-01'),
            $filters + ['item_ids' => [$item->id], 'only_active' => false],
        )
        ->first();
}

function stockReportUser(): User
{
    Role::findOrCreate('store', 'web');

    return User::factory()->create()->assignRole('store');
}

// --- Guarantee 1: the month path does not move -------------------------------

it('gives the same figures as before when no range is set', function () {
    $item = rangeItem('Marker Pen');

    rangeReceipt($item, 100, '2026-07-20');   // before August
    rangeIssue($item, 30, '2026-07-25');      // before August
    rangeReceipt($item, 50, '2026-08-10');    // in August
    rangeIssue($item, 20, '2026-08-12');      // in August

    $row = rangeRow($item);

    expect($row['balance_bf'])->toBe(70.0)      // 100 - 30
        ->and($row['addition'])->toBe(50.0)
        ->and($row['consumption'])->toBe(20.0)
        ->and($row['stock_as_on'])->toBe(100.0); // 70 + 50 - 20
});

// --- Date Range ---------------------------------------------------------------

it('moves the window to the range, and the row still ties', function () {
    $item = rangeItem('Marker Pen');

    rangeReceipt($item, 100, '2026-07-20');
    rangeReceipt($item, 50, '2026-08-10');
    rangeIssue($item, 20, '2026-08-12');
    rangeReceipt($item, 999, '2026-08-25');   // AFTER the range — must not count

    $row = rangeRow($item, ['from' => '2026-08-01', 'to' => '2026-08-15']);

    expect($row['balance_bf'])->toBe(100.0)
        ->and($row['addition'])->toBe(50.0)
        ->and($row['consumption'])->toBe(20.0)
        // The report's own equation, which is the thing that must never break.
        ->and($row['stock_as_on'])->toBe($row['balance_bf'] + $row['addition'] - $row['consumption'])
        ->and($row['stock_as_on'])->toBe(130.0);
});

it('carries everything before the from date into balance brought forward', function () {
    $item = rangeItem('Marker Pen');

    rangeReceipt($item, 100, '2026-08-02');
    rangeIssue($item, 40, '2026-08-03');

    // Range starts AFTER both, so both belong to the opening balance.
    $row = rangeRow($item, ['from' => '2026-08-10', 'to' => '2026-08-20']);

    expect($row['balance_bf'])->toBe(60.0)
        ->and($row['addition'])->toBe(0.0)
        ->and($row['consumption'])->toBe(0.0)
        ->and($row['stock_as_on'])->toBe(60.0);
});

it('spans months when the range does', function () {
    $item = rangeItem('Marker Pen');

    rangeReceipt($item, 10, '2026-07-15');
    rangeReceipt($item, 20, '2026-08-15');

    $row = rangeRow($item, ['from' => '2026-07-01', 'to' => '2026-08-31']);

    expect($row['addition'])->toBe(30.0);
});

it('includes movement dated on the from and to dates themselves', function () {
    $item = rangeItem('Marker Pen');

    rangeReceipt($item, 5, '2026-08-05');   // exactly the from date
    rangeReceipt($item, 7, '2026-08-09');   // exactly the to date

    $row = rangeRow($item, ['from' => '2026-08-05', 'to' => '2026-08-09']);

    expect($row['addition'])->toBe(12.0);
});

// --- Guarantee 2: the rate columns stay on calendar months -------------------

it('reads the consumption pattern from the calendar month, not the range', function () {
    // 260 issued in July / 26 working days = 10 a day, whatever window is
    // being viewed. Divide a five-day range by 26 instead and the rate — and
    // with it Safety Stock and Status — collapses.
    $item = rangeItem('Marker Pen');

    rangeIssue($item, 260, '2026-07-10');
    rangeReceipt($item, 1000, '2026-07-01');

    $monthRow = rangeRow($item);
    $rangeRow = rangeRow($item, ['from' => '2026-08-05', 'to' => '2026-08-09']);

    expect($rangeRow['consumption_per_day'])->toBe($monthRow['consumption_per_day'])
        ->and($rangeRow['consumption_per_day'])->toBe(10.0)
        ->and($rangeRow['safety'])->toBe($monthRow['safety']);
});

it('keeps the status the same as the item master would give', function () {
    $item = rangeItem('Marker Pen', ['safety_stock_qty' => 200, 'reorder_level' => 400]);

    rangeReceipt($item, 100, '2026-08-01');

    $row = rangeRow($item, ['from' => '2026-08-01', 'to' => '2026-08-03']);

    // 100 in stock against a safety level of 200 — Place Order, exactly as
    // statusFor() answers for the same numbers anywhere else in the system.
    expect($row['status'])->toBe(GeneralStockReportService::STATUS_PLACE_ORDER)
        ->and($row['status'])->toBe(
            GeneralStockReportService::statusFor($row['stock_as_on'], 200.0, 400.0)
        );
});

// --- A half-filled range completes itself ------------------------------------

it('completes a from-only range from that date own month', function () {
    $service = app(GeneralStockReportService::class);

    $period = $service->resolvePeriod(\Illuminate\Support\Carbon::parse('2026-08-01'), '2026-09-10', null);

    expect($period['is_range'])->toBeTrue()
        ->and($period['start']->toDateString())->toBe('2026-09-10')
        // NOT the August the month picker is on — that would be a backwards
        // range built out of two controls that look unrelated.
        ->and($period['end']->toDateString())->toBe('2026-09-30');
});

it('completes a to-only range from that date own month', function () {
    $service = app(GeneralStockReportService::class);

    $period = $service->resolvePeriod(\Illuminate\Support\Carbon::parse('2026-08-01'), null, '2026-06-12');

    expect($period['start']->toDateString())->toBe('2026-06-01')
        ->and($period['end']->toDateString())->toBe('2026-06-12');
});

it('stays on the month when neither date is given', function () {
    $service = app(GeneralStockReportService::class);

    $period = $service->resolvePeriod(\Illuminate\Support\Carbon::parse('2026-08-01'), null, null);

    expect($period['is_range'])->toBeFalse()
        ->and($period['start']->toDateString())->toBe('2026-08-01')
        ->and($period['end']->toDateString())->toBe('2026-08-31');
});

// --- Supplier ----------------------------------------------------------------

it('lists only items received from the chosen supplier', function () {
    $acme = GeneralStockSupplier::create(['name' => 'Acme', 'is_active' => true]);
    $other = GeneralStockSupplier::create(['name' => 'Other', 'is_active' => true]);

    $fromAcme = rangeItem('Acme Pen');
    $fromOther = rangeItem('Other Pen');
    $fromNobody = rangeItem('Untraced Pen');

    rangeReceipt($fromAcme, 10, '2026-08-02', $acme->id);
    rangeReceipt($fromOther, 10, '2026-08-02', $other->id);
    rangeReceipt($fromNobody, 10, '2026-08-02');

    $names = app(GeneralStockReportService::class)
        ->rows(\Illuminate\Support\Carbon::parse('2026-08-01'), [
            'only_active' => false,
            'supplier_id' => $acme->id,
        ])
        ->map(fn ($r) => $r['item']->name)
        ->all();

    expect($names)->toBe(['Acme Pen']);
});

it('leaves the figures whole-store, not the supplier share', function () {
    // THE POINT OF THE DESIGN. stock_issues carries no supplier, so filtering
    // Addition to one vendor while Consumption still counts everyone would
    // stop the row tying and misprice Closing Stock Value.
    $acme = GeneralStockSupplier::create(['name' => 'Acme', 'is_active' => true]);
    $other = GeneralStockSupplier::create(['name' => 'Other', 'is_active' => true]);

    $item = rangeItem('Shared Pen');

    rangeReceipt($item, 30, '2026-08-02', $acme->id);
    rangeReceipt($item, 70, '2026-08-03', $other->id);
    rangeIssue($item, 25, '2026-08-04');

    $row = rangeRow($item, ['supplier_id' => $acme->id]);

    expect($row['addition'])->toBe(100.0)          // both suppliers, not just Acme
        ->and($row['consumption'])->toBe(25.0)
        ->and($row['stock_as_on'])->toBe(75.0);
});

it('ignores a receipt dated after the period when deciding the item list', function () {
    $acme = GeneralStockSupplier::create(['name' => 'Acme', 'is_active' => true]);

    $item = rangeItem('Late Pen');
    rangeReceipt($item, 10, '2026-09-15', $acme->id);   // after August

    $rows = app(GeneralStockReportService::class)
        ->rows(\Illuminate\Support\Carbon::parse('2026-08-01'), [
            'only_active' => false,
            'supplier_id' => $acme->id,
        ]);

    expect($rows)->toHaveCount(0);
});

// --- Filters combine ----------------------------------------------------------

it('ands the supplier, category and range together', function () {
    $acme = GeneralStockSupplier::create(['name' => 'Acme', 'is_active' => true]);

    $wanted = rangeItem('Acme Blade', ['category' => 'Blade']);
    $wrongCategory = rangeItem('Acme Pen', ['category' => 'Pen']);

    rangeReceipt($wanted, 10, '2026-08-02', $acme->id);
    rangeReceipt($wrongCategory, 10, '2026-08-02', $acme->id);

    $names = app(GeneralStockReportService::class)
        ->rows(\Illuminate\Support\Carbon::parse('2026-08-01'), [
            'only_active' => false,
            'supplier_id' => $acme->id,
            'category' => 'Blade',
            'from' => '2026-08-01',
            'to' => '2026-08-31',
        ])
        ->map(fn ($r) => $r['item']->name)
        ->all();

    expect($names)->toBe(['Acme Blade']);
});

// --- The screen and the exports ----------------------------------------------

it('offers the supplier dropdown on the report screen', function () {
    GeneralStockSupplier::create(['name' => 'Acme Traders', 'is_active' => true]);
    GeneralStockSupplier::create(['name' => 'Retired Traders', 'is_active' => false]);

    $this->actingAs(stockReportUser())
        ->get(route('store.stock.ledger'))
        ->assertOk()
        ->assertSee('From Date')
        ->assertSee('To Date')
        ->assertSee('Acme Traders')
        // Inactive suppliers are not offered for a new filter.
        ->assertDontSee('Retired Traders');
});

it('says which period is in force when a range is applied', function () {
    $this->actingAs(stockReportUser())
        ->get(route('store.stock.ledger', ['from' => '2026-08-05', 'to' => '2026-08-09']))
        ->assertOk()
        ->assertSee('05 Aug 2026 to 09 Aug 2026');
});

it('refuses a backwards range', function () {
    $this->actingAs(stockReportUser())
        ->get(route('store.stock.ledger', ['from' => '2026-08-20', 'to' => '2026-08-01']))
        ->assertSessionHasErrors('to');
});

it('applies the filters to the excel export, not the full list', function () {
    $acme = GeneralStockSupplier::create(['name' => 'Acme', 'is_active' => true]);

    $wanted = rangeItem('Acme Pen');
    $unwanted = rangeItem('Other Pen');

    rangeReceipt($wanted, 10, '2026-08-02', $acme->id);
    rangeReceipt($unwanted, 10, '2026-08-02');

    $response = $this->actingAs(stockReportUser())
        ->get(route('store.stock.ledger.excel', [
            'month' => '2026-08',
            'supplier_id' => $acme->id,
            'include_inactive' => '1',
        ]));

    $response->assertOk();

    // The filename names the month, unchanged for a non-range export.
    expect($response->headers->get('content-disposition'))
        ->toContain('Stock-Report-2026-08.xlsx');
});

it('names the range in the export filename so two periods cannot collide', function () {
    $response = $this->actingAs(stockReportUser())
        ->get(route('store.stock.ledger.excel', ['from' => '2026-08-05', 'to' => '2026-08-09']));

    $response->assertOk();

    expect($response->headers->get('content-disposition'))
        ->toContain('05-aug-2026-to-09-aug-2026');
});

it('renders the pdf with a range and a supplier applied', function () {
    // The PDF branch of ledger-pdf.blade is the half the Excel export does not
    // exercise, and it is where the period caption and the supplier name print.
    $acme = GeneralStockSupplier::create(['name' => 'Acme', 'is_active' => true]);
    $item = rangeItem('Acme Pen');
    rangeReceipt($item, 10, '2026-08-06', $acme->id);

    $this->actingAs(stockReportUser())
        ->get(route('store.stock.ledger.pdf', [
            'from' => '2026-08-05',
            'to' => '2026-08-09',
            'supplier_id' => $acme->id,
            'include_inactive' => '1',
        ]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});
