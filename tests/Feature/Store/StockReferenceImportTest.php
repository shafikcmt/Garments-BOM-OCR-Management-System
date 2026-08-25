<?php

use App\Imports\StockReferenceImport;
use App\Models\StockIssue;
use App\Models\StockItem;
use App\Models\StockPurchase;
use App\Models\User;
use App\Services\GeneralStockReportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * Backfilling the Stock Report's three blank columns.
 *
 * Two guarantees are worth holding still, and both are negative:
 *
 *   IT FILLS BLANKS ONLY. A column already showing a value is never replaced,
 *   whatever the file says, and the three columns are decided independently —
 *   so an item missing only its price keeps the dates it has.
 *
 *   IT NEVER WRITES A TRANSACTION. stock_purchases and stock_issues are read
 *   and never written. The test at the bottom asserts both tables are unchanged
 *   after a full import rather than trusting that no code writes them.
 *
 * In-memory sqlite. No real record touched.
 */
function refItem(string $name, ?string $brand = null, array $attributes = []): StockItem
{
    return StockItem::create(array_merge([
        'name' => $name,
        'brand' => $brand,
        'uom' => 'Pkt',
        'category' => 'Needle',
        'opening_qty' => 25,
        'safety_stock_qty' => 10,
        'reorder_level' => 20,
        'lead_time_days' => 5,
        'remarks' => 'keep me',
    ], $attributes));
}

function refSheet(array $rows): array
{
    return array_merge([StockReferenceImport::COLUMNS], $rows);
}

function refUser(array $permissions = ['store.stock_report.view', 'store.edit']): User
{
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $user = User::factory()->create(['status' => 1]);
    $user->givePermissionTo($permissions);

    return $user;
}

function refPurchase(StockItem $item, ?string $date, ?float $price = null): StockPurchase
{
    return StockPurchase::create([
        'stock_item_id' => $item->id,
        'challan_no' => 'CH-1',
        'purchase_date' => $date,
        'qty' => 10,
        'unit_price' => $price,
    ]);
}

function refIssue(StockItem $item, string $date): StockIssue
{
    return StockIssue::create([
        'stock_item_id' => $item->id,
        'requisition_no' => 'RQ-1',
        'issue_date' => $date,
        'qty' => 2,
    ]);
}

/** Upload a sheet through the preview endpoint. */
function refUpload(array $rows, ?User $user = null)
{
    $csv = collect(refSheet($rows))
        ->map(fn ($row) => collect($row)->map(fn ($cell) => '"'.str_replace('"', '""', (string) $cell).'"')->implode(','))
        ->implode("\n")."\n";

    return test()->actingAs($user ?? refUser())->post(
        route('store.stock.ledger.import.preview'),
        ['file' => UploadedFile::fake()->createWithContent('reference.csv', $csv)]
    );
}

// --- It fills blanks only -----------------------------------------------------

it('fills all three columns for an item that is missing all three', function () {
    $item = refItem('Sewing Needle', 'Organ');

    $plan = StockReferenceImport::plan(refSheet([
        ['Sewing Needle', 'Organ', 12.5, '2026-01-15', '2026-02-20'],
    ]));

    expect($plan['errors'])->toBeEmpty()
        ->and($plan['updates'])->toHaveCount(1)
        ->and($plan['updates'][0]['id'])->toBe($item->id)
        ->and($plan['updates'][0]['values'])->toBe([
            'unit_price' => 12.5,
            'last_addition' => '2026-01-15',
            'last_consumption' => '2026-02-20',
        ])
        ->and($plan['counts'])->toBe([
            'unit_price' => 1,
            'last_addition' => 1,
            'last_consumption' => 1,
        ]);
});

it('fills only the missing column and leaves the two that are already set', function () {
    // The case the whole feature turns on: three independent decisions, not one.
    $item = refItem('Sewing Needle', 'Organ', [
        'last_addition_reference' => '2025-05-05',
        'last_consumption_reference' => '2025-06-06',
    ]);

    $plan = StockReferenceImport::plan(refSheet([
        ['Sewing Needle', 'Organ', 12.5, '2026-01-15', '2026-02-20'],
    ]));

    expect($plan['updates'])->toHaveCount(1)
        ->and($plan['updates'][0]['values'])->toBe(['unit_price' => 12.5])
        ->and($plan['counts'])->toBe([
            'unit_price' => 1,
            'last_addition' => 0,
            'last_consumption' => 0,
        ])
        ->and(implode(' ', $plan['skipped']))->toContain('already has a Last Addition Date');

    // And nothing was written by planning.
    expect($item->fresh()->last_addition_reference->toDateString())->toBe('2025-05-05');
});

