<?php

use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Models\StockItem;
use App\Models\StockPurchase;
use App\Models\User;
use App\Services\RequisitionFulfilmentService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The Store's review of a department request, free-text items, and Current
 * Stock on the Stock Summary widget.
 *
 * What matters here is that NOTHING parallel was invented:
 *
 *   - Received / Approve move the requisition along the statuses the flow
 *     already had (store_reviewed, approved) and stamp the columns that were
 *     already on the table (store_ack_*, approved_*).
 *   - The two permissions are the ones PurchaseRequisitionPermissionSeeder
 *     already defines, including its deliberate rule that the store role may
 *     NOT approve a spend it raised.
 *   - "Sent to Store" is derived from created_by and roles, with no new column.
 *
 * In-memory sqlite. No real record touched.
 */

// Stage engine: every approval stage has a person, as Admin would set it.
beforeEach(fn () => $this->approvers = requisitionApprovers());

function reviewDepartmentUser(): User
{
    Role::findOrCreate('merchant', 'web');

    $role = Role::findOrCreate('merchant-requisition', 'web');

    foreach (['store.requisition.view', 'store.requisition.create'] as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return User::factory()->create()->assignRole('merchant', 'merchant-requisition');
}

/** A Store user: may mark Received, but deliberately may NOT approve. */
function reviewStoreUser(): User
{
    $role = Role::findOrCreate('store', 'web');
    $role->givePermissionTo(Permission::findOrCreate('store.requisition.review', 'web'));

    return User::factory()->create()->assignRole('store');
}

/** Admin / Management: the ones the seeder trusts with the approval. */
function reviewApprover(): User
{
    $role = Role::findOrCreate('management', 'web');

    foreach (['store.requisition.review', 'store.requisition.approve'] as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return User::factory()->create()->assignRole('management');
}

function submittedRequisition(User $by, ?StockItem $item = null, float $qty = 10): PurchaseRequisition
{
    $requisition = PurchaseRequisition::create([
        'requisition_no' => 'REQ-'.$by->id.'-'.uniqid(),
        'requisition_date' => '2026-08-10',
        'mode' => PurchaseRequisition::MODE_MULTI,
        'status' => PurchaseRequisition::STATUS_SUBMITTED,
        'submitted_at' => now(),
        'created_by' => $by->id,
    ]);

    PurchaseRequisitionItem::create([
        'purchase_requisition_id' => $requisition->id,
        'stock_item_id' => $item?->id,
        'custom_item_name' => $item ? null : 'Hand Sanitiser 5L',
        'sort_order' => 1,
        'qty_requested' => $qty,
    ]);

    return $requisition;
}

// --- PART 1: the Store's review queue ----------------------------------------

it('lists only what the departments sent, not the Store\'s own requisitions', function () {
    $item = StockItem::create(['name' => 'Machine Oil', 'uom' => 'Ltr', 'is_active' => true]);

    $fromDepartment = submittedRequisition(reviewDepartmentUser(), $item);
    $storeOwn = submittedRequisition(reviewStoreUser(), $item);

    $this->actingAs(reviewStoreUser())
        ->get(route('store.stock.requisitions.requested', ['month' => '']))
        ->assertOk()
        ->assertSee($fromDepartment->requisition_no)
        ->assertDontSee($storeOwn->requisition_no);
});

it('keeps the review queue away from a department user', function () {
    $this->actingAs(reviewDepartmentUser())
        ->get(route('store.stock.requisitions.requested', ['month' => '']))
        ->assertForbidden();
});

it('marks a requisition received, stamping the columns that already existed', function () {
    $item = StockItem::create(['name' => 'Machine Oil', 'uom' => 'Ltr', 'is_active' => true]);
    $requisition = submittedRequisition(reviewDepartmentUser(), $item);
    $store = reviewStoreUser();

    $this->actingAs($store)
        ->post(route('store.stock.requisitions.received', $requisition))
        ->assertRedirect();

    $requisition->refresh();

    expect($requisition->status)->toBe(PurchaseRequisition::STATUS_STORE_REVIEWED)
        ->and($requisition->store_ack_by)->toBe($store->id)
        ->and($requisition->store_ack_at)->not->toBeNull();
});

it('lets admin or management approve, but not the Store that raised it', function () {
    $item = StockItem::create(['name' => 'Machine Oil', 'uom' => 'Ltr', 'is_active' => true]);
    $requisition = submittedRequisition(reviewDepartmentUser(), $item);
    $requisition->update(['status' => PurchaseRequisition::STATUS_STORE_REVIEWED]);

    // The seeder withholds store.requisition.approve from the store role on
    // purpose: whoever raises a requisition must not approve the spend.
    $this->actingAs(reviewStoreUser())
        ->post(route('store.stock.requisitions.approve', $requisition))
        ->assertForbidden();

    $approver = reviewApprover();

    $this->actingAs($approver)
        ->post(route('store.stock.requisitions.approve', $requisition))
        ->assertRedirect();

    $requisition->refresh();

    expect($requisition->status)->toBe(PurchaseRequisition::STATUS_APPROVED)
        ->and($requisition->approved_by)->toBe($approver->id)
        ->and($requisition->approved_at)->not->toBeNull();
});

it('refuses an action taken at the wrong stage', function () {
    $item = StockItem::create(['name' => 'Machine Oil', 'uom' => 'Ltr', 'is_active' => true]);
    $requisition = submittedRequisition(reviewDepartmentUser(), $item);

    // Not received yet, so there is nothing to approve.
    $this->actingAs(reviewApprover())
        ->post(route('store.stock.requisitions.approve', $requisition))
        ->assertStatus(422);

    $requisition->update(['status' => PurchaseRequisition::STATUS_STORE_REVIEWED]);

    // Already received, so it cannot be received again.
    $this->actingAs(reviewStoreUser())
        ->post(route('store.stock.requisitions.received', $requisition))
        ->assertStatus(422);
});

it('shows the department its own progress in words it uses', function () {
    $item = StockItem::create(['name' => 'Machine Oil', 'uom' => 'Ltr', 'is_active' => true]);
    $department = reviewDepartmentUser();
    $requisition = submittedRequisition($department, $item);

    $html = $this->actingAs($department)
        ->get(route('store.stock.requisitions.show', $requisition))
        ->assertOk()
        ->getContent();

    // "Sent to Store", not the internal "Submitted"...
    expect($html)->toContain('Sent to Store');

    // ...and no action buttons: acting is the Store's job.
    expect($html)->not->toContain(route('store.stock.requisitions.received', $requisition));

    $requisition->update(['status' => PurchaseRequisition::STATUS_STORE_REVIEWED]);

    expect($this->actingAs($department)
        ->get(route('store.stock.requisitions.show', $requisition))
        ->getContent())->toContain('Received by Store');
});

// --- PART 2: an item that is not in the Item Master ---------------------------

it('refuses a typed item and creates no stock_items row', function () {
    $department = reviewDepartmentUser();
    $itemsBefore = StockItem::count();

    $this->actingAs($department)->post(route('store.stock.requisitions.store'), [
        'action' => 'submit',
        'mode' => PurchaseRequisition::MODE_MULTI,
        'requisition_date' => '2026-08-10',
        'items' => [
            ['stock_item_id' => '', 'custom_item_name' => 'Hand Sanitiser 5L', 'qty_requested' => 12],
        ],
    ])->assertSessionHasErrors('items.0.stock_item_id');

    expect(PurchaseRequisitionItem::count())->toBe(0)
        ->and(StockItem::count())->toBe($itemsBefore);
});

it('refuses a line that names neither a master item nor a typed one', function () {
    $this->actingAs(reviewDepartmentUser())
        ->post(route('store.stock.requisitions.store'), [
            'action' => 'submit',
            'mode' => PurchaseRequisition::MODE_MULTI,
            'requisition_date' => '2026-08-10',
            'items' => [['stock_item_id' => '', 'custom_item_name' => '', 'qty_requested' => 5]],
        ])
        ->assertSessionHasErrors('items.0.stock_item_id');
});

it('names the typed item on the requisition screen and flags it for Store', function () {
    $department = reviewDepartmentUser();
    $requisition = submittedRequisition($department);

    $this->actingAs($department)
        ->get(route('store.stock.requisitions.show', $requisition))
        ->assertOk()
        ->assertSee('Hand Sanitiser 5L')
        ->assertSee(PurchaseRequisitionItem::NOT_IN_MASTER_NOTE);
});

// --- PART 3: Current Stock on the Stock Summary -------------------------------

it('adds current stock to the summary from the Stock Report source', function () {
    $item = StockItem::create([
        'name' => 'Needle DBx1 90/14',
        'uom' => 'Pcs',
        'is_active' => true,
        'opening_qty' => 40,
        'opening_as_on' => '2026-08-01',
    ]);

    submittedRequisition(reviewStoreUser(), $item, 100);

    StockPurchase::create([
        'stock_item_id' => $item->id,
        'supplier_name' => 'Star Traders',
        'purchase_date' => '2026-08-12',
        'challan_no' => 'CH-9',
        'qty' => 30,
        'unit_price' => 4,
    ]);

    $summary = app(RequisitionFulfilmentService::class)
        ->summary(reviewStoreUser(), ['month' => '2026-08']);

    $row = $summary['rows']->firstWhere('item_id', $item->id);

    // 40 opening + 30 received, the figure the Stock Report prints.
    expect($row['stock_in_hand'])->toBe(70.0)
        ->and($row['requested'])->toBe(100.0)
        ->and($row['purchased'])->toBe(30.0)
        ->and($row['pending'])->toBe(70.0);
});

it('keeps a typed item on the summary with no stock or purchased figure', function () {
    submittedRequisition(reviewDepartmentUser(), null, 12);

    $summary = app(RequisitionFulfilmentService::class)
        ->summary(reviewStoreUser(), ['month' => '2026-08']);

    $row = $summary['rows']->firstWhere('item_name', 'Hand Sanitiser 5L');

    // Still demand somebody has to answer, and the row that is most at risk of
    // being forgotten — so it must not be dropped by the stock_items join.
    expect($row)->not->toBeNull()
        ->and($row['in_master'])->toBeFalse()
        ->and($row['stock_in_hand'])->toBeNull()
        ->and($row['purchased'])->toBe(0.0)
        ->and($row['pending'])->toBe(12.0)
        ->and($summary['totals']['not_in_master'])->toBe(1);
});

it('keeps the Stock Summary off a department dashboard unless the user holds its permission', function () {
    $item = StockItem::create(['name' => 'Bal Pen', 'uom' => 'pcs', 'is_active' => true]);

    $department = reviewDepartmentUser();

    submittedRequisition($department, $item, 20);
    submittedRequisition(reviewStoreUser(), $item, 500);

    // A requisition user alone no longer gets the card.
    $this->actingAs($department)
        ->get(route('merchant.dashboard'))
        ->assertOk()
        ->assertDontSee('Stock Summary');

    // Granted by Admin, they see it — company-wide, like the Store.
    $department->givePermissionTo(Permission::findOrCreate(\App\Services\RequirementVsIssueService::PERMISSION, 'web'));
    $this->actingAs($department->fresh())
        ->get(route('merchant.dashboard'))
        ->assertOk()
        ->assertSee('Stock Summary')
        ->assertSee('Open Stock Summary');
});

it('leaves a department dashboard untouched without the requisition permission', function () {
    Role::findOrCreate('merchant', 'web');
    $user = User::factory()->create()->assignRole('merchant');

    $this->actingAs($user)
        ->get(route('merchant.dashboard'))
        ->assertOk()
        ->assertDontSee('Stock Summary');
});
