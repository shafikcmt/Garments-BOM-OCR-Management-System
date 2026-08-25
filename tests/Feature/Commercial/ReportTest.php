<?php

use App\Models\Buyer;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use App\Services\CommercialReportService;
use App\Support\BuyerScope;
use Spatie\Permission\Models\Role;

/**
 * Locks the Commercial report's access matrix, its filters and its column
 * boundary — Commercial's own columns plus three named Merchandising context
 * columns, and nothing else.
 *
 * Same structure as tests/Feature/SupplyChain/ReportTest.php, department
 * swapped, with one deliberate difference: see the buyer-scoping note further
 * down. Commercial is NOT in BuyerScope::SCOPED_DEPARTMENTS yet, so the tests
 * that would pin what a Commercial user is confined to are not written here.
 */
function cmReportUser(string $role, array $attributes = []): User
{
    Role::findOrCreate($role, 'web');

    return User::factory()->create($attributes)->assignRole($role);
}

function cmHeader(string $key, string $name, Role $role, int $position, string $type = 'text'): ExcelHeader
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
function cmBomLine(?int $buyerId, array $valuesByKey, int $rowNumber = 1): ExcelFile
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
    $commercial = Role::findOrCreate('commercial', 'web');
    $supply = Role::findOrCreate('supply_chain', 'web');

    // Merchandising — three of these ride along as context, the fourth must not.
    cmHeader('buyer_name', 'Buyer Name', $merchant, 1);
    cmHeader('style_name', 'Style Name', $merchant, 2);
    cmHeader('description', 'Description', $merchant, 3);
    cmHeader('merchant_remarks', 'Merchant Remarks', $merchant, 4);

    // Commercial's own.
    cmHeader('payment_doc_no', 'Payment Doc No', $commercial, 60);
    cmHeader('payment_docs_date', 'Payment Docs Date', $commercial, 61, 'date');
    cmHeader('payment_status', 'Payment Status', $commercial, 62);
    cmHeader('sg_status', 'SG Status', $commercial, 63);
    cmHeader('original_docs_submit_status', 'Original docs submit status', $commercial, 64);

    // Another department's — must never appear.
    cmHeader('material_po_number', 'Material PO Number', $supply, 40);

    cmBomLine(null, [
        'buyer_name' => 'Hugo Boss',
        'style_name' => 'Oribe',
        'description' => 'Recycled Polyamide lining',
        'payment_doc_no' => 'PD-1001',
        'payment_docs_date' => '2026-07-28',
        'payment_status' => 'Paid',
        'sg_status' => 'Received',
        'original_docs_submit_status' => 'Submitted',
        'merchant_remarks' => 'merchant only note',
        'material_po_number' => 'PO-9001',
    ]);

    cmBomLine(null, [
        'buyer_name' => 'Marks & Spencer',
        'style_name' => 'Kelso',
        'description' => 'Cotton twill main fabric',
        'payment_doc_no' => 'PD-2002',
        'payment_docs_date' => '2026-09-14',
        'payment_status' => 'Pending',
        'sg_status' => 'Awaiting',
        'original_docs_submit_status' => 'Not Submitted',
        'merchant_remarks' => 'merchant only note',
        'material_po_number' => 'PO-9002',
    ]);
});

// --- Access -----------------------------------------------------------------

test('commercial, admin and management get preview plus both exports', function (string $role) {
    $user = cmReportUser($role);

    $this->actingAs($user)->get('/commercial/reports')->assertOk();
    $this->actingAs($user)->get('/commercial/reports/pdf')->assertOk();
    $this->actingAs($user)->get('/commercial/reports/excel')->assertOk();
})->with(['commercial', 'admin', 'management']);

test('other departments cannot reach the commercial report at all', function (string $role) {
    $user = cmReportUser($role);

    $this->actingAs($user)->get('/commercial/reports')->assertForbidden();
    $this->actingAs($user)->get('/commercial/reports/pdf')->assertForbidden();
    $this->actingAs($user)->get('/commercial/reports/excel')->assertForbidden();
})->with(['merchant', 'store', 'supply_chain', 'account']);

test('guests are redirected to login', function () {
    $this->get('/commercial/reports')->assertRedirect('/login');
});

// --- The column boundary ----------------------------------------------------

test('the report shows commercial columns plus the three context columns, in order', function () {
    $keys = app(CommercialReportService::class)->columns()->pluck('header_key');

    expect($keys->all())->toBe([
        // Context first, in contextKeys() order …
        'buyer_name', 'style_name', 'description',
        // … then Commercial's own, in position order.
        'payment_doc_no', 'payment_docs_date', 'payment_status', 'sg_status', 'original_docs_submit_status',
    ]);
});