it('never overwrites a value that is already there, even when the file differs', function () {
    $item = refItem('Sewing Needle', 'Organ', [
        'unit_price' => 9.0,
        'last_addition_reference' => '2025-05-05',
        'last_consumption_reference' => '2025-06-06',
    ]);

    refUpload([['Sewing Needle', 'Organ', 99.0, '2026-01-15', '2026-02-20']])
        ->assertRedirect()
        ->assertSessionHas('warning');

    $fresh = $item->fresh();

    expect((float) $fresh->unit_price)->toBe(9.0)
        ->and($fresh->last_addition_reference->toDateString())->toBe('2025-05-05')
        ->and($fresh->last_consumption_reference->toDateString())->toBe('2025-06-06');
});

it('leaves a column alone when its cell is blank rather than clearing it', function () {
    $item = refItem('Sewing Needle', 'Organ');

    $plan = StockReferenceImport::plan(refSheet([
        ['Sewing Needle', 'Organ', '', '', '2026-02-20'],
    ]));

    expect($plan['errors'])->toBeEmpty()
        ->and($plan['updates'][0]['values'])->toBe(['last_consumption' => '2026-02-20']);
});

// --- A real transaction always wins -------------------------------------------

it('skips the date columns for an item that already has a purchase and an issue', function () {
    $item = refItem('Sewing Needle', 'Organ');
    refPurchase($item, '2026-03-01');
    refIssue($item, '2026-03-05');

    $plan = StockReferenceImport::plan(refSheet([
        ['Sewing Needle', 'Organ', 12.5, '2026-01-15', '2026-02-20'],
    ]));

    expect($plan['updates'][0]['values'])->toBe(['unit_price' => 12.5])
        ->and($plan['counts']['last_addition'])->toBe(0)
        ->and($plan['counts']['last_consumption'])->toBe(0);
});

it('skips unit price when a priced challan exists', function () {
    $item = refItem('Sewing Needle', 'Organ');
    refPurchase($item, '2026-03-01', 44.0);

    $plan = StockReferenceImport::plan(refSheet([
        ['Sewing Needle', 'Organ', 12.5, '2026-01-15', '2026-02-20'],
    ]));

    // The purchase blocks the price AND the addition date; only consumption is
    // still blank.
    expect($plan['updates'][0]['values'])->toBe(['last_consumption' => '2026-02-20']);
});

it('still fills unit price when a purchase carries no price', function () {
    // An unpriced challan is an addition without a rate, so the Value column is
    // still blank even though the date column is not.
    $item = refItem('Sewing Needle', 'Organ');
    refPurchase($item, '2026-03-01', null);

    $plan = StockReferenceImport::plan(refSheet([
        ['Sewing Needle', 'Organ', 12.5, '2026-01-15', '2026-02-20'],
    ]));

    expect($plan['updates'][0]['values'])->toBe([
        'unit_price' => 12.5,
        'last_consumption' => '2026-02-20',
    ]);
});

it('reads transactions without a date bound, so a later month still blocks a reference', function () {
    // The report bounds its own lookup at the period on screen, so this item
    // looks blank while January is displayed. A reference date that contradicted
    // a real movement in another month would be worse than a blank cell.
    $item = refItem('Sewing Needle', 'Organ');
    refPurchase($item, '2026-09-30');

    $plan = StockReferenceImport::plan(refSheet([
        ['Sewing Needle', 'Organ', 12.5, '2026-01-15', ''],
    ]));

    expect($plan['counts']['last_addition'])->toBe(0);
});

// --- Name and brand together --------------------------------------------------

