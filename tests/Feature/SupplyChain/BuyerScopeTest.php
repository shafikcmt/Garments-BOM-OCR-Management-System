<?php

use App\Models\Buyer;
use App\Models\BookingPo;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestItem;
use App\Models\User;
use App\Support\BuyerScope;
use Spatie\Permission\Models\Role;

/**
 * Buyer scoping on the Supply Chain screens — PO Generate, PRA, the dashboard
 * and the email history.
 *
 * The department's report already had it (tests/Feature/SupplyChain/ReportTest);
 * these lock the three modules that read buyer-linked data outside a report, and
 * they lock BOTH halves of the rule, because the halves fail differently:
 *
 *   VISIBILITY   an out-of-scope PO is absent from every list.
 *   REFUSAL      an out-of-scope PO is 403 when its id is named directly. A
 *                list that omits a record and an action that silently does
 *                nothing look identical to a user and mean opposite things, so
 *                every write path is asserted as "refused", never as "absent".
 *
 * THREE BUYER STATES, because they are three different code paths and the
 * dangerous one is the middle: one buyer, several buyers, all buyers.
 *
 *   one buyer    buyer_scope_mode 'specific', one row in buyer_user
 *   several      'specific', several rows — the case a first-buyer-only bug
 *                passes with one buyer and fails with two
 *   all buyers   'all', no rows — must see everything, unchanged
 *
 * The UNTAGGED file is in every fixture on purpose. Every live BOM file carries
 * a null buyer_id today, so a scope that drops untagged rows gives the whole
 * department empty screens — the failure this suite is most likely to catch.
 */
function scScopeUser(string $role, array $buyerIds = [], string $mode = BuyerScope::MODE_ALL): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create([
        'buyer_scope_mode' => $mode,
        'buyer_id' => $buyerIds[0] ?? null,
    ])->assignRole($role);

    if ($buyerIds !== []) {
        $user->buyers()->sync($buyerIds);
    }

    return $user->fresh();
}

/** One BOM file on the given buyer (null = untagged), carrying one line. */
function scScopeFile(?int $buyerId, array $valuesByKey): ExcelRow
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

    foreach ($valuesByKey as $key => $value) {
        ExcelCell::create([
            'row_id' => $row->id,
            'header_id' => ExcelHeader::where('header_key', $key)->value('id'),
            'value' => $value,
        ]);
    }

    return $row->fresh();
}

/** A generated PO on a BOM row, eligible to be raised as a PRA. */
function scScopePo(ExcelRow $row, string $poNo): BookingPo
{
    return BookingPo::create([
        'excel_file_id' => $row->excel_file_id,
        'excel_row_id' => $row->id,
        'po_no' => $poNo,
        'buyer_name' => 'whatever the cell said',
        'vendor_name' => 'Vendor',
        'generated_at' => now(),
        // Completed, so the PO row drops out of the PENDING list and the two
        // lists on that screen can be asserted apart from one another.
        'status' => 'completed',
        'completed_at' => now(),
    ]);
}

beforeEach(function () {
    $supply = Role::findOrCreate('supply_chain', 'web');
    $merchant = Role::findOrCreate('merchant', 'web');
    Role::findOrCreate('admin', 'web');

    $header = function (string $key, string $name, Role $owner, int $position) {
        ExcelHeader::create([
            'header_name' => $name,
            'header_key' => $key,
            'owner_role_id' => $owner->id,
            'position' => $position,
            'field_type' => 'text',
            'is_active' => true,
        ]);
    };

    // Enough of the grid for a row to count as "has booking source data" and
    // for a PO to count as eligible for a PRA.
    $header('buyer_name', 'Buyer Name', $merchant, 1);
    $header('style_name', 'Style Name', $merchant, 2);
    $header('vendor_name', 'Vendor Name', $supply, 10);
    $header('material_po_number', 'Material PO Number', $supply, 11);
    $header('pi_number', 'PI Number', $supply, 12);
    $header('pi_status', 'PI Status', $supply, 13);

    $this->buyerA = Buyer::create(['buyer_name' => 'Buyer A']);
    $this->buyerB = Buyer::create(['buyer_name' => 'Buyer B']);

    $line = fn (string $buyer, string $pi) => [
        'buyer_name' => $buyer,
        'style_name' => 'Style ' . $buyer,
        'vendor_name' => 'Vendor ' . $buyer,
        'pi_number' => $pi,
        'pi_status' => 'PI Received',
    ];

    // Pending rows — no PO number in the grid, so PO Generate lists them.
    $this->pendingA = scScopeFile($this->buyerA->id, $line('Alpha', 'PI-A'));
    $this->pendingB = scScopeFile($this->buyerB->id, $line('Bravo', 'PI-B'));
    $this->pendingUntagged = scScopeFile(null, $line('Charlie', 'PI-U'));

    // Generated POs, on their own rows so they do not remove the pending ones.
    $this->poA = scScopePo(scScopeFile($this->buyerA->id, $line('Alpha', 'PI-A2')), 'PO-A');
    $this->poB = scScopePo(scScopeFile($this->buyerB->id, $line('Bravo', 'PI-B2')), 'PO-B');
    $this->poUntagged = scScopePo(scScopeFile(null, $line('Charlie', 'PI-U2')), 'PO-U');
});

