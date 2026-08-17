<?php

use App\Exports\ItemMasterExport;
use App\Imports\ItemMasterImport;
use App\Imports\UnitPriceImport;
use App\Models\StockItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;

/**
 * Setting Unit Price across many items at once, and the export that feeds it.
 *
 * The guarantee worth holding still is a negative one: THIS WRITES ONE FIELD.
 * The tests check the other columns after every import, because the whole point
 * of a separate importer is that a stale file cannot reach them.
 *
 * The second is that it never creates. A row matching no item is a misspelling,
 * and an item carrying a price and nothing else would be a phantom in the Stock
 * Report.
 *
 * In-memory sqlite. No real record touched.
 */
function pricedItem(string $name, ?string $brand = null, array $attributes = []): StockItem
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

function priceSheet(array $rows): array
{
    return array_merge([UnitPriceImport::COLUMNS], $rows);
}

function priceUser(array $permissions = ['store.items.view', 'store.edit']): User
{
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $user = User::factory()->create(['status' => 1]);
    $user->givePermissionTo($permissions);

    return $user;
}

// --- It writes one field ------------------------------------------------------

it('sets the unit price and leaves every other field exactly as it was', function () {
    $item = pricedItem('Sewing Needle', 'Organ');

    $result = UnitPriceImport::parse(priceSheet([
        ['Sewing Needle', 'Organ', 12.5],
    ]));

    expect($result['errors'])->toBeEmpty()
        ->and($result['updates'])->toHaveCount(1)
        ->and($result['updates'][0]['id'])->toBe($item->id)
        ->and($result['updates'][0]['unit_price'])->toBe(12.5);
});

it('touches nothing but the price when it runs through the screen', function () {
    // The test that earns the separate importer: everything else must survive.
    $item = pricedItem('Sewing Needle', 'Organ');

    $csv = implode(',', UnitPriceImport::COLUMNS)."\n".'Sewing Needle,Organ,12.5'."\n";

    $this->actingAs(priceUser())->post(
        route('store.stock.items.prices.import'),
        ['file' => UploadedFile::fake()->createWithContent('prices.csv', $csv)]
    )->assertRedirect()->assertSessionHas('success');

    $fresh = $item->fresh();

    expect((float) $fresh->unit_price)->toBe(12.5)
        // Untouched, all of it.
        ->and($fresh->uom)->toBe('Pkt')
        ->and($fresh->category)->toBe('Needle')
        ->and((float) $fresh->opening_qty)->toBe(25.0)
        ->and((float) $fresh->safety_stock_qty)->toBe(10.0)
        ->and((float) $fresh->reorder_level)->toBe(20.0)
        ->and($fresh->lead_time_days)->toBe(5)
        ->and($fresh->remarks)->toBe('keep me')
        ->and($fresh->brand)->toBe('Organ');
});

it('leaves a price alone when the cell is blank rather than clearing it', function () {
    // Somebody filling 1,241 rows leaves most of them empty on the first pass.
    // Reading that as "set these to nothing" would wipe every price recorded.
    $item = pricedItem('Sewing Needle', 'Organ', ['unit_price' => 9.0]);

    $result = UnitPriceImport::parse(priceSheet([
        ['Sewing Needle', 'Organ', ''],
    ]));

    expect($result['updates'])->toBeEmpty()
        ->and($result['errors'])->toBeEmpty()
        ->and(implode(' ', $result['skipped']))->toContain('has no Unit Price, so it was left as it is')
        ->and((float) $item->fresh()->unit_price)->toBe(9.0);
});

// --- Name and brand together --------------------------------------------------

it('prices the right make when one name is stocked under two brands', function () {
    // 106 names in the live master carry two or more brands. A name-only match
    // would price the wrong one and look like it had worked.
    $generic = pricedItem('B/S Needle DPX17-09', 'Generic');
    $organ = pricedItem('B/S Needle DPX17-09', 'Organ');

    $result = UnitPriceImport::parse(priceSheet([
        ['B/S Needle DPX17-09', 'Organ', 15],
    ]));

    expect($result['updates'])->toHaveCount(1)
        ->and($result['updates'][0]['id'])->toBe($organ->id)
        ->and($result['updates'][0]['id'])->not->toBe($generic->id);
});