it('backfills the right make when one name is stocked under two brands', function () {
    $generic = refItem('B/S Needle DPX17-09', 'Generic');
    $organ = refItem('B/S Needle DPX17-09', 'Organ');

    $plan = StockReferenceImport::plan(refSheet([
        ['B/S Needle DPX17-09', 'Organ', 15, '2026-01-15', ''],
    ]));

    expect($plan['updates'])->toHaveCount(1)
        ->and($plan['updates'][0]['id'])->toBe($organ->id)
        ->and($plan['updates'][0]['id'])->not->toBe($generic->id);
});

it('reports a row matching no item and creates nothing', function () {
    refItem('Sewing Needle', 'Organ');

    $before = StockItem::count();

    $plan = StockReferenceImport::plan(refSheet([
        ['Sewing Needel', 'Organ', 12.5, '', ''],
    ]));

    expect($plan['updates'])->toBeEmpty()
        ->and(implode(' ', $plan['errors']))->toContain('is not in the item master')
        ->and(StockItem::count())->toBe($before);
});

it('rejects a date it cannot read rather than skipping the row quietly', function () {
    refItem('Sewing Needle', 'Organ');

    $plan = StockReferenceImport::plan(refSheet([
        ['Sewing Needle', 'Organ', '', '13-14 Jan', ''],
    ]));

    expect($plan['updates'])->toBeEmpty()
        ->and(implode(' ', $plan['errors']))->toContain('could not be read as a date');
});

it('uses the first row when one item is listed twice', function () {
    refItem('Sewing Needle', 'Organ');

    $plan = StockReferenceImport::plan(refSheet([
        ['Sewing Needle', 'Organ', 10, '', ''],
        ['Sewing Needle', 'Organ', 20, '', ''],
    ]));

    expect($plan['updates'])->toHaveCount(1)
        ->and($plan['updates'][0]['values']['unit_price'])->toBe(10.0)
        ->and(implode(' ', $plan['skipped']))->toContain('listed more than once');
});

// --- Preview writes nothing; apply writes what was previewed -------------------

it('writes nothing at the preview step', function () {
    $item = refItem('Sewing Needle', 'Organ');

    refUpload([['Sewing Needle', 'Organ', 12.5, '2026-01-15', '2026-02-20']])
        ->assertOk()
        ->assertViewIs('store.stock.import-preview')
        ->assertSee('Import Preview');

    $fresh = $item->fresh();

    expect($fresh->unit_price)->toBeNull()
        ->and($fresh->last_addition_reference)->toBeNull()
        ->and($fresh->last_consumption_reference)->toBeNull();
});

it('shows a count for each column on the preview', function () {
    refItem('Needle A', 'Organ');
    refItem('Needle B', 'Organ', ['unit_price' => 5.0]);

    $response = refUpload([
        ['Needle A', 'Organ', 12.5, '2026-01-15', '2026-02-20'],
        ['Needle B', 'Organ', 30.0, '2026-01-16', ''],
    ])->assertOk();

    $counts = $response->viewData('counts');

    expect($counts)->toBe([
        'unit_price' => 1,      // Needle B already has one
        'last_addition' => 2,
        'last_consumption' => 1,
    ]);
});

it('applies the previewed plan on confirm and only then', function () {
    $item = refItem('Sewing Needle', 'Organ');

    $user = refUser();
    $token = refUpload([['Sewing Needle', 'Organ', 12.5, '2026-01-15', '2026-02-20']], $user)
        ->assertOk()
        ->viewData('token');

    expect($item->fresh()->unit_price)->toBeNull();

    $this->actingAs($user)
        ->post(route('store.stock.ledger.import.apply'), ['token' => $token])
        ->assertRedirect(route('store.stock.ledger'))
        ->assertSessionHas('success');

    $fresh = $item->fresh();

    expect((float) $fresh->unit_price)->toBe(12.5)
        ->and($fresh->last_addition_reference->toDateString())->toBe('2026-01-15')
        ->and($fresh->last_consumption_reference->toDateString())->toBe('2026-02-20')
        // Everything else on the row, untouched.
        ->and($fresh->uom)->toBe('Pkt')
        ->and($fresh->category)->toBe('Needle')
        ->and((float) $fresh->opening_qty)->toBe(25.0)
        ->and((float) $fresh->safety_stock_qty)->toBe(10.0)
        ->and($fresh->remarks)->toBe('keep me');
});