/*
|--------------------------------------------------------------------------
| PO Generate — the pending list
|--------------------------------------------------------------------------
*/

it('shows a one-buyer user their own pending rows plus the untagged archive', function () {
    $user = scScopeUser('supply_chain', [$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    $response = $this->actingAs($user)
        ->get(route('supply_chain.bookings.data'))
        ->assertOk();

    expect($response->json('pending_total'))->toBe(2);
    expect($response->json('rows_html'))
        ->toContain('Alpha')
        ->toContain('Charlie')
        ->not->toContain('Bravo');
});

it('shows a several-buyer user every one of their buyers, not only the first', function () {
    $user = scScopeUser(
        'supply_chain',
        [$this->buyerA->id, $this->buyerB->id],
        BuyerScope::MODE_SPECIFIC
    );

    $response = $this->actingAs($user)
        ->get(route('supply_chain.bookings.data'))
        ->assertOk();

    expect($response->json('pending_total'))->toBe(3);
    expect($response->json('rows_html'))
        ->toContain('Alpha')
        ->toContain('Bravo')
        ->toContain('Charlie');
});

it('shows an all-buyers user everything, exactly as before', function () {
    $user = scScopeUser('supply_chain');

    $response = $this->actingAs($user)
        ->get(route('supply_chain.bookings.data'))
        ->assertOk();

    expect($response->json('pending_total'))->toBe(3);
    expect($user->isBuyerConfined())->toBeFalse();
});

/* The generated half of the same screen must agree with the pending half —
 * PO numbers for rows the user cannot see would be a leak by another name. */
it('scopes the generated PO list on the same screen', function () {
    $user = scScopeUser('supply_chain', [$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    $response = $this->actingAs($user)
        ->get(route('supply_chain.bookings.index'))
        ->assertOk();

    $response->assertSee('PO-A')
        ->assertSee('PO-U')
        ->assertDontSee('PO-B');
});

/*
|--------------------------------------------------------------------------
| PO Generate — refusal, not absence
|--------------------------------------------------------------------------
*/

it('refuses a PO belonging to another buyer', function () {
    $user = scScopeUser('supply_chain', [$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    $this->actingAs($user)->get(route('supply_chain.bookings.show', $this->poB))->assertForbidden();
    $this->actingAs($user)->get(route('supply_chain.bookings.print', $this->poB))->assertForbidden();
    $this->actingAs($user)->get(route('supply_chain.bookings.download', $this->poB))->assertForbidden();
});

it('allows a PO on their own buyer, and an untagged one', function () {
    $user = scScopeUser('supply_chain', [$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    $this->actingAs($user)->get(route('supply_chain.bookings.show', $this->poA))->assertOk();
    $this->actingAs($user)->get(route('supply_chain.bookings.show', $this->poUntagged))->assertOk();
});

it('refuses generating a PO from another buyer row', function () {
    $user = scScopeUser('supply_chain', [$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    $this->actingAs($user)
        ->post(route('supply_chain.bookings.preview', $this->pendingB))
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('supply_chain.bookings.generate', $this->pendingB))
        ->assertForbidden();

    expect(BookingPo::where('excel_row_id', $this->pendingB->id)->exists())->toBeFalse();
});

/* One out-of-scope id in a bulk selection refuses the WHOLE request. Completing
 * the in-scope subset would report a count the user cannot reconcile. */
it('refuses a bulk complete that names another buyer PO', function () {
    $user = scScopeUser('supply_chain', [$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    // Back to not-completed, so "still not completed" afterwards means the
    // request was refused rather than that it had nothing left to do.
    $this->poA->update(['status' => 'applied', 'completed_at' => null]);
    $this->poB->update(['status' => 'applied', 'completed_at' => null]);

    $this->actingAs($user)
        ->post(route('supply_chain.bookings.bulk_complete'), [
            'booking_pos' => [$this->poA->id, $this->poB->id],
        ])
        ->assertForbidden();

    expect($this->poA->fresh()->status)->not->toBe('completed')
        ->and($this->poB->fresh()->status)->not->toBe('completed');
});

/*
|--------------------------------------------------------------------------
| PRA — pending PO/PI rows, the raised list, and the write paths
|--------------------------------------------------------------------------
*/

it('hides another buyer PO from the PRA pending list', function () {
    $user = scScopeUser('supply_chain', [$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    $this->actingAs($user)
        ->get(route('supply_chain.payment_requests.index'))
        ->assertOk()
        ->assertSee('PO-A')
        ->assertSee('PO-U')
        ->assertDontSee('PO-B');
});

it('shows an all-buyers user every PO in the PRA pending list', function () {
    $user = scScopeUser('supply_chain');

    $this->actingAs($user)
        ->get(route('supply_chain.payment_requests.index'))
        ->assertOk()
        ->assertSee('PO-A')
        ->assertSee('PO-B')
        ->assertSee('PO-U');
});

it('refuses raising a PRA against another buyer PO', function () {
    $user = scScopeUser('supply_chain', [$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    $this->actingAs($user)
        ->get(route('supply_chain.payment_requests.preview', ['booking_po_ids' => [$this->poB->id]]))
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('supply_chain.payment_requests.store'), [
            'booking_po_ids' => [$this->poB->id],
        ])
        ->assertForbidden();

    expect(PaymentRequest::count())->toBe(0);
});

it('refuses reading a PRA raised for another buyer', function () {
    $user = scScopeUser('supply_chain', [$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    $pra = PaymentRequest::create([
        'request_no' => 'PRA-1',
        'created_by' => scScopeUser('supply_chain')->id,
    ]);

    PaymentRequestItem::create([
        'payment_request_id' => $pra->id,
        'booking_po_id' => $this->poB->id,
        'excel_file_id' => $this->poB->excel_file_id,
        'po_no' => 'PO-B',
    ]);

    $this->actingAs($user)->get(route('supply_chain.payment_requests.show', $pra))->assertForbidden();
    $this->actingAs($user)->get(route('supply_chain.payment_requests.download_pdf', $pra))->assertForbidden();

    $this->actingAs($user)
        ->get(route('supply_chain.payment_requests.index'))
        ->assertOk()
        ->assertDontSee('PRA-1');
});

it('allows a PRA raised for their own buyer', function () {
    $user = scScopeUser('supply_chain', [$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    $pra = PaymentRequest::create([
        'request_no' => 'PRA-2',
        'created_by' => $user->id,
    ]);

    PaymentRequestItem::create([
        'payment_request_id' => $pra->id,
        'booking_po_id' => $this->poA->id,
        'excel_file_id' => $this->poA->excel_file_id,
        'po_no' => 'PO-A',
    ]);

    $this->actingAs($user)->get(route('supply_chain.payment_requests.show', $pra))->assertOk();
});

/*
|--------------------------------------------------------------------------
| The departments scoping is NOT switched on for, and the super admin
|--------------------------------------------------------------------------
|
| These are the regression guards. Buyer scoping is data visibility; it must
| never narrow anyone it was not switched on for, and never narrow the person
| whose job is to see everything.
*/

it('never restricts a super admin', function () {
    $admin = scScopeUser('admin', [$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    // Even holding buyers and set to 'specific' — the department gate refuses
    // to read them for the super admin, by name, before any comparison.
    expect($admin->isBuyerConfined())->toBeFalse()
        ->and($admin->scopedBuyerIds())->toBe([]);
});

it('leaves a merchant with the buyer rule their own department already had', function () {
    $merchant = scScopeUser('merchant', [$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    expect($merchant->isBuyerConfined())->toBeTrue()
        ->and($merchant->scopedBuyerIds())->toBe([$this->buyerA->id]);
});

it('leaves an unscoped role unconfined even holding a stray buyer', function () {
    Role::findOrCreate('store_general_stock', 'web');

    // A stray buyer_id on a General Stock account is inert because the gate
    // refuses to read it — the property that lets the scope list grow without
    // touching anybody else. Note this is now a ROLE carve-out rather than a
    // whole department: Store IS scoped, General Stock inside it is not,
    // because general stock carries no buyer column at all.
    $generalStock = scScopeUser('store_general_stock', [$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    expect($generalStock->isBuyerConfined())->toBeFalse()
        ->and($generalStock->scopedBuyerIds())->toBe([]);
});

/*
|--------------------------------------------------------------------------
| MySQL / PostgreSQL
|--------------------------------------------------------------------------
|
| Local development is MySQL, production is PostgreSQL and this suite runs on
| SQLite — three dialects, and green tests here prove nothing about the one
| that matters. The scope is built entirely from query-builder methods with no
| raw SQL, no LIKE and no date or string function, so it compiles on all three;
| this pins that, by reading the SQL the real PostgreSQL grammar produces.
*/
it('compiles to portable SQL on postgresql', function () {
    config()->set('database.connections.pgcheck', [
        'driver' => 'pgsql',
        'host' => 'localhost',
        'database' => 'unused',
        'username' => 'unused',
        'password' => 'unused',
        'charset' => 'utf8',
        'prefix' => '',
        'schema' => 'public',
    ]);

    $user = scScopeUser('supply_chain', [$this->buyerA->id], BuyerScope::MODE_SPECIFIC);
    $this->actingAs($user);

    $scoper = new class {
        use \App\Support\Concerns\ScopesToAssignedBuyers;

        public function scope($query): void
        {
            $this->applyBuyerScope($query);
        }
    };

    $query = BookingPo::on('pgcheck');
    $scoper->scope($query);

    $sql = $query->toSql();

    // Quoted with double quotes, no backticks, and none of the constructs that
    // differ between engines.
    expect($sql)
        ->toContain('"buyer_id" is null')
        ->toContain('"buyer_id" in (?)')
        ->not->toContain('`')
        ->not->toContain('ilike')
        ->not->toContain('DATE_FORMAT')
        ->not->toContain('CONCAT');
});
