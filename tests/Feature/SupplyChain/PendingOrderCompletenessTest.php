<?php

use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * The order of the Pending PO Generate list.
 *
 * It used to be newest-first, which scatters half-filled rows evenly through
 * it. On the live set that is 173 rows of 550 carrying nothing but a buyer, a
 * season and a style, so a sparse row turned up from page 2 onward and the
 * screen read as broken.
 *
 * "Complete" is not a new idea here. applyHasBookingSourceData() already names
 * the seven groups this screen is built on — buyer, season, ihod, vendor,
 * style, item, qty — and admits a row if ANY of them is filled. The ordering
 * counts how many are, through the same headerIdsForGroup() the filters use,
 * so the ordering and the admission rule cannot drift apart.
 *
 * What is pinned here is the ORDER and the PAGE SIZE. The rows shown, the
 * columns and the filters are unchanged and are covered elsewhere.
 */
function pocUser(): User
{
    Role::findOrCreate('supply_chain', 'web');

    return User::factory()->create()->assignRole('supply_chain');
}

/**
 * One header per group the ordering scores, so a row's score is exactly the
 * number of groups it fills. Names matter: headerIdsForGroup() resolves them
 * by key and by name, and 'Materials to be Ordered' is what the qty group
 * matches in this database.
 */
function pocHeaders(): void
{
    $owner = Role::findOrCreate('supply_chain', 'web');

    $spec = [
        'buyer_name' => 'Buyer Name',
        'season_name' => 'Season Name',
        'contract_shipment_date' => 'Contract Shipment Date',
        'vendor_name' => 'Vendor Name',
        'style_name' => 'Style Name',
        'material_description' => 'Material Description',
        'materials_ordered' => 'Materials to be Ordered',
    ];

    $position = 1;

    foreach ($spec as $key => $name) {
        ExcelHeader::firstOrCreate(
            ['header_key' => $key],
            [
                'header_name' => $name,
                'owner_role_id' => $owner->id,
                'position' => $position++,
                'field_type' => 'text',
                'is_active' => true,
            ]
        );
    }
}

/**
 * A pending row filling exactly the named columns.
 *
 * BUYER VARIES PER ROW in most fixtures below, and that is deliberate.
 * decoratePendingRows() collapses rows sharing a booking group key —
 * buyer|season|ihod|vendor, or vendor|sap_code when a SAP code is present —
 * into a single line carrying a count. Rows built from identical values are
 * therefore ONE row on screen, which would leave an ordering test asserting
 * against a list of one. Varying the buyer keeps every key distinct while
 * leaving each row's completeness score exactly as intended: a filled buyer
 * scores the same whatever it says.
 */
function pocRow(ExcelFile $file, int $number, array $valuesByKey): ExcelRow
{
    $row = ExcelRow::create([
        'excel_file_id' => $file->id,
        'row_number' => $number,
        'origin' => ExcelRow::ORIGIN_IMPORTED,
    ]);

    foreach ($valuesByKey as $key => $value) {
        ExcelCell::create([
            'row_id' => $row->id,
            'header_id' => ExcelHeader::where('header_key', $key)->value('id'),
            'value' => $value,
        ]);
    }

    return $row->fresh();
}

function pocFile(): ExcelFile
{
    return ExcelFile::create([
        'file_name' => 'bom.xlsx',
        'original_file_name' => 'bom.xlsx',
        'file_path' => 'bom.xlsx',
        'uploaded_by' => User::factory()->create()->id,
        'buyer_id' => null,
        'total_rows' => 1,
    ]);
}

/** The pending list's row ids, in the order the screen renders them. */
function pocOrder(): array
{
    return test()->actingAs(pocUser())
        ->get(route('supply_chain.bookings.index'))
        ->assertOk()
        ->viewData('pendingRows')
        ->getCollection()
        ->pluck('id')
        ->all();
}

beforeEach(function () {
    pocHeaders();
    $this->file = pocFile();
});

it('puts a fuller row ahead of a sparse one, whatever their ids say', function () {
    // The SPARSE row is created LAST, so under the old newest-first order it
    // would lead. If this passes, the id is no longer what decides.
    $full = pocRow($this->file, 1, [
        'buyer_name' => 'HUGO BOSS',
        'season_name' => 'FA-27',
        'contract_shipment_date' => '2026-08-01',
        'vendor_name' => 'Star Traders',
        'style_name' => 'Orevo',
        'material_description' => 'Needle DBx1',
        'materials_ordered' => '1435',
    ]);

    $sparse = pocRow($this->file, 2, [
        'buyer_name' => 'HUGO BOSS',
        'season_name' => 'FA-27',
        'style_name' => 'Orevo',
    ]);

    expect(pocOrder())->toBe([$full->id, $sparse->id]);
});

it('sorts the emptiest rows to the very end', function () {
    $sparseA = pocRow($this->file, 1, ['buyer_name' => 'BUYER A']);

    $middle = pocRow($this->file, 2, [
        'buyer_name' => 'BUYER B',
        'season_name' => 'FA-27',
        'vendor_name' => 'Star Traders',
        'style_name' => 'Orevo',
    ]);

    $sparseB = pocRow($this->file, 3, ['buyer_name' => 'BUYER C']);

    $full = pocRow($this->file, 4, [
        'buyer_name' => 'BUYER D',
        'season_name' => 'FA-27',
        'contract_shipment_date' => '2026-08-01',
        'vendor_name' => 'Star Traders',
        'style_name' => 'Orevo',
        'material_description' => 'Needle DBx1',
        'materials_ordered' => '1435',
    ]);

    $order = pocOrder();

    expect($order[0])->toBe($full->id)
        ->and($order[1])->toBe($middle->id)
        // Both one-field rows last; between themselves, newest first as before.
        ->and(array_slice($order, 2))->toBe([$sparseB->id, $sparseA->id]);
});