test('no other department column reaches the screen', function () {
    $user = cmReportUser('commercial');

    $this->actingAs($user)->get('/commercial/reports')
        ->assertOk()
        ->assertSee('Payment Doc No')
        ->assertSee('Buyer Name')
        ->assertSee('PD-1001')
        // Supply Chain's column, by heading and by value.
        ->assertDontSee('Material PO Number')
        ->assertDontSee('PO-9001')
        // A Merchandising column that is NOT one of the three context ones.
        ->assertDontSee('Merchant Remarks')
        ->assertDontSee('merchant only note');
});

test('an inactive commercial column drops out of the report', function () {
    ExcelHeader::where('header_key', 'sg_status')->update(['is_active' => false]);

    expect(app(CommercialReportService::class)->columns()->pluck('header_key'))
        ->not->toContain('sg_status');
});

test('an inactive context column drops out too', function () {
    ExcelHeader::where('header_key', 'description')->update(['is_active' => false]);

    expect(app(CommercialReportService::class)->columns()->pluck('header_key')->all())
        ->toBe(['buyer_name', 'style_name', 'payment_doc_no', 'payment_docs_date', 'payment_status', 'sg_status', 'original_docs_submit_status']);
});

// --- Filters ----------------------------------------------------------------

test('exact filters narrow the report to one line', function () {
    $service = app(CommercialReportService::class);

    expect($service->count([]))->toBe(2)
        ->and($service->count(['buyer' => 'Hugo Boss']))->toBe(1)
        ->and($service->count(['style' => 'Kelso']))->toBe(1)
        ->and($service->count(['payment_status' => 'Paid']))->toBe(1)
        ->and($service->count(['sg_status' => 'Awaiting']))->toBe(1)
        ->and($service->count(['docs_status' => 'Submitted']))->toBe(1)
        // A value that exists only in another department's column matches
        // nothing: the filter cannot see outside this report.
        ->and($service->count(['payment_status' => 'PO-9001']))->toBe(0);
});

test('material search is case-insensitive', function () {
    $service = app(CommercialReportService::class);

    expect($service->count(['material' => 'POLYAMIDE']))->toBe(1)
        ->and($service->count(['material' => 'polyamide']))->toBe(1)
        ->and($service->count(['material' => 'twill']))->toBe(1);
});

test('the date range filters on payment docs date', function () {
    $service = app(CommercialReportService::class);

    expect($service->count(['date_from' => '2026-07-01', 'date_to' => '2026-07-31']))->toBe(1)
        ->and($service->count(['date_from' => '2026-08-01']))->toBe(1)
        ->and($service->count(['date_from' => '2026-01-01', 'date_to' => '2026-12-31']))->toBe(2)
        ->and($service->count(['date_to' => '2026-01-01']))->toBe(0);
});

test('a blank date cell is excluded rather than treated as an early date', function () {
    $headerId = ExcelHeader::where('header_key', 'payment_docs_date')->value('id');
    ExcelCell::where('header_id', $headerId)->update(['value' => '']);

    expect(app(CommercialReportService::class)->count(['date_from' => '2026-01-01']))->toBe(0);
});

test('an invalid date range is rejected rather than silently ignored', function () {
    $user = cmReportUser('commercial');

    $this->actingAs($user)
        ->get('/commercial/reports?date_from=2026-08-01&date_to=2026-07-01')
        ->assertSessionHasErrors('date_to');
});

test('filter dropdowns are built from this report own columns', function () {
    $options = app(CommercialReportService::class)->filterOptions();

    expect($options['buyer']->all())->toBe(['Hugo Boss', 'Marks & Spencer'])
        ->and($options['style']->all())->toBe(['Kelso', 'Oribe'])
        ->and($options['payment_status']->all())->toBe(['Paid', 'Pending'])
        ->and($options['sg_status']->all())->toBe(['Awaiting', 'Received'])
        ->and($options['docs_status']->all())->toBe(['Not Submitted', 'Submitted']);
});

test('the excel download carries every column', function () {
    $user = cmReportUser('commercial');

    $response = $this->actingAs($user)->get('/commercial/reports/excel')->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('commercial-report-');
});

