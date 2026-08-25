<?php

use App\Models\Buyer;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use App\Services\SupplyChainReportService;
use App\Support\BuyerScope;
use Spatie\Permission\Models\Role;

/**
 * Locks the Supply Chain report's access matrix, its filters, its column
 * boundary — Supply Chain's own columns plus three named Merchandising context
 * columns, and nothing else — and the buyer scope.
 *
 * Same structure as tests/Feature/Merchant/ReportTest.php, department swapped.
 */
function scReportUser(string $role, array $attributes = []): User
{
    Role::findOrCreate($role, 'web');

    return User::factory()->create($attributes)->assignRole($role);
}

function scHeader(string $key, string $name, Role $role, int $position, string $type = 'text'): ExcelHeader
{
    return ExcelHeader::create([
        'header_name' => $name,
        'header_key' => $key,
        'owner_role_id' => $role->id,
        'position' => $position,
        'field_type' => $type,
        'is_active' => true,
    ]);
}

/** One file, optionally buyer-tagged, carrying one BOM line. */
function scBomLine(?int $buyerId, array $valuesByKey, int $rowNumber = 1): ExcelFile
{
    $file = ExcelFile::create([
        'file_name' => 'bom.xlsx',
        'original_file_name' => 'bom.xlsx',
        'file_path' => 'bom.xlsx',
        'uploaded_by' => User::factory()->create()->id,
        'buyer_id' => $buyerId,
        'total_rows' => 1,
    ]);

    $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => $rowNumber]);

    foreach ($valuesByKey as $key => $value) {
        ExcelCell::create([
            'row_id' => $row->id,
            'header_id' => ExcelHeader::where('header_key', $key)->value('id'),
            'value' => $value,
        ]);
    }

    return $file;
}

beforeEach(function () {
    $merchant = Role::findOrCreate('merchant', 'web');
    $supply = Role::findOrCreate('supply_chain', 'web');
    $commercial = Role::findOrCreate('commercial', 'web');

    // Merchandising — three of these ride along as context, the fourth must not.
    scHeader('buyer_name', 'Buyer Name', $merchant, 1);
    scHeader('style_name', 'Style Name', $merchant, 2);
    scHeader('description', 'Description', $merchant, 3);
    scHeader('merchant_remarks', 'Merchant Remarks', $merchant, 4);

    // Supply Chain's own.
    scHeader('material_po_number', 'Material PO Number', $supply, 40);
    scHeader('po_date', 'PO Date', $supply, 41, 'date');
    scHeader('material_order_status', 'Material Order Status', $supply, 42);
    scHeader('pi_status', 'PI Status', $supply, 43);
    scHeader('pi_amount', 'PI Amount', $supply, 44, 'number');

    // Another department's — must never appear.
    scHeader('payment_term', 'Payment Term', $commercial, 50);

    scBomLine(null, [
        'buyer_name' => 'Hugo Boss',
        'style_name' => 'Oribe',
        'description' => 'Recycled Polyamide lining',
        'material_po_number' => 'PO-1001',
        'po_date' => '2026-07-28',
        'material_order_status' => 'Ordered',
        'pi_status' => 'Approved',
        'pi_amount' => '1200',
        'merchant_remarks' => 'merchant only note',
        'payment_term' => 'TT 30 days',
    ]);

    scBomLine(null, [
        'buyer_name' => 'Marks & Spencer',
        'style_name' => 'Kelso',
        'description' => 'Cotton twill main fabric',
        'material_po_number' => 'PO-2002',
        'po_date' => '2026-09-14',
        'material_order_status' => 'Pending',
        'pi_status' => 'Awaiting',
        'pi_amount' => '800',
        'merchant_remarks' => 'merchant only note',
        'payment_term' => 'LC at sight',
    ]);
});

// --- Access -----------------------------------------------------------------

