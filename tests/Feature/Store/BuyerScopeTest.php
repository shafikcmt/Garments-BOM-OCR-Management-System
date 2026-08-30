<?php

use App\Models\BookingPo;
use App\Models\Buyer;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\MaterialBulkIssue;
use App\Models\MaterialReceiving;
use App\Models\MaterialRequisition;
use App\Models\MaterialStockLedger;
use App\Models\StockItem;
use App\Models\User;
use App\Support\BuyerScope;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Buyer scoping on the Store screens — Receiving, Requisitions, Bulk Issue and
 * the Closing Stock ledger.
 *
 * Store is the last department switched on and the only awkward one, because
 * one department key covers two businesses:
 *
 *   Buyer / Style stock   every table carries excel_file_id, so every row can
 *                         be traced to a buyer. Scoped.
 *   General Stock         stock_items / stock_purchases / stock_issues carry no
 *                         buyer at all. Carved out by BuyerScope::UNSCOPED_ROLES,
 *                         and the last block below is the test that says so.
 *
 * Both halves of the rule are locked for every screen, because they fail
 * differently and a user cannot tell them apart:
 *
 *   VISIBILITY   another buyer's record is absent from the list.
 *   REFUSAL      another buyer's record is 403 when its id is POSTed directly.
 *                Reaching that point means the id was supplied by hand, and an
 *                action that silently does nothing looks exactly like one that
 *                worked.
 *
 * The UNTAGGED file appears in every fixture on purpose. Every live BOM file
 * has a null buyer_id today, so a scope that dropped untagged rows would give
 * Store empty screens on the day it was switched on — the failure this suite is
 * most likely to catch.
 */

/** A Store user, optionally confined to specific buyers. */
function storeScopeUser(array $buyerIds = [], string $mode = BuyerScope::MODE_ALL, string $role = 'store'): User
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

/** The same user, plus the correction rights the delete paths demand. */
function storeScopeCorrector(array $buyerIds = [], string $mode = BuyerScope::MODE_ALL): User
{
    $user = storeScopeUser($buyerIds, $mode);

    foreach (['store.edit', 'store.delete'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $user->givePermissionTo(['store.edit', 'store.delete']);

    return $user->fresh();
}

/** One BOM header, created once and shared across rows. */
function storeScopeHeader(string $key, string $name, int $position): ExcelHeader
{
    return ExcelHeader::firstOrCreate(
        ['header_key' => $key],
        [
            'header_name' => $name,
            'position' => $position,
            'owner_role_id' => Role::findOrCreate('merchant', 'web')->id,
        ]
    );
}

/**
 * One BOM file on the given buyer (null = untagged) with one PO line under it.
 *
 * The line carries real BOM CELLS as well as the booking_pos columns, because
 * the search paths reach a PO by two different routes and only cells exercise
 * the second: PO Number and Style match on the booking_pos column, while PI
 * Number lives only in the worksheet and has to be mapped back to its owning
 * PO. A fixture with columns alone would leave that half of the scope untested.
 */
function storeScopePo(?int $buyerId, string $poNo): BookingPo
{
    $file = ExcelFile::create([
        'file_name' => 'bom.xlsx',
        'original_file_name' => 'bom.xlsx',
        'file_path' => 'bom.xlsx',
        'status' => 'completed',
        'buyer_id' => $buyerId,
        'uploaded_by' => User::factory()->create()->id,
    ]);

    $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => 1]);

    $cells = [
        'material_po_number' => ['Material PO Number', 1, $poNo],
        'style_name' => ['Style Name', 2, 'STYLE-'.$poNo],
        'pi_number' => ['PI Number', 3, 'PI-'.$poNo],
        'material_name' => ['Material Name', 4, 'Woven Label'],
    ];

    foreach ($cells as $key => [$name, $position, $value]) {
        ExcelCell::create([
            'row_id' => $row->id,
            'header_id' => storeScopeHeader($key, $name, $position)->id,
            'value' => $value,
        ]);
    }

    return BookingPo::create([
        'excel_file_id' => $file->id,
        'excel_row_id' => $row->id,
        'po_no' => $poNo,
        'buyer_name' => 'Buyer '.($buyerId ?? 'none'),
        'season_name' => 'W26FA',
        'style_name' => 'STYLE-'.$poNo,
        'item_name' => 'Accessories',
        'qty' => 100,
    ]);
}