it('matches whatever the letter case and spacing', function () {
    $item = pricedItem('Circuit Breaker', 'Schneider');

    $result = UnitPriceImport::parse(priceSheet([
        ['  circuit   breaker ', 'SCHNEIDER', 4.25],
    ]));

    expect($result['errors'])->toBeEmpty()
        ->and($result['updates'][0]['id'])->toBe($item->id);
});

it('treats a blank brand as an identity, not a wildcard', function () {
    $unbranded = pricedItem('Masking Tape', null);
    pricedItem('Masking Tape', 'Nitto');

    $result = UnitPriceImport::parse(priceSheet([
        ['Masking Tape', '', 3],
    ]));

    // The unbranded row, not whichever came first.
    expect($result['updates'])->toHaveCount(1)
        ->and($result['updates'][0]['id'])->toBe($unbranded->id);
});

// --- It never creates ---------------------------------------------------------

it('reports an unmatched row instead of creating a phantom item', function () {
    pricedItem('Sewing Needle', 'Organ');

    $result = UnitPriceImport::parse(priceSheet([
        ['Sewing Neddle', 'Organ', 12.5],
    ]));

    expect($result['updates'])->toBeEmpty()
        ->and(implode(' ', $result['errors']))->toContain('is not in the item master')
        ->and(StockItem::count())->toBe(1);
});

it('reports a right name under the wrong brand as unmatched', function () {
    // The failure the brand column exists to catch.
    pricedItem('B/S Needle DPX17-09', 'Organ');

    $result = UnitPriceImport::parse(priceSheet([
        ['B/S Needle DPX17-09', 'Groz-Beckert', 15],
    ]));

    expect($result['updates'])->toBeEmpty()
        ->and(implode(' ', $result['errors']))->toContain('is not in the item master');
});

it('writes nothing at all when any row is unmatched', function () {
    // All-or-nothing: applying only the half that matched leaves the user
    // unable to tell what landed.
    $item = pricedItem('Sewing Needle', 'Organ');

    $csv = implode(',', UnitPriceImport::COLUMNS)."\n"
        .'Sewing Needle,Organ,12.5'."\n"
        .'Ghost Item,Organ,4'."\n";

    $this->actingAs(priceUser())->post(
        route('store.stock.items.prices.import'),
        ['file' => UploadedFile::fake()->createWithContent('prices.csv', $csv)]
    )->assertRedirect()->assertSessionHas('import_errors');

    expect($item->fresh()->unit_price)->toBeNull()
        ->and(StockItem::count())->toBe(1);
});

it('refuses a price that is not a number, or is negative', function () {
    pricedItem('Sewing Needle', 'Organ');

    $bad = UnitPriceImport::parse(priceSheet([['Sewing Needle', 'Organ', 'about ten']]));
    $negative = UnitPriceImport::parse(priceSheet([['Sewing Needle', 'Organ', -5]]));

    expect(implode(' ', $bad['errors']))->toContain('must be a number')
        ->and(implode(' ', $negative['errors']))->toContain('must be a number');
});

it('skips the template example row', function () {
    pricedItem('Sewing Needle', 'Organ');

    $result = UnitPriceImport::parse(priceSheet([
        UnitPriceImport::SAMPLE_ROW,
        ['Sewing Needle', 'Organ', 5],
    ]));

    expect($result['updates'])->toHaveCount(1)
        ->and(implode(' ', $result['skipped']))->toContain('example row was ignored');
});

it('needs the correction right, not just the right to see items', function () {
    pricedItem('Sewing Needle', 'Organ');

    $csv = implode(',', UnitPriceImport::COLUMNS)."\n".'Sewing Needle,Organ,12.5'."\n";

    $this->actingAs(priceUser(['store.items.view']))->post(
        route('store.stock.items.prices.import'),
        ['file' => UploadedFile::fake()->createWithContent('prices.csv', $csv)]
    )->assertForbidden();

    expect(StockItem::first()->unit_price)->toBeNull();
});

// --- The export that feeds it -------------------------------------------------