it('puts a zero quantity behind an equally complete row that has a real one', function () {
    // Both fill all seven groups. The only difference is that one of them
    // cannot actually be ordered.
    $base = [
        'season_name' => 'FA-27',
        'contract_shipment_date' => '2026-08-01',
        'vendor_name' => 'Star Traders',
        'style_name' => 'Orevo',
        'material_description' => 'Needle DBx1',
    ];

    // Created FIRST, so newest-first would otherwise put the zero row on top.
    $realQty = pocRow($this->file, 1, $base + ['buyer_name' => 'BUYER A', 'materials_ordered' => '1435']);
    $zeroQty = pocRow($this->file, 2, $base + ['buyer_name' => 'BUYER B', 'materials_ordered' => '0']);

    expect(pocOrder())->toBe([$realQty->id, $zeroQty->id]);
});

it('treats the written forms of zero the same way', function () {
    $base = [
        'season_name' => 'FA-27',
        'contract_shipment_date' => '2026-08-01',
        'vendor_name' => 'Star Traders',
        'style_name' => 'Orevo',
        'material_description' => 'Needle DBx1',
    ];

    $real = pocRow($this->file, 1, $base + ['buyer_name' => 'BUYER A', 'materials_ordered' => '12']);
    $zero = pocRow($this->file, 2, $base + ['buyer_name' => 'BUYER B', 'materials_ordered' => '0.00']);

    expect(pocOrder())->toBe([$real->id, $zero->id]);
});

it('keeps the order when a filter is applied', function () {
    // The ordering is applied to the query the filters have already narrowed,
    // so a filtered list reads the same way the whole one does. Both rows match
    // the filter; only their fullness differs.
    $full = pocRow($this->file, 1, [
        'buyer_name' => 'HUGO BOSS',
        'season_name' => 'FA-27',
        'contract_shipment_date' => '2026-08-01',
        'vendor_name' => 'Star Traders',
        'style_name' => 'Orevo',
        'material_description' => 'Needle DBx1',
        'materials_ordered' => '1435',
    ]);

    $sparse = pocRow($this->file, 2, [
        'buyer_name' => 'HUGO BOSS',
        'style_name' => 'Orevo',
    ]);

    // A row the filter must exclude, so the filter is doing something.
    pocRow($this->file, 3, ['buyer_name' => 'ZARA', 'style_name' => 'Other']);

    $filtered = $this->actingAs(pocUser())
        ->get(route('supply_chain.bookings.index', ['buyer' => 'HUGO BOSS']))
        ->assertOk()
        ->viewData('pendingRows');

    expect($filtered->getCollection()->pluck('id')->all())->toBe([$full->id, $sparse->id]);
});

it('keeps the order under a keyword search too', function () {
    $full = pocRow($this->file, 1, [
        'buyer_name' => 'HUGO BOSS',
        'season_name' => 'FA-27',
        'contract_shipment_date' => '2026-08-01',
        'vendor_name' => 'Star Traders',
        'style_name' => 'Orevo',
        'material_description' => 'Needle DBx1',
        'materials_ordered' => '1435',
    ]);

    $sparse = pocRow($this->file, 2, ['style_name' => 'Orevo']);

    $filtered = $this->actingAs(pocUser())
        ->get(route('supply_chain.bookings.index', ['keyword' => 'Orevo']))
        ->assertOk()
        ->viewData('pendingRows');

    expect($filtered->getCollection()->pluck('id')->all())->toBe([$full->id, $sparse->id]);
});

it('shows 25 rows a page', function () {
    foreach (range(1, 30) as $number) {
        pocRow($this->file, $number, [
            'buyer_name' => 'BUYER '.$number,
            'style_name' => 'Orevo',
            'vendor_name' => 'Star Traders',
        ]);
    }

    $paginator = $this->actingAs(pocUser())
        ->get(route('supply_chain.bookings.index'))
        ->assertOk()
        ->viewData('pendingRows');

    expect($paginator->perPage())->toBe(25)
        ->and($paginator->total())->toBe(30)
        ->and($paginator->getCollection())->toHaveCount(25);
});

it('carries the ordering onto the later pages', function () {
    // 26 full rows and one sparse: the sparse row must be alone on page 2, not
    // sitting among the full ones on page 1.
    foreach (range(1, 26) as $number) {
        pocRow($this->file, $number, [
            'buyer_name' => 'BUYER '.$number,
            'season_name' => 'FA-27',
            'contract_shipment_date' => '2026-08-01',
            'vendor_name' => 'Star Traders',
            'style_name' => 'Orevo',
            'material_description' => 'Needle DBx1',
            'materials_ordered' => '1435',
        ]);
    }

    $sparse = pocRow($this->file, 27, ['buyer_name' => 'SPARSE BUYER']);

    $page1 = $this->actingAs(pocUser())
        ->get(route('supply_chain.bookings.index'))
        ->assertOk()
        ->viewData('pendingRows')
        ->getCollection()->pluck('id')->all();

    $page2 = $this->actingAs(pocUser())
        ->get(route('supply_chain.bookings.index', ['page' => 2]))
        ->assertOk()
        ->viewData('pendingRows')
        ->getCollection()->pluck('id')->all();

    // 27 rows over pages of 25: the sparse one sorts LAST overall, so page one
    // holds none of it and page two ends on it.
    expect($page1)->not->toContain($sparse->id)
        ->and(end($page2))->toBe($sparse->id);
});