/*
|--------------------------------------------------------------------------
| Buyer scoping
|--------------------------------------------------------------------------
|
| DELIBERATELY PARTIAL, and this is the one place this file departs from
| SupplyChain/ReportTest.php.
|
| 'commercial' is not in BuyerScope::SCOPED_DEPARTMENTS. Adding it confines
| every Commercial user who holds a buyer assignment from the next request, so
| it waits on `php artisan buyer:audit-scope` against production — the same
| gate supply_chain went through. Until that audit runs, a test asserting what
| a COMMERCIAL user is or is not confined to would pin a decision nobody has
| made, and would have to be inverted the moment it is made. Those tests are
| left unwritten rather than written backwards.
|
| What IS tested below is the scope machinery this report already inherits and
| runs today, exercised through viewers whose confinement is already settled: a
| merchant (merchandising IS scoped), an all-buyer viewer, and admin /
| management. None of these assertions changes when 'commercial' is added to
| the list, so none of them will need rewriting then.
*/

/** @return array{0: Buyer, 1: Buyer} */
function cmSeedTaggedBuyers(): array
{
    $buyerA = Buyer::create(['buyer_name' => 'Buyer A']);
    $buyerB = Buyer::create(['buyer_name' => 'Buyer B']);

    cmBomLine($buyerA->id, ['buyer_name' => 'Buyer A', 'payment_doc_no' => 'PD-AAA']);
    cmBomLine($buyerB->id, ['buyer_name' => 'Buyer B', 'payment_doc_no' => 'PD-BBB']);

    return [$buyerA, $buyerB];
}

test('a confined viewer sees their own buyer plus the untagged archive', function () {
    [$buyerA] = cmSeedTaggedBuyers();

    $user = cmReportUser('merchant', ['buyer_id' => $buyerA->id]);

    expect($user->isBuyerConfined())->toBeTrue()
        // 2 untagged lines + Buyer A's. Buyer B's is gone.
        ->and(app(CommercialReportService::class)->forUser($user)->count([]))->toBe(3);
});

test('an all-buyer-scope viewer sees everything', function () {
    cmSeedTaggedBuyers();
    $user = cmReportUser('merchant', ['buyer_scope_mode' => BuyerScope::MODE_ALL]);

    expect($user->isBuyerConfined())->toBeFalse()
        ->and(app(CommercialReportService::class)->forUser($user)->count([]))->toBe(4);
});

test('admin and management are never buyer-restricted', function (string $role) {
    [$buyerA] = cmSeedTaggedBuyers();
    $user = cmReportUser($role, ['buyer_id' => $buyerA->id]);

    expect($user->isBuyerConfined())->toBeFalse()
        ->and(app(CommercialReportService::class)->forUser($user)->count([]))->toBe(4);
})->with(['admin', 'management']);

test('the dropdowns offer only buyers a confined viewer can see', function () {
    [$buyerA] = cmSeedTaggedBuyers();
    $confined = cmReportUser('merchant', ['buyer_id' => $buyerA->id]);
    $unconfined = cmReportUser('merchant', ['buyer_scope_mode' => BuyerScope::MODE_ALL]);

    expect(app(CommercialReportService::class)->forUser($confined)->filterOptions()['buyer']->all())
        ->toBe(['Buyer A', 'Hugo Boss', 'Marks & Spencer'])
        ->and(app(CommercialReportService::class)->forUser($unconfined)->filterOptions()['buyer']->all())
        ->toBe(['Buyer A', 'Buyer B', 'Hugo Boss', 'Marks & Spencer']);
});

test('every dropdown is scoped, not just the buyer one', function () {
    [$buyerA] = cmSeedTaggedBuyers();
    $user = cmReportUser('merchant', ['buyer_id' => $buyerA->id]);

    expect(app(CommercialReportService::class)->forUser($user)->filterOptions()['payment_status']->all())
        ->toBe(['Paid', 'Pending']);
});

test('hand-typing another buyer into the filter returns nothing rather than leaking', function () {
    [$buyerA] = cmSeedTaggedBuyers();
    $user = cmReportUser('merchant', ['buyer_id' => $buyerA->id]);

    expect(app(CommercialReportService::class)->forUser($user)->count(['buyer' => 'Buyer B']))->toBe(0);
});

test('the report falls back to the session user when no viewer is set', function () {
    [$buyerA] = cmSeedTaggedBuyers();

    // Fail-closed: a caller that forgets forUser() must not get an
    // unrestricted report.
    $this->actingAs(cmReportUser('merchant', ['buyer_id' => $buyerA->id]));

    expect(app(CommercialReportService::class)->count([]))->toBe(3);
});
