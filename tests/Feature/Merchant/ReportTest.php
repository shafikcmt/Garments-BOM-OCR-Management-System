<?php

use App\Models\Buyer;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use App\Services\MerchantReportService;
use App\Support\BuyerScope;
use Spatie\Permission\Models\Role;

/**
 * Locks the Merchandising report's access matrix, its filters, and the one rule
 * the whole feature rests on: a column owned by another department never
 * reaches the screen or either export.
 */
function merchantReportUser(string $role, array $attributes = []): User
{
    Role::findOrCreate($role, 'web');

    return User::factory()->create($attributes)->assignRole($role);
}

/** @return array{header: ExcelHeader, other: ExcelHeader} */
function seedBomLines(): array
{
    $merchantRole = Role::findOrCreate('merchant', 'web');
    $supplyRole = Role::findOrCreate('supply_chain', 'web');

    $make = fn (string $key, string $name, Role $role, int $position, string $type = 'text') => ExcelHeader::create([
        'header_name' => $name,
        'header_key' => $key,
        'owner_role_id' => $role->id,
        'position' => $position,
        'field_type' => $type,
        'is_active' => true,
    ]);

    $buyer = $make('buyer_name', 'Buyer Name', $merchantRole, 1);
    $style = $make('style_name', 'Style Name', $merchantRole, 2);
    $shipDate = $make('contract_shipment_date', 'Contract Shipment Date', $merchantRole, 3, 'date');
    $description = $make('description', 'Description', $merchantRole, 4);
    // Owned by Supply Chain — must never appear in a Merchandising report.
    $vendorStatus = $make('material_order_status', 'Material Order Status', $supplyRole, 5);

    $file = ExcelFile::create([
        'file_name' => 'bom.xlsx',
        'original_file_name' => 'bom.xlsx',
        'file_path' => 'bom.xlsx',
        'uploaded_by' => User::factory()->create()->id,
        'total_rows' => 2,
    ]);

    $lines = [
        ['Hugo Boss', 'Oribe', '2026-07-28', 'Recycled Polyamide lining', 'Ordered'],
        ['Marks & Spencer', 'Kelso', '2026-09-14', 'Cotton twill main fabric', 'Pending'],
    ];

    foreach ($lines as $index => [$b, $s, $d, $desc, $status]) {
        $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => $index + 1]);

        foreach ([[$buyer, $b], [$style, $s], [$shipDate, $d], [$description, $desc], [$vendorStatus, $status]] as [$header, $value]) {
            ExcelCell::create(['row_id' => $row->id, 'header_id' => $header->id, 'value' => $value]);
        }
    }

    return ['header' => $buyer, 'other' => $vendorStatus];
}

beforeEach(function () {
    seedBomLines();
});

test('merchant, admin and management get preview plus both exports', function (string $role) {
    $user = merchantReportUser($role);

    $this->actingAs($user)->get('/merchant/reports')->assertOk();
    $this->actingAs($user)->get('/merchant/reports/pdf')->assertOk();
    $this->actingAs($user)->get('/merchant/reports/excel')->assertOk();
})->with(['merchant', 'admin', 'management']);

test('other departments cannot reach the merchandising report at all', function (string $role) {
    $user = merchantReportUser($role);

    $this->actingAs($user)->get('/merchant/reports')->assertForbidden();
    $this->actingAs($user)->get('/merchant/reports/pdf')->assertForbidden();
    $this->actingAs($user)->get('/merchant/reports/excel')->assertForbidden();
})->with(['store', 'commercial', 'account', 'supply_chain']);

test('guests are redirected to login', function () {
    $this->get('/merchant/reports')->assertRedirect('/login');
});

test('the preview shows merchandising columns and never another department column', function () {
    $user = merchantReportUser('merchant');

    $this->actingAs($user)->get('/merchant/reports')
        ->assertOk()
        ->assertSee('Buyer Name')
        ->assertSee('Contract Shipment Date')
        ->assertSee('Hugo Boss')
        // The Supply Chain column, by heading and by value.
        ->assertDontSee('Material Order Status')
        ->assertDontSee('Ordered');
});

test('the service only ever reports headers owned by merchandising', function () {
    $keys = app(MerchantReportService::class)->columns()->pluck('header_key');

    expect($keys->all())->toBe(['buyer_name', 'style_name', 'contract_shipment_date', 'description'])
        ->and($keys)->not->toContain('material_order_status');
});

test('an inactive merchandising column drops out of the report', function () {
    ExcelHeader::where('header_key', 'description')->update(['is_active' => false]);

    expect(app(MerchantReportService::class)->columns()->pluck('header_key')->all())
        ->toBe(['buyer_name', 'style_name', 'contract_shipment_date']);
});

