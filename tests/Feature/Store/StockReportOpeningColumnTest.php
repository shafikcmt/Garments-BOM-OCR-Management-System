<?php

use App\Models\StockIssue;
use App\Models\StockItem;
use App\Models\StockPurchase;
use App\Models\User;
use App\Services\GeneralStockReportService;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Stock Report — the single Opening Stock column.
 *
 * The report used to print TWO opening-shaped columns: "Opening Stock", the
 * frozen counted figure off the Item Master, and "Balance B/F", the running
 * carry-forward. The frozen one reads the same in every month, so from an
 * item's second month onward the column headed Opening did not reconcile with
 * the report's own Stock as on Date — and the report's banner and PDF footnote
 * both asserted that it did.
 *
 * The client reconciles this sheet by hand against their Excel, where there is
 * ONE opening column (Stock <Month> column H) holding last month's closing.
 * So the carry-forward is now the only opening column and carries that name,
 * and the counted seed moved to the Item Master list where it belongs.
 *
 * The arithmetic never changed — GeneralStockReportService is untouched. These
 * tests pin the presentation, which is where the defect was.
 *
 * In-memory sqlite. No real record touched.
 */
function openingItem(string $name, array $attributes = []): StockItem
{
    return StockItem::create(array_merge([
        'name' => $name, 'uom' => 'Pcs', 'category' => 'General', 'is_active' => true,
    ], $attributes));
}

function openingReportUser(): User
{
    Role::findOrCreate('store', 'web');
    Permission::findOrCreate('store.items.view', 'web');

    $user = User::factory()->create()->assignRole('store');
    $user->givePermissionTo('store.items.view');

    return $user->fresh();
}

function openingRow(StockItem $item, string $month): array
{
    return app(GeneralStockReportService::class)
        ->rows(Carbon::parse($month.'-01'), ['item_ids' => [$item->id], 'only_active' => false])
        ->first();
}

// --- The carry-forward itself ------------------------------------------------

it('carries last month closing into this month opening', function () {
    // The shape of the client's complaint: activity in August, and September
    // must open where August closed rather than back at the counted seed.
    $item = openingItem('Air Freshner Spray', [
        'opening_qty' => 55, 'opening_as_on' => '2026-08-01',
    ]);

    StockIssue::create(['stock_item_id' => $item->id, 'issue_date' => '2026-08-12', 'qty' => 1]);

    $august = openingRow($item, '2026-08');
    $september = openingRow($item, '2026-09');

    expect($august['balance_bf'])->toBe(55.0)
        ->and($august['consumption'])->toBe(1.0)
        ->and($august['stock_as_on'])->toBe(54.0)
        // September opens at August's close, NOT at the counted 55.
        ->and($september['balance_bf'])->toBe(54.0)
        ->and($september['stock_as_on'])->toBe(54.0);
});

it('opens the following month at zero when a month issues everything out', function () {
    // The starkest case on live data: seeded 230, all 230 issued in August.
    // The frozen column still printed 230 as September's opening.
    $item = openingItem('Cosco Soap-Mini', [
        'opening_qty' => 230, 'opening_as_on' => '2026-08-01',
    ]);

    StockIssue::create(['stock_item_id' => $item->id, 'issue_date' => '2026-08-20', 'qty' => 230]);

    expect(openingRow($item, '2026-08')['stock_as_on'])->toBe(0.0)
        ->and(openingRow($item, '2026-09')['balance_bf'])->toBe(0.0);
});

it('seeds a new mid-year item from its counted opening in its first month', function () {
    // Removing the frozen column must not cost a NEW item its seed: the seed
    // is the base term of the carry-forward, so the first reporting month
    // opens at exactly the counted figure.
    $item = openingItem('ZZ Seed Item', [
        'opening_qty' => 100, 'opening_as_on' => '2026-09-01',
    ]);

    StockPurchase::create([
        'stock_item_id' => $item->id, 'purchase_date' => '2026-09-10',
        'rcv_date' => '2026-09-10', 'qty' => 40, 'unit_price' => 10,
    ]);
    StockIssue::create(['stock_item_id' => $item->id, 'issue_date' => '2026-09-20', 'qty' => 15]);
    StockIssue::create(['stock_item_id' => $item->id, 'issue_date' => '2026-10-05', 'qty' => 30]);

    $september = openingRow($item, '2026-09');
    $october = openingRow($item, '2026-10');

    expect($september['balance_bf'])->toBe(100.0)     // the seed, intact
        ->and($september['stock_as_on'])->toBe(125.0) // 100 + 40 - 15
        ->and($october['balance_bf'])->toBe(125.0)    // September's close
        ->and($october['stock_as_on'])->toBe(95.0);   // 125 + 0 - 30
});

