<?php

use App\Models\IndentSection;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Models\StockItem;
use App\Models\StockPurchase;
use App\Models\User;
use App\Services\RequisitionFulfilmentService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Purchase Requisition for a user from ANOTHER department, and the
 * Requested vs Purchased vs Pending summary.
 *
 * Two things are pinned here, and they are the two the feature rests on:
 *
 *   1. WHO gets in. The permissions store.requisition.view / .create already
 *      existed and the route guards already accept them; what a user from
 *      Merchandising, Commercial or Accounts must now also get is a sidebar
 *      link, their own requisitions on the list, and a 404 on somebody else's.
 *   2. WHAT the summary says. Requested comes from the requisition items,
 *      Purchased from the Receiving records, Pending never goes negative.
 *
 * Store, Admin and Management visibility must not narrow, which is asserted
 * explicitly rather than assumed.
 *
 * In-memory sqlite. No real record touched.
 */

/** A user in another department, holding the two requisition permissions. */
function requisitionGrantee(string $department = 'merchant'): User
{
    Role::findOrCreate($department, 'web');

    $role = Role::findOrCreate($department.'-requisition', 'web');

    foreach (['store.requisition.view', 'store.requisition.create'] as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return User::factory()->create()->assignRole($department, $department.'-requisition');
}

function requisitionStoreUser(): User
{
    Role::findOrCreate('store', 'web');

    return User::factory()->create()->assignRole('store');
}

/** One submitted requisition for $qty of one item, raised by $user. */
function raiseRequisition(User $user, StockItem $item, float $qty, string $date = '2026-08-10'): PurchaseRequisition
{
    $requisition = PurchaseRequisition::create([
        'requisition_no' => 'REQ-'.$user->id.'-'.$item->id.'-'.substr(md5($date.$qty), 0, 6),
        'requisition_date' => $date,
        'mode' => PurchaseRequisition::MODE_MULTI,
        'status' => PurchaseRequisition::STATUS_SUBMITTED,
        'submitted_at' => now(),
        'created_by' => $user->id,
    ]);

    PurchaseRequisitionItem::create([
        'purchase_requisition_id' => $requisition->id,
        'stock_item_id' => $item->id,
        'sort_order' => 1,
        'qty_requested' => $qty,
    ]);

    return $requisition;
}

// --- 1. Who gets in ----------------------------------------------------------

it('lets a permission-holder from another department open and create', function () {
    $user = requisitionGrantee('merchant');

    $this->actingAs($user)->get(route('store.stock.requisitions.index'))->assertOk();
    $this->actingAs($user)->get(route('store.stock.requisitions.create'))->assertOk();
});

it('offers that user the Purchase Requisition link in their own sidebar block', function () {
    // Merchandising renders its OWN sidebar block, so the permission-driven
    // Store block never runs for them — without the shared nav partial they
    // would hold the access and have no link to reach it by.
    $html = $this->actingAs(requisitionGrantee('merchant'))
        ->get(route('store.stock.requisitions.index'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Purchase Requisition');
});

it('does not offer the link to a department user without the permission', function () {
    Role::findOrCreate('merchant', 'web');
    $user = User::factory()->create()->assignRole('merchant');

    $this->actingAs($user)
        ->get(route('store.stock.requisitions.index'))
        ->assertForbidden();
});

// --- 2. What they then see ---------------------------------------------------

it('shows a granted user their own requisitions and not another department\'s', function () {
    $item = StockItem::create(['name' => 'Sewing Thread 40/2', 'uom' => 'Cone', 'is_active' => true]);

    $mine = requisitionGrantee('merchant');
    $theirs = requisitionGrantee('commercial');

    $own = raiseRequisition($mine, $item, 10);
    $other = raiseRequisition($theirs, $item, 99);

    $html = $this->actingAs($mine)
        ->get(route('store.stock.requisitions.index'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain($own->requisition_no)
        ->and($html)->not->toContain($other->requisition_no);

    // Hiding a row from a list is not a guard: the other department's
    // requisition must not open by id either.
    $this->actingAs($mine)->get(route('store.stock.requisitions.show', $other))->assertNotFound();
    $this->actingAs($mine)->get(route('store.stock.requisitions.pdf', $other))->assertNotFound();
});

it('leaves Store, Admin and Management seeing every requisition', function () {
    $item = StockItem::create(['name' => 'Machine Oil', 'uom' => 'Ltr', 'is_active' => true]);

    $grantee = requisitionGrantee('account');
    $raised = raiseRequisition($grantee, $item, 25);

    foreach (['store', 'admin', 'management'] as $role) {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create()->assignRole($role);

        $this->actingAs($user)
            ->get(route('store.stock.requisitions.index'))
            ->assertOk()
            ->assertSee($raised->requisition_no);

        $this->actingAs($user)
            ->get(route('store.stock.requisitions.show', $raised))
            ->assertOk();
    }
});

// --- 3. Requested vs Purchased vs Pending ------------------------------------

it('reports requested, purchased and pending per item', function () {
    $item = StockItem::create(['name' => 'Needle DBx1 90/14', 'uom' => 'Pcs', 'is_active' => true]);

    raiseRequisition(requisitionStoreUser(), $item, 100);

    // Received against the same item in the same month, through the records the
    // Receiving report reads.
    StockPurchase::create([
        'stock_item_id' => $item->id,
        'supplier_name' => 'Star Traders',
        'purchase_date' => '2026-08-12',
        'challan_no' => 'CH-1',
        'qty' => 30,
        'unit_price' => 4,
    ]);

    $summary = app(RequisitionFulfilmentService::class)
        ->summary(requisitionStoreUser(), ['month' => '2026-08']);

    $row = $summary['rows']->firstWhere('item_id', $item->id);

    expect($row['requested'])->toBe(100.0)
        ->and($row['purchased'])->toBe(30.0)
        ->and($row['pending'])->toBe(70.0)
        ->and($summary['scoped'])->toBeFalse();
});

it('floors pending at zero and says so when more arrived than was asked for', function () {
    $item = StockItem::create(['name' => 'Bal Pen', 'uom' => 'pcs', 'is_active' => true]);

    raiseRequisition(requisitionStoreUser(), $item, 50);

    StockPurchase::create([
        'stock_item_id' => $item->id,
        'supplier_name' => 'Star Traders',
        'purchase_date' => '2026-08-20',
        'challan_no' => 'CH-2',
        'qty' => 80,
        'unit_price' => 5,
    ]);

    $row = app(RequisitionFulfilmentService::class)
        ->summary(requisitionStoreUser(), ['month' => '2026-08'])
        ['rows']->firstWhere('item_id', $item->id);

    // Never negative, and the excess is reported rather than swallowed.
    expect($row['pending'])->toBe(0.0)
        ->and($row['over_received'])->toBe(30.0);
});

it('leaves drafts out of Requested', function () {
    $item = StockItem::create(['name' => 'Ereasser/Rubber', 'uom' => 'pcs', 'is_active' => true]);
    $user = requisitionStoreUser();

    raiseRequisition($user, $item, 10);

    // A draft has not been raised yet; counting it would report demand the
    // store has never been told about.
    $draft = raiseRequisition($user, $item, 999, '2026-08-11');
    $draft->update(['status' => PurchaseRequisition::STATUS_DRAFT]);

    $row = app(RequisitionFulfilmentService::class)
        ->summary($user, ['month' => '2026-08'])
        ['rows']->firstWhere('item_id', $item->id);

    expect($row['requested'])->toBe(10.0);
});

it('scopes the summary to a granted user\'s own requisitions', function () {
    $item = StockItem::create(['name' => 'Scale 12in', 'uom' => 'pcs', 'is_active' => true]);

    $mine = requisitionGrantee('commercial');

    raiseRequisition($mine, $item, 20);
    raiseRequisition(requisitionStoreUser(), $item, 500);

    $summary = app(RequisitionFulfilmentService::class)->summary($mine, ['month' => '2026-08']);
    $row = $summary['rows']->firstWhere('item_id', $item->id);

    // Their own 20, not the company's 520.
    expect($row['requested'])->toBe(20.0)
        ->and($summary['scoped'])->toBeTrue();
});

it('renders the summary on the requisition screen under the month filter', function () {
    $item = StockItem::create(['name' => 'Needle DBx1 90/14', 'uom' => 'Pcs', 'is_active' => true]);
    $user = requisitionStoreUser();

    raiseRequisition($user, $item, 100);

    StockPurchase::create([
        'stock_item_id' => $item->id,
        'supplier_name' => 'Star Traders',
        'purchase_date' => '2026-08-12',
        'challan_no' => 'CH-3',
        'qty' => 30,
        'unit_price' => 4,
    ]);

    // The card is for holders of store.requirement_issue.view, and carries its
    // own period under sum[...]. The Purchase View figures are unchanged.
    $user->givePermissionTo(Permission::findOrCreate(\App\Services\RequirementVsIssueService::PERMISSION, 'web'));

    $this->actingAs($user)
        ->get(route('store.stock.requisitions.index', ['sum' => ['from' => '2026-08', 'to' => '2026-08']]))
        ->assertOk()
        ->assertSee('Stock Summary')
        ->assertSee('To Purchase')
        // 100 requested, 30 received, 70 outstanding.
        ->assertSee('70');
});