function storeScopeReceiving(BookingPo $po, string $grnNo): MaterialReceiving
{
    return MaterialReceiving::create([
        'excel_file_id' => $po->excel_file_id,
        'excel_row_id' => $po->excel_row_id,
        'booking_po_id' => $po->id,
        'po_no' => $po->po_no,
        'buyer_name' => $po->buyer_name,
        'season_name' => $po->season_name,
        'style_name' => $po->style_name,
        'material_name' => 'Accessories',
        'grn_no' => $grnNo,
        'receive_date' => now()->toDateString(),
        'grn_date' => now()->toDateString(),
        'source_type' => MaterialReceiving::SOURCE_BOOKING,
        'qty' => 10,
    ]);
}

function storeScopeRequisition(BookingPo $po, string $requisitionNo): MaterialRequisition
{
    return MaterialRequisition::create([
        'excel_file_id' => $po->excel_file_id,
        'excel_row_id' => $po->excel_row_id,
        'booking_po_id' => $po->id,
        'po_no' => $po->po_no,
        'buyer_name' => $po->buyer_name,
        'style_name' => $po->style_name,
        'requisition_no' => $requisitionNo,
        'status' => MaterialRequisition::STATUS_PENDING,
        'qty' => 5,
    ]);
}

function storeScopeIssue(BookingPo $po, string $issueNo): MaterialBulkIssue
{
    return MaterialBulkIssue::create([
        'excel_file_id' => $po->excel_file_id,
        'excel_row_id' => $po->excel_row_id,
        'booking_po_id' => $po->id,
        'po_no' => $po->po_no,
        'buyer_name' => $po->buyer_name,
        'season_name' => $po->season_name,
        'style_name' => $po->style_name,
        'material_name' => 'Accessories',
        'material_description' => 'Woven label',
        'issue_no' => $issueNo,
        'issue_date' => now()->toDateString(),
        'bulk_qty' => 10,
        'sample_qty' => 0,
        'liability_qty' => 0,
        'dead_qty' => 0,
    ]);
}

function storeScopeLedger(BookingPo $po): MaterialStockLedger
{
    return MaterialStockLedger::create([
        'excel_file_id' => $po->excel_file_id,
        'excel_row_id' => $po->excel_row_id,
        'booking_po_id' => $po->id,
        'po_no' => $po->po_no,
        'buyer_name' => $po->buyer_name,
        'season_name' => $po->season_name,
        'style_name' => $po->style_name,
        'material_description' => 'Woven label',
        'total_receive_qty' => 100,
        'liability_closing_qty' => 50,
        'dead_closing_qty' => 50,
    ]);
}

/**
 * mine / theirs / untagged, in that order — the three states every assertion
 * below reads. A scope bug that hides untagged rows and a scope bug that shows
 * everything both pass a fixture holding only two of them.
 */
beforeEach(function () {
    $this->buyerA = Buyer::create(['buyer_name' => 'Hugo Boss']);
    $this->buyerB = Buyer::create(['buyer_name' => 'Zara']);

    $this->mine = storeScopePo($this->buyerA->id, 'PO-MINE');
    $this->theirs = storeScopePo($this->buyerB->id, 'PO-THEIRS');
    $this->untagged = storeScopePo(null, 'PO-UNTAGGED');
});

// --- Receiving -------------------------------------------------------------