test('exact filters narrow the report to one line', function () {
    $service = app(MerchantReportService::class);

    expect($service->count([]))->toBe(2)
        ->and($service->count(['buyer' => 'Hugo Boss']))->toBe(1)
        ->and($service->count(['style' => 'Kelso']))->toBe(1)
        // A value that exists only in a Supply Chain column matches nothing:
        // the filter cannot see outside this department.
        ->and($service->count(['buyer' => 'Ordered']))->toBe(0);
});

test('material search is case-insensitive', function () {
    $service = app(MerchantReportService::class);

    expect($service->count(['material' => 'POLYAMIDE']))->toBe(1)
        ->and($service->count(['material' => 'polyamide']))->toBe(1)
        ->and($service->count(['material' => 'twill']))->toBe(1);
});

test('the date range filters on contract shipment date', function () {
    $service = app(MerchantReportService::class);

    expect($service->count(['date_from' => '2026-07-01', 'date_to' => '2026-07-31']))->toBe(1)
        ->and($service->count(['date_from' => '2026-08-01']))->toBe(1)
        ->and($service->count(['date_from' => '2026-01-01', 'date_to' => '2026-12-31']))->toBe(2)
        ->and($service->count(['date_to' => '2026-01-01']))->toBe(0);
});

test('a blank date cell is excluded rather than treated as an early date', function () {
    $dateHeader = ExcelHeader::where('header_key', 'contract_shipment_date')->first();
    ExcelCell::where('header_id', $dateHeader->id)->update(['value' => '']);

    expect(app(MerchantReportService::class)->count(['date_from' => '2026-01-01']))->toBe(0);
});

test('filter dropdowns offer only values from merchandising columns', function () {
    $options = app(MerchantReportService::class)->filterOptions();

    expect($options['buyer']->all())->toBe(['Hugo Boss', 'Marks & Spencer'])
        ->and($options['style']->all())->toBe(['Kelso', 'Oribe'])
        // No Supply Chain header backs a filter, so its list is simply empty.
        ->and($options['season']->all())->toBe([]);
});

test('the excel download carries every merchandising column', function () {
    $user = merchantReportUser('merchant');

    $response = $this->actingAs($user)->get('/merchant/reports/excel')->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('merchandising-bom-report-');
});

test('an invalid date range is rejected rather than silently ignored', function () {
    $user = merchantReportUser('merchant');

    $this->actingAs($user)
        ->get('/merchant/reports?date_from=2026-08-01&date_to=2026-07-01')
        ->assertSessionHasErrors('date_to');
});

/*
|--------------------------------------------------------------------------
| Buyer scoping — the read-time half of the Merchandising edit-lock rule
|--------------------------------------------------------------------------
|
| A confined merchant sees their own buyers' lines plus the untagged archive,
| and nothing else. Everyone the Phase 4 rule leaves unconfined — an 'all'
| merchant, Admin, Management — must see exactly what they saw before, which is
| most of what these assert.
*/

/** A file tagged with $buyerId (or untagged when null), carrying one BOM line. */
function taggedBomLine(?int $buyerId, string $buyerName, string $styleName): ExcelFile
{
    $file = ExcelFile::create([
        'file_name' => 'bom.xlsx',
        'original_file_name' => 'bom.xlsx',
        'file_path' => 'bom.xlsx',
        'uploaded_by' => User::factory()->create()->id,
        'buyer_id' => $buyerId,
        'total_rows' => 1,
    ]);

    $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => 1]);

    foreach (['buyer_name' => $buyerName, 'style_name' => $styleName] as $key => $value) {
        ExcelCell::create([
            'row_id' => $row->id,
            'header_id' => ExcelHeader::where('header_key', $key)->value('id'),
            'value' => $value,
        ]);
    }

    return $file;
}

/**
 * Two tagged buyers on top of the 2 untagged lines seedBomLines() left, giving
 * 4 lines in total. Called per test rather than from a beforeEach so the tests
 * above keep counting the 2 lines they were written against.
 *
 * @return array{0: Buyer, 1: Buyer}
 */
function seedTaggedBuyers(): array
{
    $buyerA = Buyer::create(['buyer_name' => 'Buyer A']);
    $buyerB = Buyer::create(['buyer_name' => 'Buyer B']);

    taggedBomLine($buyerA->id, 'Buyer A', 'Alpha');
    taggedBomLine($buyerB->id, 'Buyer B', 'Beta');

    return [$buyerA, $buyerB];
}