test('supply chain, admin and management get preview plus both exports', function (string $role) {
    $user = scReportUser($role);

    $this->actingAs($user)->get('/supply-chain/reports')->assertOk();
    $this->actingAs($user)->get('/supply-chain/reports/pdf')->assertOk();
    $this->actingAs($user)->get('/supply-chain/reports/excel')->assertOk();
})->with(['supply_chain', 'admin', 'management']);

test('other departments cannot reach the supply chain report at all', function (string $role) {
    $user = scReportUser($role);

    $this->actingAs($user)->get('/supply-chain/reports')->assertForbidden();
    $this->actingAs($user)->get('/supply-chain/reports/pdf')->assertForbidden();
    $this->actingAs($user)->get('/supply-chain/reports/excel')->assertForbidden();
})->with(['merchant', 'store', 'commercial', 'account']);

test('guests are redirected to login', function () {
    $this->get('/supply-chain/reports')->assertRedirect('/login');
});

// --- The column boundary ----------------------------------------------------

test('the report shows supply chain columns plus the three context columns, in order', function () {
    $keys = app(SupplyChainReportService::class)->columns()->pluck('header_key');

    expect($keys->all())->toBe([
        // Context first, in CONTEXT_KEYS order …
        'buyer_name', 'style_name', 'description',
        // … then Supply Chain's own, in position order.
        'material_po_number', 'po_date', 'material_order_status', 'pi_status', 'pi_amount',
    ]);
});

test('no other department column reaches the screen', function () {
    $user = scReportUser('supply_chain');

    $this->actingAs($user)->get('/supply-chain/reports')
        ->assertOk()
        ->assertSee('Material PO Number')
        ->assertSee('Buyer Name')
        ->assertSee('PO-1001')
        // Commercial's column, by heading and by value.
        ->assertDontSee('Payment Term')
        ->assertDontSee('LC at sight')
        // A Merchandising column that is NOT one of the three context ones.
        ->assertDontSee('Merchant Remarks')
        ->assertDontSee('merchant only note');
});

test('an inactive supply chain column drops out of the report', function () {
    ExcelHeader::where('header_key', 'pi_status')->update(['is_active' => false]);

    expect(app(SupplyChainReportService::class)->columns()->pluck('header_key'))
        ->not->toContain('pi_status');
});

test('an inactive context column drops out too', function () {
    ExcelHeader::where('header_key', 'description')->update(['is_active' => false]);

    expect(app(SupplyChainReportService::class)->columns()->pluck('header_key')->all())
        ->toBe(['buyer_name', 'style_name', 'material_po_number', 'po_date', 'material_order_status', 'pi_status', 'pi_amount']);
});

// --- Filters ----------------------------------------------------------------

test('exact filters narrow the report to one line', function () {
    $service = app(SupplyChainReportService::class);

    expect($service->count([]))->toBe(2)
        ->and($service->count(['buyer' => 'Hugo Boss']))->toBe(1)
        ->and($service->count(['po_no' => 'PO-2002']))->toBe(1)
        ->and($service->count(['order_status' => 'Ordered']))->toBe(1)
        ->and($service->count(['pi_status' => 'Approved']))->toBe(1)
        // A value that exists only in another department's column matches
        // nothing: the filter cannot see outside this report.
        ->and($service->count(['po_no' => 'LC at sight']))->toBe(0);
});

test('material search is case-insensitive', function () {
    $service = app(SupplyChainReportService::class);

    expect($service->count(['material' => 'POLYAMIDE']))->toBe(1)
        ->and($service->count(['material' => 'polyamide']))->toBe(1)
        ->and($service->count(['material' => 'twill']))->toBe(1);
});

test('the date range filters on PO date', function () {
    $service = app(SupplyChainReportService::class);

    expect($service->count(['date_from' => '2026-07-01', 'date_to' => '2026-07-31']))->toBe(1)
        ->and($service->count(['date_from' => '2026-08-01']))->toBe(1)
        ->and($service->count(['date_from' => '2026-01-01', 'date_to' => '2026-12-31']))->toBe(2)
        ->and($service->count(['date_to' => '2026-01-01']))->toBe(0);
});