it('exports every item under the import s own headings', function () {
    pricedItem('Sewing Needle', 'Organ', ['unit_price' => 7.5, 'opening_as_on' => '2026-08-01']);
    pricedItem('Sewing Thread', null, ['is_active' => false]);

    $export = new ItemMasterExport;

    expect($export->headings())->toBe(ItemMasterImport::COLUMNS);

    $rows = $export->array();

    // Inactive items included — they still hold stock the report values, and
    // leaving them out would mean one could never be given a price.
    expect($rows)->toHaveCount(2);

    $at = fn (string $h) => (int) array_search($h, ItemMasterImport::COLUMNS, true);
    $needle = collect($rows)->firstWhere($at('Item Name*'), 'Sewing Needle');

    expect($needle[$at('Brand/Specification')])->toBe('Organ')
        ->and($needle[$at('Uom*')])->toBe('Pkt')
        ->and($needle[$at('Category*')])->toBe('Needle')
        ->and((float) $needle[$at('Unit Price')])->toBe(7.5)
        // A real Excel date, not text — the discipline the other templates use.
        ->and($needle[$at('Counted On')])->toBeNumeric();
});

it('exports a blank where a value is blank, never a zero', function () {
    // A 0 written back into Safety Stock would come back as a pinned zero
    // meaning "never re-order", instead of "calculate this".
    pricedItem('Plain Item', null, [
        'safety_stock_qty' => null, 'reorder_level' => null, 'unit_price' => null,
    ]);

    $at = fn (string $h) => (int) array_search($h, ItemMasterImport::COLUMNS, true);
    [$row] = (new ItemMasterExport)->array();

    expect($row[$at('Safety Stock')])->toBeNull()
        ->and($row[$at('Re-order Level')])->toBeNull()
        ->and($row[$at('Unit Price')])->toBeNull()
        ->and($row[$at('Brand/Specification')])->toBeNull();
});

it('round-trips the export into the price importer', function () {
    // The actual workflow: export, keep name and brand, fill in a price.
    $item = pricedItem('B/S Needle DPX17-09', 'Generic');
    pricedItem('B/S Needle DPX17-09', 'Organ');

    $at = fn (string $h) => (int) array_search($h, ItemMasterImport::COLUMNS, true);
    $exported = collect((new ItemMasterExport)->array())
        ->firstWhere($at('Brand/Specification'), 'Generic');

    $result = UnitPriceImport::parse(priceSheet([
        [$exported[$at('Item Name*')], $exported[$at('Brand/Specification')], 11],
    ]));

    expect($result['errors'])->toBeEmpty()
        ->and($result['updates'])->toHaveCount(1)
        ->and($result['updates'][0]['id'])->toBe($item->id);
});

it('takes the full export file as-is, without the other columns being trimmed off', function () {
    // The workflow people will actually use: export, fill in the Unit Price
    // column, upload the whole 11-column file. Headings are matched by NAME, so
    // Uom, Category, Opening Stock and the rest are simply never looked up.
    //
    // Asserted because it is a guarantee somebody is relying on for 1,241 rows:
    // rename a column in the export and this path breaks silently, leaving a
    // file that looks right and updates nothing.
    $needle = pricedItem('Sewing Needle', 'Organ');
    $thread = pricedItem('Sewing Thread', null);

    $at = fn (string $h) => (int) array_search($h, ItemMasterImport::COLUMNS, true);

    $rows = (new ItemMasterExport)->array();

    // Price one row, leave the other blank, exactly as a first pass would.
    foreach ($rows as $i => $row) {
        if ($row[$at('Item Name*')] === 'Sewing Needle') {
            $rows[$i][$at('Unit Price')] = 12.5;
        }
    }

    // The FULL export headings, not the price importer's three.
    $result = UnitPriceImport::parse(array_merge([ItemMasterImport::COLUMNS], $rows));

    expect($result['errors'])->toBeEmpty()
        ->and($result['updates'])->toHaveCount(1)
        ->and($result['updates'][0]['id'])->toBe($needle->id)
        ->and($result['updates'][0]['unit_price'])->toBe(12.5)
        // The unpriced row is left alone, not cleared.
        ->and(implode(' ', $result['skipped']))->toContain('Sewing Thread');

    expect($thread->fresh()->unit_price)->toBeNull();
});

it('lets anyone who can see items download the export', function () {
    pricedItem('Sewing Needle', 'Organ');

    $this->actingAs(priceUser(['store.items.view']))
        ->get(route('store.stock.items.export'))
        ->assertOk()
        ->assertDownload();
});