it('shows a confined store user only their own and untagged receivings', function () {
    storeScopeReceiving($this->mine, 'GRN-MINE');
    storeScopeReceiving($this->theirs, 'GRN-THEIRS');
    storeScopeReceiving($this->untagged, 'GRN-UNTAGGED');

    $user = storeScopeUser([$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    $grns = collect($this->actingAs($user)
        ->get(route('store.material.receivings.index'))
        ->assertOk()
        ->viewData('receivings')
        ->items())->pluck('grn_no')->all();

    expect($grns)->toContain('GRN-MINE')
        ->and($grns)->toContain('GRN-UNTAGGED')
        ->and($grns)->not->toContain('GRN-THEIRS');
});

it('leaves an all-buyers store user seeing every receiving', function () {
    storeScopeReceiving($this->mine, 'GRN-MINE');
    storeScopeReceiving($this->theirs, 'GRN-THEIRS');

    $grns = collect($this->actingAs(storeWorkspaceUser())
        ->get(route('store.material.receivings.index'))
        ->assertOk()
        ->viewData('receivings')
        ->items())->pluck('grn_no')->all();

    expect($grns)->toContain('GRN-MINE')
        ->and($grns)->toContain('GRN-THEIRS');
});

it('refuses a receiving POSTed against another buyer PO', function () {
    $user = storeScopeUser([$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    $this->actingAs($user)->post(route('store.material.receivings.store'), [
        'booking_po_id' => $this->theirs->id,
        'rows' => [[
            'excel_row_id' => $this->theirs->excel_row_id,
            'receive_date' => now()->toDateString(),
            'source_type' => 'booking',
            'qty' => 5,
        ]],
    ])->assertForbidden();

    expect(MaterialReceiving::count())->toBe(0);
});

it('allows a receiving against an untagged PO, which belongs to no buyer', function () {
    $user = storeScopeUser([$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    // Asserting "not 403" rather than a success: the item-row guard inside
    // store() may still reject this minimal fixture. What matters here is that
    // the BUYER guard did not — a 403 would mean the whole pre-tagging archive
    // had been locked away from a confined user.
    $this->actingAs($user)->post(route('store.material.receivings.store'), [
        'booking_po_id' => $this->untagged->id,
        'rows' => [[
            'excel_row_id' => $this->untagged->excel_row_id,
            'receive_date' => now()->toDateString(),
            'source_type' => 'booking',
            'qty' => 5,
        ]],
    ])->assertStatus(302);
});

it('refuses deleting another buyer receiving', function () {
    $theirs = storeScopeReceiving($this->theirs, 'GRN-THEIRS');
    $user = storeScopeCorrector([$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    $this->actingAs($user)
        ->delete(route('store.material.receivings.destroy', $theirs))
        ->assertForbidden();

    expect(MaterialReceiving::whereKey($theirs->id)->exists())->toBeTrue();
});

it('refuses the PO lookup endpoints for another buyer PO', function () {
    $user = storeScopeUser([$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    $this->actingAs($user)
        ->getJson(route('store.material.receivings.po-details', $this->theirs))
        ->assertForbidden();

    $this->actingAs($user)
        ->getJson(route('store.material.receivings.po-items', $this->theirs))
        ->assertForbidden();

    // ...and still answers for their own.
    $this->actingAs($user)
        ->getJson(route('store.material.receivings.po-details', $this->mine))
        ->assertOk();
});

// --- Requisitions ----------------------------------------------------------

it('shows a confined store user only their own and untagged requisitions', function () {
    storeScopeRequisition($this->mine, 'REQ-MINE');
    storeScopeRequisition($this->theirs, 'REQ-THEIRS');
    storeScopeRequisition($this->untagged, 'REQ-UNTAGGED');

    $user = storeScopeUser([$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    $res = $this->actingAs($user)->get(route('store.material.requisitions.index'))->assertOk();

    $nos = collect($res->viewData('requisitions')->items())->pluck('requisition_no')->all();

    expect($nos)->toContain('REQ-MINE')
        ->and($nos)->toContain('REQ-UNTAGGED')
        ->and($nos)->not->toContain('REQ-THEIRS');

    // The PO picker that feeds the form is scoped by the same rule, so it never
    // offers a PO the save below would refuse.
    $poNos = collect($res->viewData('poGroups'))->pluck('po_no')->all();

    expect($poNos)->toContain('PO-MINE')
        ->and($poNos)->toContain('PO-UNTAGGED')
        ->and($poNos)->not->toContain('PO-THEIRS');
});

it('refuses raising a requisition against another buyer PO', function () {
    $user = storeScopeUser([$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    $this->actingAs($user)->post(route('store.material.requisitions.store'), [
        'po_no' => 'PO-THEIRS',
        'items' => [$this->theirs->id => ['issued_qty' => 5]],
    ])->assertForbidden();

    expect(MaterialRequisition::count())->toBe(0);
});

it('refuses approving and deleting another buyer requisition', function () {
    $theirs = storeScopeRequisition($this->theirs, 'REQ-THEIRS');
    $user = storeScopeCorrector([$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    $this->actingAs($user)
        ->patch(route('store.material.requisitions.approve', $theirs))
        ->assertForbidden();

    $this->actingAs($user)
        ->delete(route('store.material.requisitions.destroy', $theirs))
        ->assertForbidden();

    expect($theirs->fresh()->status)->toBe(MaterialRequisition::STATUS_PENDING);
});

it('still lets a confined user raise a requisition on their own PO', function () {
    $user = storeScopeUser([$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    $this->actingAs($user)->post(route('store.material.requisitions.store'), [
        'po_no' => 'PO-MINE',
        'items' => [$this->mine->id => ['issued_qty' => 5]],
    ])->assertStatus(302);

    expect(MaterialRequisition::where('po_no', 'PO-MINE')->count())->toBe(1);
});

// --- Bulk Issue ------------------------------------------------------------

it('shows a confined store user only their own and untagged bulk issues', function () {
    storeScopeIssue($this->mine, 'BI-MINE');
    storeScopeIssue($this->theirs, 'BI-THEIRS');
    storeScopeIssue($this->untagged, 'BI-UNTAGGED');

    $user = storeScopeUser([$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    // Asserted on the PO number, which is what the row actually prints — the
    // issue_no never reaches the history markup.
    $this->actingAs($user)
        ->get(route('store.material.bulk-issues.index', ['partial' => 1]))
        ->assertOk()
        ->assertSee('PO-MINE')
        ->assertSee('PO-UNTAGGED')
        ->assertDontSee('PO-THEIRS')
        // The tab count badge reads the same scoped query as the list. A badge
        // reading 3 above a list of 2 is a support call.
        ->assertSee('&quot;all&quot;:2', false);
});

it('refuses a bulk delete naming another buyer issue', function () {
    $mineIssue = storeScopeIssue($this->mine, 'BI-MINE');
    $theirsIssue = storeScopeIssue($this->theirs, 'BI-THEIRS');

    $user = storeScopeCorrector([$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    // Refused whole, not silently trimmed to the allowed half — the user's own
    // issue survives too, so the count they see matches what they ticked.
    $this->actingAs($user)->post(route('store.material.bulk-issues.bulk-destroy'), [
        'ids' => [$mineIssue->id, $theirsIssue->id],
    ])->assertForbidden();

    expect(MaterialBulkIssue::count())->toBe(2);
});

// --- Closing Stock ledger --------------------------------------------------

it('shows a confined store user only their own and untagged ledger rows', function () {
    storeScopeLedger($this->mine);
    storeScopeLedger($this->theirs);
    storeScopeLedger($this->untagged);

    $user = storeScopeUser([$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    $res = $this->actingAs($user)->get(route('store.material.ledger'))->assertOk();

    $poNos = collect($res->viewData('ledgers')->items())->pluck('po_no')->all();

    expect($poNos)->toContain('PO-MINE')
        ->and($poNos)->toContain('PO-UNTAGGED')
        ->and($poNos)->not->toContain('PO-THEIRS');

    // The Buyer dropdown and the summary cards read the same scope as the rows.
    // A card totalling the whole factory above a one-buyer list is both a leak
    // and a figure nobody can reconcile.
    expect($res->viewData('buyers')->all())->not->toContain('Buyer '.$this->buyerB->id)
        ->and($res->viewData('totals')['liability'])->toBe(100.0);
});

it('refuses a liability or dead movement on another buyer ledger row', function () {
    $theirs = storeScopeLedger($this->theirs);
    $user = storeScopeUser([$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    $this->actingAs($user)->post(route('store.material.ledger.liability', $theirs), [
        'transfer_to_bulk_qty' => 5,
    ])->assertForbidden();

    $this->actingAs($user)->post(route('store.material.ledger.dead', $theirs), [
        'transfer_to_bulk_qty' => 5,
    ])->assertForbidden();
});

// --- The shared search service ---------------------------------------------

/**
 * BookingPoSourceService is the picker behind Store's PO search, style search
 * and style-BOM lookup, and it is SHARED by Material Receiving and Material
 * Bulk Issue. It was the last read-side gap: every list on both screens was
 * scoped, but the search box next to them still reached every buyer in the
 * factory, which is the one place a confined user could go looking.
 *
 * The scope sits on the service's six search/browse methods rather than on the
 * two controllers, so both consumers get it from one place. The per-record
 * methods (paymentSnapshot, itemRowsForBookingPo, sourceValueForBookingPo) are
 * deliberately NOT scoped — Supply Chain and the console commands use those,
 * and narrowing them would turn an access rule into wrong PO totals.
 */
it('scopes the PO search by term for a confined user', function () {
    $user = storeScopeUser([$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    // 'PO-' matches all three fixtures, so anything missing is the scope's doing
    // and not the search term's.
    $results = $this->actingAs($user)
        ->getJson(route('store.material.receivings.po-search', ['type' => 'po_no', 'term' => 'PO-']))
        ->assertOk()
        ->json('results');

    $poNos = collect($results)->pluck('po_no')->all();

    expect($poNos)->toContain('PO-MINE')
        ->and($poNos)->toContain('PO-UNTAGGED')
        ->and($poNos)->not->toContain('PO-THEIRS');
});

it('scopes the PO browse list, which is the search with no term at all', function () {
    $user = storeScopeUser([$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    $poNos = collect($this->actingAs($user)
        ->getJson(route('store.material.receivings.po-search', ['type' => 'po_no', 'term' => '']))
        ->assertOk()
        ->json('results'))->pluck('po_no')->all();

    expect($poNos)->toContain('PO-MINE')
        ->and($poNos)->toContain('PO-UNTAGGED')
        ->and($poNos)->not->toContain('PO-THEIRS');
});

it('scopes a PI Number search, which reaches its PO through the BOM cells', function () {
    $user = storeScopeUser([$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    // The other route into a PO: PI Number exists only as a worksheet cell and
    // is mapped back to the owning booking. Scoping the column route alone would
    // leave this one open.
    $poNos = collect($this->actingAs($user)
        ->getJson(route('store.material.receivings.po-search', ['type' => 'pi_number', 'term' => 'PI-']))
        ->assertOk()
        ->json('results'))->pluck('po_no')->all();

    expect($poNos)->toContain('PO-MINE')
        ->and($poNos)->toContain('PO-UNTAGGED')
        ->and($poNos)->not->toContain('PO-THEIRS');
});

it('leaves the PO search complete for an all-buyers user', function () {
    $poNos = collect($this->actingAs(storeWorkspaceUser())
        ->getJson(route('store.material.receivings.po-search', ['type' => 'po_no', 'term' => 'PO-']))
        ->assertOk()
        ->json('results'))->pluck('po_no')->all();

    expect($poNos)->toContain('PO-MINE')
        ->and($poNos)->toContain('PO-THEIRS')
        ->and($poNos)->toContain('PO-UNTAGGED');
});

it('scopes the style search on the Independent receiving form', function () {
    $user = storeScopeUser([$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    $styles = collect($this->actingAs($user)
        ->getJson(route('store.material.receivings.style-search', ['term' => 'STYLE-']))
        ->assertOk()
        ->json('results'))->pluck('style_name')->all();

    expect($styles)->toContain('STYLE-PO-MINE')
        ->and($styles)->toContain('STYLE-PO-UNTAGGED')
        ->and($styles)->not->toContain('STYLE-PO-THEIRS');
});

it('leaves the style search complete for an all-buyers user', function () {
    $styles = collect($this->actingAs(storeScopeUser())
        ->getJson(route('store.material.receivings.style-search', ['term' => 'STYLE-']))
        ->assertOk()
        ->json('results'))->pluck('style_name')->all();

    expect($styles)->toContain('STYLE-PO-MINE')
        ->and($styles)->toContain('STYLE-PO-THEIRS');
});

it('returns no style-BOM lines for another buyer style', function () {
    $user = storeScopeUser([$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    // Their own style still suggests its materials...
    $mine = $this->actingAs($user)
        ->getJson(route('store.material.receivings.style-bom', ['style_name' => 'STYLE-PO-MINE']))
        ->assertOk()
        ->json('lines');

    expect($mine)->not->toBeEmpty();

    // ...and another buyer's style answers with nothing rather than with that
    // buyer's material names, colours and vendors.
    $theirs = $this->actingAs($user)
        ->getJson(route('store.material.receivings.style-bom', ['style_name' => 'STYLE-PO-THEIRS']))
        ->assertOk()
        ->json('lines');

    expect($theirs)->toBeEmpty();

    // The same request from an unconfined user still returns them, so the empty
    // result above is the scope and not a broken fixture.
    $unconfined = $this->actingAs(storeScopeUser())
        ->getJson(route('store.material.receivings.style-bom', ['style_name' => 'STYLE-PO-THEIRS']))
        ->assertOk()
        ->json('lines');

    expect($unconfined)->not->toBeEmpty();
});

it('scopes the same shared search for Bulk Issue, not only for Receiving', function () {
    $user = storeScopeUser([$this->buyerA->id], BuyerScope::MODE_SPECIFIC);

    // The point of putting the scope in BookingPoSourceService rather than in
    // MaterialReceivingController: Bulk Issue's own PO search is the same
    // service, so it is scoped without a second copy of the rule.
    // `q` is Bulk Issue's smart-search box, which fans one term across every
    // handle Store recognises — so this also covers the loop over
    // bookingPosMatching(), not just a single group.
    $poNos = collect($this->actingAs($user)
        ->getJson(route('store.material.bulk-issues.po-search', ['q' => 'PO-']))
        ->assertOk()
        ->json('results'))->pluck('po_no')->filter()->all();

    expect($poNos)->toContain('PO-MINE')
        ->and($poNos)->not->toContain('PO-THEIRS');
});

// --- The General Stock carve-out -------------------------------------------

/**
 * THE CARVE-OUT. 'store' is a scoped department, but General Stock inside it
 * has no buyer anywhere in its data — stock_items, stock_purchases and
 * stock_issues carry no excel_file_id and no buyer column, because consumables
 * bought for the factory belong to no order.
 *
 * So a store_general_stock user stays UNCONFINED even when an admin has ticked
 * buyers onto their account. Confining them would buy nothing (there is no
 * buyer column to filter on) and cost something real: a BOM edit-lock they did
 * not have before, behind a Buyer Access control that changes nothing on any
 * screen they can open.
 */
it('never confines a General Stock user, even with buyers assigned', function () {
    $user = storeScopeUser([$this->buyerA->id], BuyerScope::MODE_SPECIFIC, 'store_general_stock');

    expect($user->isBuyerConfined())->toBeFalse()
        ->and(BuyerScope::appliesToRole('store_general_stock'))->toBeFalse()
        // The DEPARTMENT stays scoped — the carve-out belongs to the role, not
        // to Store, and appliesTo() must keep answering true so the Users form
        // still offers Buyer Access to the rest of the department.
        ->and(BuyerScope::appliesTo('store'))->toBeTrue()
        ->and(BuyerScope::appliesToRole('store'))->toBeTrue()
        ->and(BuyerScope::appliesToRole('store_material_stock'))->toBeTrue();
});

it('leaves the General Stock item list unfiltered for a buyer-assigned user', function () {
    StockItem::create(['name' => 'Sewing Thread', 'uom' => 'Cone', 'is_active' => true]);
    StockItem::create(['name' => 'Carton Tape', 'uom' => 'Roll', 'is_active' => true]);

    $user = storeScopeUser([$this->buyerA->id], BuyerScope::MODE_SPECIFIC, 'store_general_stock');
    $user->givePermissionTo(Permission::findOrCreate('store.items.view', 'web'));

    $this->actingAs($user->fresh())
        ->get(route('store.stock.items.index'))
        ->assertOk()
        ->assertSee('Sewing Thread')
        ->assertSee('Carton Tape');
});

// --- The workspace file list ------------------------------------------------
//
// NOTE: store.workspace is gated on the store.workspace.view PERMISSION, unlike
// every other screen in this file, which gate on role alone. storeWorkspaceRole()
// grants it before each test; without it the route answers 403 and the scope
// assertion never runs — a green-looking test that proved nothing.
//
// Added 2026-08-30, closing the last unscoped screen in this department.
//
// (Definition sits here beside its tests rather than up with the other helpers,
// because it is the only one that grants a permission.) It was
// a plain ExcelFile::with(['uploader'])->latest()->get(), on the old reading
// that buyer scoping on a workspace was an EDIT LOCK rather than a visibility
// filter. That reading leaked buyer and style names into the list itself, so it
// was dropped across all five departments at once.
//
// The assertions go through the response BODY as well as the view data, because
// a row excluded from the query but still printed by some other partial would
// satisfy a count alone. The row's OPEN LINK is the identifier, not the file
// name: the shared table partial prints the name only inside a lock modal that
// non-admins never render, so a name-based assertion would pass whether the row
// was there or not. The trailing quote anchors the match so /uploaded-files/3
// cannot satisfy a search for /uploaded-files/30.

/** Grants the permission store.workspace is gated on, then builds the user. */
function storeWorkspaceUser(array $buyerIds = [], string $mode = BuyerScope::MODE_ALL, string $role = 'store'): User
{
    Permission::findOrCreate('store.workspace.view', 'web');
    Role::findOrCreate($role, 'web')->givePermissionTo('store.workspace.view');

    return storeScopeUser($buyerIds, $mode, $role);
}

it('hides another buyers files from the store workspace list', function () {
    $response = $this->actingAs(storeWorkspaceUser([$this->buyerA->id], BuyerScope::MODE_SPECIFIC))
        ->get(route('store.workspace'))
        ->assertOk();

    $link = fn (BookingPo $po) => route('uploaded-files.show', $po->excel_file_id) . '"';

    $response->assertSee($link($this->mine), false);
    $response->assertSee($link($this->untagged), false);
    $response->assertDontSee($link($this->theirs), false);
});

/* A scope that narrows the unconfined is a different bug from one that fails to
 * narrow the confined, so both directions are pinned. */
it('leaves the store workspace list whole for an unconfined user', function () {
    $response = $this->actingAs(storeWorkspaceUser())
        ->get(route('store.workspace'))
        ->assertOk();

    $link = fn (BookingPo $po) => route('uploaded-files.show', $po->excel_file_id) . '"';

    $response->assertSee($link($this->mine), false);
    $response->assertSee($link($this->theirs), false);
    $response->assertSee($link($this->untagged), false);
});

/* The ROLE carve-out, on this screen too.
 *
 * store_general_stock is in BuyerScope::UNSCOPED_ROLES because General Stock
 * data carries no buyer column at all, so such a user is never confined even
 * holding a buyer an admin ticked by mistake. Scoping the department's file
 * list must not quietly acquire a role check the rest of the department does
 * not have. */
it('never narrows a general stock user on the workspace list', function () {
    $user = storeWorkspaceUser([$this->buyerA->id], BuyerScope::MODE_SPECIFIC, 'store_general_stock');

    $response = $this->actingAs($user)
        ->get(route('store.workspace'))
        ->assertOk();

    $link = fn (BookingPo $po) => route('uploaded-files.show', $po->excel_file_id) . '"';

    $response->assertSee($link($this->theirs), false);
});