// --- What the screen actually prints -----------------------------------------

it('prints one opening column on the report and no frozen counted column', function () {
    $item = openingItem('Marker Pen', ['opening_qty' => 55, 'opening_as_on' => '2026-08-01']);
    StockIssue::create(['stock_item_id' => $item->id, 'issue_date' => '2026-08-12', 'qty' => 1]);

    $html = $this->actingAs(openingReportUser())
        ->get(route('store.stock.ledger', ['month' => '2026-09']))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Opening Stock')
        // The old carry-forward heading is gone: it IS Opening Stock now.
        ->and($html)->not->toContain('Balance B/F')
        // And the note that used to excuse the duplicate pair went with it.
        ->and($html)->not->toContain('never changes');
});

it('states the formula the report actually computes', function () {
    // The banner used to read "Opening + Addition - Consumption = Stock as on
    // Date" while the column headed Opening was the frozen one, so the stated
    // equation failed on 221 of 1,241 rows in September against live data.
    $html = $this->actingAs(openingReportUser())
        ->get(route('store.stock.ledger', ['month' => '2026-09']))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Opening Stock + Addition − Consumption = Stock as on Date');
});

it('shows September opening as August closing on the report screen', function () {
    $item = openingItem('Broom Flower', ['opening_qty' => 50, 'opening_as_on' => '2026-08-01']);
    StockIssue::create(['stock_item_id' => $item->id, 'issue_date' => '2026-08-14', 'qty' => 25]);

    // Rendered, not just computed: the defect was that the right number existed
    // in the service and the wrong one reached the page.
    $this->actingAs(openingReportUser())
        ->get(route('store.stock.ledger', ['month' => '2026-09']))
        ->assertOk()
        ->assertSee('Broom Flower')
        ->assertDontSee('>50<', false);   // the frozen seed is no longer a cell
});

// --- The PDF / Excel table ----------------------------------------------------

it('keeps the PDF and Excel total row aligned with its header columns', function () {
    // _report-table is the partial BOTH downloads render, and its footer is
    // hand-built from colspans just like the screen's. The screen has had this
    // guard; the download has not, and a total shifted one column sideways in a
    // management PDF looks like a plausible number rather than a broken page.
    $item = openingItem('Cotton Thread White', [
        'opening_qty' => 5000, 'opening_as_on' => '2026-07-01',
    ]);
    StockIssue::create(['stock_item_id' => $item->id, 'issue_date' => '2026-08-05', 'qty' => 200]);

    $svc = app(GeneralStockReportService::class);
    $rows = $svc->rows(Carbon::parse('2026-08-01'), ['only_active' => false]);

    $html = view('store.stock._report-table', [
        'rows' => $rows,
        'summary' => $svc->summary($rows),
        'forExcel' => true,
    ])->render();

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    libxml_clear_errors();
    $xpath = new DOMXPath($doc);

    $headCols = $xpath->query('//thead/tr/th')->length;

    $footCols = 0;
    foreach ($xpath->query('//tfoot/tr/td') as $td) {
        $footCols += max(1, (int) $td->getAttribute('colspan'));
    }

    expect($headCols)->toBe(19)
        ->and($footCols)->toBe($headCols)
        ->and($html)->toContain('Opening Stock')
        ->and($html)->not->toContain('Balance B/F');
});

// --- The Item Master keeps the seed visible ----------------------------------

it('shows the counted opening quantity on the Item Master list', function () {
    // The seed left the report, so it has to be readable here or a plain Store
    // user could only get it by downloading the Item Master workbook.
    openingItem('Numbering Machine', ['opening_qty' => 100, 'opening_as_on' => '2026-08-01']);

    $this->actingAs(openingReportUser())
        ->get(route('store.stock.items.index'))
        ->assertOk()
        ->assertSee('Opening Stock')
        ->assertSee('100')
        ->assertSee('as on 01-Aug-26');
});

it('shows the counted opening to a view-only user with no edit rights', function () {
    // The Edit modal carries opening_qty too, but it is gated on store.edit.
    // This column must not be, or the gap this fix was meant to close reopens.
    openingItem('Cutting Knife', ['opening_qty' => 42, 'opening_as_on' => '2026-07-31']);

    $user = openingReportUser();
    expect($user->can('store.edit'))->toBeFalse();

    $this->actingAs($user)
        ->get(route('store.stock.items.index'))
        ->assertOk()
        ->assertSee('Opening Stock')
        ->assertSee('42');
});