it('applies nothing twice, because the plan is spent on first use', function () {
    $item = refItem('Sewing Needle', 'Organ');
    $user = refUser();

    $token = refUpload([['Sewing Needle', 'Organ', 12.5, '', '']], $user)->viewData('token');

    $this->actingAs($user)->post(route('store.stock.ledger.import.apply'), ['token' => $token])
        ->assertSessionHas('success');

    $this->actingAs($user)->post(route('store.stock.ledger.import.apply'), ['token' => $token])
        ->assertRedirect(route('store.stock.ledger'))
        ->assertSessionHas('warning');
});

it('applies nothing for an unknown or expired token', function () {
    $item = refItem('Sewing Needle', 'Organ');

    $this->actingAs(refUser())
        ->post(route('store.stock.ledger.import.apply'), ['token' => 'not-a-real-token'])
        ->assertRedirect(route('store.stock.ledger'))
        ->assertSessionHas('warning');

    expect($item->fresh()->unit_price)->toBeNull();
});

it('will not let one user apply another user plan', function () {
    $item = refItem('Sewing Needle', 'Organ');

    $token = refUpload([['Sewing Needle', 'Organ', 12.5, '', '']], refUser())->viewData('token');

    $this->actingAs(refUser())
        ->post(route('store.stock.ledger.import.apply'), ['token' => $token])
        ->assertSessionHas('warning');

    expect($item->fresh()->unit_price)->toBeNull();
});

// --- It never writes a transaction --------------------------------------------

it('leaves every transaction table byte-identical after a full import', function () {
    // The guarantee that makes this feature safe at all. A backdated purchase or
    // issue invented to move a derived date would be counted by closing stock,
    // the ledger and every figure downstream.
    $item = refItem('Sewing Needle', 'Organ');
    $other = refItem('Needle B', 'Organ');
    refPurchase($other, '2026-03-01', 44.0);
    refIssue($other, '2026-03-05');

    $before = [
        'purchases' => DB::table('stock_purchases')->orderBy('id')->get()->toJson(),
        'issues' => DB::table('stock_issues')->orderBy('id')->get()->toJson(),
    ];

    $user = refUser();
    $token = refUpload([
        ['Sewing Needle', 'Organ', 12.5, '2026-01-15', '2026-02-20'],
        ['Needle B', 'Organ', 99.0, '2026-01-16', '2026-02-21'],
    ], $user)->viewData('token');

    $this->actingAs($user)->post(route('store.stock.ledger.import.apply'), ['token' => $token])
        ->assertSessionHas('success');

    expect(DB::table('stock_purchases')->orderBy('id')->get()->toJson())->toBe($before['purchases'])
        ->and(DB::table('stock_issues')->orderBy('id')->get()->toJson())->toBe($before['issues']);
});

// --- The report reads a reference only as a fallback ---------------------------

it('shows a reference date only when no transaction exists, and marks it', function () {
    $item = refItem('Sewing Needle', 'Organ', [
        'last_addition_reference' => '2025-05-05',
        'last_consumption_reference' => '2025-06-06',
    ]);

    $rows = app(GeneralStockReportService::class)->rows(Carbon::parse('2026-08-01'));
    $row = collect($rows)->firstWhere('item.id', $item->id);

    expect($row['last_addition_date']->toDateString())->toBe('2025-05-05')
        ->and($row['last_addition_is_reference'])->toBeTrue()
        ->and($row['last_consumption_is_reference'])->toBeTrue();
});

it('lets a real transaction win over a reference date', function () {
    $item = refItem('Sewing Needle', 'Organ', [
        'last_addition_reference' => '2025-05-05',
    ]);
    refPurchase($item, '2026-08-10');

    $rows = app(GeneralStockReportService::class)->rows(Carbon::parse('2026-08-01'));
    $row = collect($rows)->firstWhere('item.id', $item->id);

    expect($row['last_addition_date']->toDateString())->toBe('2026-08-10')
        ->and($row['last_addition_is_reference'])->toBeFalse();
});

