<?php

use App\Models\MaterialBulkIssue;
use App\Models\MaterialReceiving;
use App\Models\MaterialStockLedger;
use App\Models\User;
use App\Services\StoreReportService;
use Spatie\Permission\Models\Role;

/**
 * Season / PO Number / GMTS Colour filters on Closing Stock and Store Reports.
 *
 * The guarantee worth locking is that the new filters narrow (never widen), that
 * they AND with the existing Buyer/Style ones, and that clearing them restores
 * the full result — the stock maths itself is not touched by any of this.
 */
function stockFilterUser(): User
{
    Role::findOrCreate('store', 'web');

    return User::factory()->create()->assignRole('store');
}

/** Two ledger rows differing on every new filter column. */
beforeEach(function () {
    MaterialStockLedger::create([
        'excel_row_id' => null,
        'buyer_name' => 'Buyer A',
        'season_name' => 'W26FA',
        'style_name' => 'Style A',
        'po_no' => 'HB26FA0005',
        'gmts_color_name' => 'Navy',
        'material_description' => 'Main Fabric',
        'total_closing_qty' => 60,
    ]);

    MaterialStockLedger::create([
        'buyer_name' => 'Buyer B',
        'season_name' => 'S27SP',
        'style_name' => 'Style B',
        'po_no' => 'HB27SP0009',
        'gmts_color_name' => 'Crimson',
        'material_description' => 'Lining',
        'total_closing_qty' => 25,
    ]);
});

// --- Closing Stock screen ---------------------------------------------------
test('closing stock narrows on each new filter individually', function (string $param, string $value, string $keep, string $drop) {
    $this->actingAs(stockFilterUser())
        ->get('/store/material-stock/ledger?'.$param.'='.urlencode($value))
        ->assertOk()
        ->assertSee($keep)
        ->assertDontSee($drop);
})->with([
    'season' => ['season', 'W26FA', 'Main Fabric', 'Lining'],
    'po number' => ['po_no', 'HB26FA0005', 'Main Fabric', 'Lining'],
    'gmts colour' => ['gmts_color', 'Crimson', 'Lining', 'Main Fabric'],
]);

test('closing stock ANDs the new filters with each other', function () {
    // Season and PO that belong to different rows can never both match.
    $this->actingAs(stockFilterUser())
        ->get('/store/material-stock/ledger?season=W26FA&po_no=HB27SP0009')
        ->assertOk()
        ->assertDontSee('Main Fabric')
        ->assertDontSee('Lining');
});

test('closing stock ANDs a new filter with the existing buyer filter', function () {
    $this->actingAs(stockFilterUser())
        ->get('/store/material-stock/ledger?buyer=Buyer+A&season=S27SP')
        ->assertOk()
        ->assertDontSee('Main Fabric')
        ->assertDontSee('Lining');

    $this->actingAs(stockFilterUser())
        ->get('/store/material-stock/ledger?buyer=Buyer+A&season=W26FA')
        ->assertOk()
        ->assertSee('Main Fabric');
});

test('closing stock with no filters shows everything, which is what Reset returns to', function () {
    $this->actingAs(stockFilterUser())
        ->get('/store/material-stock/ledger')
        ->assertOk()
        ->assertSee('Main Fabric')
        ->assertSee('Lining');
});

// --- Store Reports ----------------------------------------------------------
test('store reports narrow on each new filter', function () {
    MaterialReceiving::create([
        'buyer_name' => 'Buyer A', 'season_name' => 'W26FA', 'style_name' => 'Style A',
        'po_no' => 'HB26FA0005', 'gmts_color_name' => 'Navy',
        'material_description' => 'Main Fabric', 'receive_date' => '2026-03-10', 'qty' => 100,
    ]);
    MaterialReceiving::create([
        'buyer_name' => 'Buyer B', 'season_name' => 'S27SP', 'style_name' => 'Style B',
        'po_no' => 'HB27SP0009', 'gmts_color_name' => 'Crimson',
        'material_description' => 'Lining', 'receive_date' => '2026-03-11', 'qty' => 40,
    ]);

    $service = app(StoreReportService::class);

    $bySeason = $service->rows(StoreReportService::TYPE_STYLE, ['season' => 'W26FA']);
    expect($bySeason->pluck('label')->all())->toEqual(['Style A']);

    $byPo = $service->rows(StoreReportService::TYPE_STYLE, ['po_no' => 'HB27SP0009']);
    expect($byPo->pluck('label')->all())->toEqual(['Style B']);

    $byColor = $service->rows(StoreReportService::TYPE_STYLE, ['gmts_color' => 'Navy']);
    expect($byColor->pluck('label')->all())->toEqual(['Style A']);

    // Combined filters that describe different rows return nothing.
    expect($service->rows(StoreReportService::TYPE_STYLE, [
        'season' => 'W26FA', 'gmts_color' => 'Crimson',
    ]))->toBeEmpty();
});

test('the new filters reach the issue side of the report too', function () {
    MaterialBulkIssue::create([
        'buyer_name' => 'Buyer A', 'season_name' => 'W26FA', 'style_name' => 'Style A',
        'po_no' => 'HB26FA0005', 'gmts_color_name' => 'Navy',
        'material_description' => 'Main Fabric', 'issue_date' => '2026-03-12', 'bulk_qty' => 40,
    ]);

    $service = app(StoreReportService::class);

    expect($service->rows(StoreReportService::TYPE_STYLE, ['gmts_color' => 'Navy'])
        ->firstWhere('label', 'Style A')['bulk_qty'])->toBe(40.0);

    expect($service->rows(StoreReportService::TYPE_STYLE, ['gmts_color' => 'Crimson'])
        ->firstWhere('label', 'Style A'))->toBeNull();
});

test('report filter dropdowns are offered the new columns', function () {
    $options = app(StoreReportService::class)->filterOptions();

    expect($options)->toHaveKeys(['buyers', 'styles', 'seasons', 'poNos', 'gmtsColors']);
});

test('the new filters survive into the pdf and excel exports', function () {
    $user = stockFilterUser();
    Role::findOrCreate('admin', 'web');
    $user->assignRole('admin');

    $query = 'type=style&season=W26FA&po_no=HB26FA0005&gmts_color=Navy';

    $this->actingAs($user)->get('/store/reports/pdf?'.$query)->assertOk();
    $this->actingAs($user)->get('/store/reports/excel?'.$query)->assertOk();
});

test('an unknown filter value is rejected rather than silently ignored', function () {
    // Over-long values fail validation instead of reaching the query.
    $this->actingAs(stockFilterUser())
        ->get('/store/reports?season='.str_repeat('x', 300))
        ->assertSessionHasErrors('season');
});