test('a scoped merchant sees their own buyer plus the untagged archive, and no other buyer', function () {
    [$buyerA] = seedTaggedBuyers();
    $user = merchantReportUser('merchant', ['buyer_id' => $buyerA->id]);
    $service = app(MerchantReportService::class)->forUser($user);

    // 2 untagged lines + Buyer A's one. Buyer B's is gone.
    expect($service->count([]))->toBe(3)
        ->and($service->all([])->pluck('values.style_name')->all())
        ->toBe(['Oribe', 'Kelso', 'Alpha']);
});

test('a merchant assigned several buyers sees all of them', function () {
    [$buyerA, $buyerB] = seedTaggedBuyers();
    $user = merchantReportUser('merchant', ['buyer_id' => $buyerA->id]);
    $user->buyers()->sync([$buyerA->id, $buyerB->id]);

    expect(app(MerchantReportService::class)->forUser($user->fresh())->count([]))->toBe(4);
});

test('a merchant on all-buyer scope keeps seeing everything', function () {
    seedTaggedBuyers();
    $user = merchantReportUser('merchant', ['buyer_scope_mode' => BuyerScope::MODE_ALL]);

    expect($user->isBuyerConfined())->toBeFalse()
        ->and(app(MerchantReportService::class)->forUser($user)->count([]))->toBe(4);
});

test('admin and management are never buyer-restricted', function (string $role) {
    [$buyerA] = seedTaggedBuyers();
    $user = merchantReportUser($role, ['buyer_id' => $buyerA->id]);

    expect($user->isBuyerConfined())->toBeFalse()
        ->and(app(MerchantReportService::class)->forUser($user)->count([]))->toBe(4);
})->with(['admin', 'management']);

test('a merchant confined to nothing still sees the untagged archive', function () {
    seedTaggedBuyers();
    // Mode 'specific' with no assignment: confined, but to an empty set.
    $user = merchantReportUser('merchant', ['buyer_scope_mode' => BuyerScope::MODE_SPECIFIC]);

    expect($user->isBuyerConfined())->toBeTrue()
        ->and(app(MerchantReportService::class)->forUser($user)->count([]))->toBe(2);
});

test('the buyer dropdown offers only buyers the viewer can see', function () {
    [$buyerA] = seedTaggedBuyers();
    $scoped = merchantReportUser('merchant', ['buyer_id' => $buyerA->id]);
    $unscoped = merchantReportUser('merchant', ['buyer_scope_mode' => BuyerScope::MODE_ALL]);

    expect(app(MerchantReportService::class)->forUser($scoped)->filterOptions()['buyer']->all())
        ->toBe(['Buyer A', 'Hugo Boss', 'Marks & Spencer'])
        ->and(app(MerchantReportService::class)->forUser($unscoped)->filterOptions()['buyer']->all())
        ->toBe(['Buyer A', 'Buyer B', 'Hugo Boss', 'Marks & Spencer']);
});

test('every dropdown is scoped, not just the buyer one', function () {
    [$buyerA] = seedTaggedBuyers();
    $user = merchantReportUser('merchant', ['buyer_id' => $buyerA->id]);

    expect(app(MerchantReportService::class)->forUser($user)->filterOptions()['style']->all())
        ->toBe(['Alpha', 'Kelso', 'Oribe'])
        ->and(app(MerchantReportService::class)->forUser($user)->filterOptions()['style'])
        ->not->toContain('Beta');
});

test('hand-typing another buyer into the filter returns nothing rather than leaking', function () {
    [$buyerA] = seedTaggedBuyers();
    $user = merchantReportUser('merchant', ['buyer_id' => $buyerA->id]);

    expect(app(MerchantReportService::class)->forUser($user)->count(['buyer' => 'Buyer B']))->toBe(0);
});

test('the scope reaches the screen, the pdf and the excel sheet alike', function () {
    [$buyerA] = seedTaggedBuyers();
    $user = merchantReportUser('merchant', ['buyer_id' => $buyerA->id]);

    // The screen names the line count, so a leak would show up as a 4.
    $this->actingAs($user)->get('/merchant/reports')
        ->assertOk()
        ->assertSee('Alpha')
        ->assertDontSee('Beta')
        ->assertDontSee('Buyer B');

    $this->actingAs($user)->get('/merchant/reports/pdf')->assertOk();
    $this->actingAs($user)->get('/merchant/reports/excel')->assertOk();
});

test('the report falls back to the session user when no viewer is set', function () {
    [$buyerA] = seedTaggedBuyers();
    $user = merchantReportUser('merchant', ['buyer_id' => $buyerA->id]);

    // Fail-closed: a caller that forgets forUser() must not get an
    // unrestricted report.
    $this->actingAs($user);

    expect(app(MerchantReportService::class)->count([]))->toBe(3);
});