it('closes the value column once a reference price is in place', function () {
    // Closing Value = Stock as on Date x Unit Price, so a filled-in price is
    // what makes the column computable at all.
    $item = refItem('Sewing Needle', 'Organ', ['unit_price' => 4.0]);

    $rows = app(GeneralStockReportService::class)->rows(Carbon::parse('2026-08-01'));
    $row = collect($rows)->firstWhere('item.id', $item->id);

    expect((float) $row['unit_price'])->toBe(4.0)
        ->and($row['unit_price_is_standard'])->toBeTrue()
        ->and((float) $row['closing_value'])->toBe(100.0);
});

// --- Permission ---------------------------------------------------------------

it('refuses both endpoints to a user without the correction right', function () {
    $viewer = refUser(['store.stock_report.view']);

    $this->actingAs($viewer)
        ->post(route('store.stock.ledger.import.preview'), [
            'file' => UploadedFile::fake()->createWithContent('reference.csv', 'Item Name*'."\n"),
        ])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->post(route('store.stock.ledger.import.apply'), ['token' => 'anything'])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->get(route('store.stock.ledger.import.template'))
        ->assertForbidden();
});

it('shows the Import control only to a user who may correct records', function () {
    // The modal branch on the report screen. Hiding it is presentation only —
    // the endpoints check again — but a control nobody can use is still wrong.
    $this->actingAs(refUser())
        ->get(route('store.stock.ledger'))
        ->assertOk()
        ->assertSee('Import Reference Data');

    $this->actingAs(refUser(['store.stock_report.view']))
        ->get(route('store.stock.ledger'))
        ->assertOk()
        ->assertDontSee('Import Reference Data');
});

/*
|--------------------------------------------------------------------------
| The pre-filled template
|--------------------------------------------------------------------------
|
| The sheet lists the items that need work and shows, greyed, what they
| already have. Both readings come from StockReferenceImport::gaps(), the same
| one the import uses, so the template can never offer a column the import
| would refuse.
*/

/** The template as a plain array of rows. */
function refTemplate(): array
{
    return (new App\Exports\StockReferenceTemplateExport)->array();
}

/** Its data rows: the note and the headings dropped. */
function refTemplateRows(): array
{
    return array_slice(refTemplate(), 2);
}

it('puts the note above the headings, where the importer skips it', function () {
    $template = refTemplate();

    expect($template[0][0])->toContain('Only items missing at least one value')
        ->and($template[1])->toBe(StockReferenceImport::COLUMNS);
});

it('lists an item that is missing all three fields', function () {
    refItem('Sewing Needle', 'Organ');

    $rows = refTemplateRows();

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toBe(['Sewing Needle', 'Organ', null, null, null]);
});

it('leaves out an item that is already complete', function () {
    $complete = refItem('Complete Item', 'Organ', ['unit_price' => 5.0]);
    refPurchase($complete, '2026-03-01');
    refIssue($complete, '2026-03-05');

    refItem('Needs Work', 'Generic');

    $names = collect(refTemplateRows())->pluck(0);

    expect($names->all())->toBe(['Needs Work'])
        ->and($names)->not->toContain('Complete Item');
});

it('shows the values an item already has and leaves only the missing ones blank', function () {
    $item = refItem('Sewing Needle', 'Organ', ['unit_price' => 7.25]);
    refPurchase($item, '2026-03-01');

    // Price and last addition are recorded; consumption is not.
    $rows = refTemplateRows();

    expect($rows)->toHaveCount(1)
        ->and($rows[0][0])->toBe('Sewing Needle')
        ->and($rows[0][1])->toBe('Organ')
        ->and((float) $rows[0][2])->toBe(7.25)
        ->and($rows[0][3])->toBe('2026-03-01')
        ->and($rows[0][4])->toBeNull();
});

it('shows the challan price rather than the master price, as the report does', function () {
    $item = refItem('Sewing Needle', 'Organ', ['unit_price' => 7.25]);
    refPurchase($item, '2026-03-01', 44.0);

    $rows = refTemplateRows();

    expect((float) $rows[0][2])->toBe(44.0);
});