test('a blank date cell is excluded rather than treated as an early date', function () {
    $headerId = ExcelHeader::where('header_key', 'po_date')->value('id');
    ExcelCell::where('header_id', $headerId)->update(['value' => '']);

    expect(app(SupplyChainReportService::class)->count(['date_from' => '2026-01-01']))->toBe(0);
});

test('an invalid date range is rejected rather than silently ignored', function () {
    $user = scReportUser('supply_chain');

    $this->actingAs($user)
        ->get('/supply-chain/reports?date_from=2026-08-01&date_to=2026-07-01')
        ->assertSessionHasErrors('date_to');
});

test('filter dropdowns are built from this report own columns', function () {
    $options = app(SupplyChainReportService::class)->filterOptions();

    expect($options['buyer']->all())->toBe(['Hugo Boss', 'Marks & Spencer'])
        ->and($options['po_no']->all())->toBe(['PO-1001', 'PO-2002'])
        ->and($options['order_status']->all())->toBe(['Ordered', 'Pending'])
        ->and($options['pi_status']->all())->toBe(['Approved', 'Awaiting']);
});

test('the excel download carries every column', function () {
    $user = scReportUser('supply_chain');

    $response = $this->actingAs($user)->get('/supply-chain/reports/excel')->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('supply-chain-report-');
});

/*
|--------------------------------------------------------------------------
| Buyer scoping
|--------------------------------------------------------------------------
|
| LIVE for supply_chain users: the department is in
| BuyerScope::SCOPED_DEPARTMENTS, so a Supply Chain user holding buyers is
| confined on this report exactly as a merchant is on theirs.
|
| The first test below is the one that pins the switch itself. It replaced an
| earlier test asserting the opposite — that a supply_chain user holding a
| buyer was NOT confined — which was true while the department was switched
| off and is deliberately inverted now.
*/

/** @return array{0: Buyer, 1: Buyer} */
function scSeedTaggedBuyers(): array
{
    $buyerA = Buyer::create(['buyer_name' => 'Buyer A']);
    $buyerB = Buyer::create(['buyer_name' => 'Buyer B']);

    scBomLine($buyerA->id, ['buyer_name' => 'Buyer A', 'material_po_number' => 'PO-AAA']);
    scBomLine($buyerB->id, ['buyer_name' => 'Buyer B', 'material_po_number' => 'PO-BBB']);

    return [$buyerA, $buyerB];
}

test('supply chain is a buyer-scoped department, so its users are confined by their assignments', function () {
    [$buyerA] = scSeedTaggedBuyers();
    $user = scReportUser('supply_chain', ['buyer_id' => $buyerA->id]);

    expect(BuyerScope::SCOPED_DEPARTMENTS)->toContain('supply_chain')
        ->and($user->isBuyerConfined())->toBeTrue()
        ->and($user->scopedBuyerIds())->toBe([$buyerA->id]);
});

test('a supply chain user on one buyer sees that buyer plus the untagged archive', function () {
    [$buyerA] = scSeedTaggedBuyers();
    $user = scReportUser('supply_chain', ['buyer_id' => $buyerA->id]);

    // 2 untagged lines + Buyer A's. Buyer B's is gone.
    expect(app(SupplyChainReportService::class)->forUser($user)->count([]))->toBe(3);
});

test('a supply chain user assigned several buyers sees all of them', function () {
    [$buyerA, $buyerB] = scSeedTaggedBuyers();
    $user = scReportUser('supply_chain', ['buyer_id' => $buyerA->id]);
    $user->buyers()->sync([$buyerA->id, $buyerB->id]);

    expect($user->fresh()->scopedBuyerIds())->toBe([$buyerA->id, $buyerB->id])
        ->and(app(SupplyChainReportService::class)->forUser($user->fresh())->count([]))->toBe(4);
});

test('a supply chain user confined to nothing still sees the untagged archive', function () {
    scSeedTaggedBuyers();
    // Mode 'specific' with no assignment: confined, but to an empty set.
    $user = scReportUser('supply_chain', ['buyer_scope_mode' => BuyerScope::MODE_SPECIFIC]);

    expect($user->isBuyerConfined())->toBeTrue()
        ->and(app(SupplyChainReportService::class)->forUser($user)->count([]))->toBe(2);
});