it('shows a reference date for an item that has one but no transaction', function () {
    refItem('Sewing Needle', 'Organ', ['last_consumption_reference' => '2025-06-06']);

    $rows = refTemplateRows();

    expect($rows[0][4])->toBe('2025-06-06')
        ->and($rows[0][3])->toBeNull();
});

it('leaves the cell empty for an undated transaction, which stays unwritable', function () {
    // The known edge case: a purchase carrying no date means the item HAS an
    // addition with nothing to print for it. The cell is blank, and the import
    // still refuses to write there.
    $item = refItem('Sewing Needle', 'Organ');
    refPurchase($item, null);

    $rows = refTemplateRows();

    expect($rows[0][3])->toBeNull();

    $plan = StockReferenceImport::plan(refSheet([
        ['Sewing Needle', 'Organ', '', '2026-01-15', ''],
    ]));

    expect($plan['counts']['last_addition'])->toBe(0)
        ->and(implode(' ', $plan['skipped']))->toContain('already has a Last Addition Date');
});

it('produces an empty sheet when every item is complete', function () {
    $item = refItem('Complete Item', 'Organ', ['unit_price' => 5.0]);
    refPurchase($item, '2026-03-01');
    refIssue($item, '2026-03-05');

    expect(refTemplateRows())->toBeEmpty();
});

it('writes nothing when the template is uploaded back untouched', function () {
    // The round trip. Every value on the sheet is one the database already has,
    // so a straight re-upload is a no-op — which is what makes it safe to print
    // existing values into editable cells.
    $withSome = refItem('Half Done', 'Organ', ['unit_price' => 7.25]);
    refPurchase($withSome, '2026-03-01');
    refItem('Nothing Yet', 'Generic');
    $complete = refItem('Complete Item', 'Acme', ['unit_price' => 5.0]);
    refPurchase($complete, '2026-03-01');
    refIssue($complete, '2026-03-05');

    $plan = StockReferenceImport::plan(refTemplate());

    expect($plan['errors'])->toBeEmpty()
        ->and($plan['updates'])->toBeEmpty()
        ->and($plan['counts'])->toBe([
            'unit_price' => 0,
            'last_addition' => 0,
            'last_consumption' => 0,
        ]);
});

it('writes only the cell that was filled in when the template comes back edited', function () {
    $item = refItem('Half Done', 'Organ', ['unit_price' => 7.25]);
    refPurchase($item, '2026-03-01');

    $sheet = refTemplate();

    // The user types the one missing value and, by accident, overtypes a grey
    // one. Only the genuinely missing field is written.
    $sheet[2][2] = 999.0;             // Unit Price — already set
    $sheet[2][4] = '2026-02-20';      // Last Consumption — the real gap

    $plan = StockReferenceImport::plan($sheet);

    expect($plan['updates'])->toHaveCount(1)
        ->and($plan['updates'][0]['values'])->toBe(['last_consumption' => '2026-02-20'])
        ->and($plan['counts']['unit_price'])->toBe(0);
});

it('reads the item list without a query per item', function () {
    foreach (range(1, 25) as $n) {
        refItem('Item '.$n, 'Organ');
    }

    DB::enableQueryLog();
    refTemplate();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Four grouped reads inside gaps(), plus the item list itself.
    expect($queries)->toBeLessThanOrEqual(6);
});

it('marks a reference date on the Stock Report screen, not just in the PDF', function () {
    // The screen renders _ledger-rows.blade.php; the PDF and Excel render
    // _report-table.blade.php. The marker was added to the second and missed on
    // the first, so the report showed a reference date indistinguishable from a
    // real one. Both partials are asserted here.
    $reference = refItem('Needs A Marker', 'Organ', [
        'last_consumption_reference' => '2026-02-20',
    ]);

    $real = refItem('Has A Real Movement', 'Organ');
    refIssue($real, '2026-08-11');

    $response = $this->actingAs(refUser())
        ->get(route('store.stock.ledger', ['month' => '2026-08']))
        ->assertOk();

    $html = $response->getContent();

    expect($html)->toContain('20-Feb-26')
        ->and($html)->toContain('bi-bookmark')
        // The real movement's date carries no marker: one bookmark on the page.
        ->and(substr_count($html, 'bi-bookmark'))->toBe(1)
        ->and($html)->toContain('11-Aug-26');
});