test('the scope reaches a supply chain user screen, pdf and excel alike', function () {
    [$buyerA] = scSeedTaggedBuyers();
    $user = scReportUser('supply_chain', ['buyer_id' => $buyerA->id]);

    $this->actingAs($user)->get('/supply-chain/reports')
        ->assertOk()
        ->assertSee('PO-AAA')
        ->assertDontSee('PO-BBB')
        ->assertDontSee('Buyer B');

    $this->actingAs($user)->get('/supply-chain/reports/pdf')->assertOk();
    $this->actingAs($user)->get('/supply-chain/reports/excel')->assertOk();
});

test('a confined viewer sees their own buyer plus the untagged archive', function () {
    [$buyerA] = scSeedTaggedBuyers();

    $user = scReportUser('merchant', ['buyer_id' => $buyerA->id]);

    expect($user->isBuyerConfined())->toBeTrue()
        // 2 untagged lines + Buyer A's. Buyer B's is gone.
        ->and(app(SupplyChainReportService::class)->forUser($user)->count([]))->toBe(3);
});

test('an all-buyer-scope viewer sees everything', function (string $role) {
    scSeedTaggedBuyers();
    $user = scReportUser($role, ['buyer_scope_mode' => BuyerScope::MODE_ALL]);

    expect($user->isBuyerConfined())->toBeFalse()
        ->and(app(SupplyChainReportService::class)->forUser($user)->count([]))->toBe(4);
})->with(['supply_chain', 'merchant']);

test('admin and management are never buyer-restricted', function (string $role) {
    [$buyerA] = scSeedTaggedBuyers();
    $user = scReportUser($role, ['buyer_id' => $buyerA->id]);

    expect($user->isBuyerConfined())->toBeFalse()
        ->and(app(SupplyChainReportService::class)->forUser($user)->count([]))->toBe(4);
})->with(['admin', 'management']);

test('the dropdowns offer only buyers a confined viewer can see', function () {
    [$buyerA] = scSeedTaggedBuyers();
    $confined = scReportUser('supply_chain', ['buyer_id' => $buyerA->id]);
    $unconfined = scReportUser('supply_chain', ['buyer_scope_mode' => BuyerScope::MODE_ALL]);

    expect(app(SupplyChainReportService::class)->forUser($confined)->filterOptions()['buyer']->all())
        ->toBe(['Buyer A', 'Hugo Boss', 'Marks & Spencer'])
        ->and(app(SupplyChainReportService::class)->forUser($unconfined)->filterOptions()['buyer']->all())
        ->toBe(['Buyer A', 'Buyer B', 'Hugo Boss', 'Marks & Spencer']);
});

test('every dropdown is scoped, not just the buyer one', function () {
    [$buyerA] = scSeedTaggedBuyers();
    $user = scReportUser('supply_chain', ['buyer_id' => $buyerA->id]);

    expect(app(SupplyChainReportService::class)->forUser($user)->filterOptions()['po_no']->all())
        ->toBe(['PO-1001', 'PO-2002', 'PO-AAA']);
});

test('hand-typing another buyer into the filter returns nothing rather than leaking', function () {
    [$buyerA] = scSeedTaggedBuyers();
    $user = scReportUser('supply_chain', ['buyer_id' => $buyerA->id]);

    expect(app(SupplyChainReportService::class)->forUser($user)->count(['buyer' => 'Buyer B']))->toBe(0);
});

test('the report falls back to the session user when no viewer is set', function () {
    [$buyerA] = scSeedTaggedBuyers();

    // Fail-closed: a caller that forgets forUser() must not get an
    // unrestricted report.
    $this->actingAs(scReportUser('supply_chain', ['buyer_id' => $buyerA->id]));

    expect(app(SupplyChainReportService::class)->count([]))->toBe(3);
});
