<?php

use App\Models\IndentSection;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionAction;
use App\Models\PurchaseRequisitionItem;
use App\Models\StockItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Step 1, Part B — the requisition audit trail and the delete rule.
 *
 *   - Every workflow action writes one history row, in the same transaction.
 *   - A return reason survives the resubmission; the round number goes up.
 *   - A Store correction records the old and new quantity per item.
 *   - A second click on the same action is refused, and records nothing.
 *   - Delete: a draft by its raiser or Admin; a submitted sheet by Admin
 *     only, with a reason. The history outlives the sheet and the user.
 *   - The backfill builds rows from the stamps and removes only its own.
 *
 * In-memory sqlite. No real record touched.
 */
// Stage engine: every approval stage has a person, as Admin would set it.
beforeEach(fn () => $this->approvers = requisitionApprovers());

function histUser(string $role, array $permissions = [], array $attributes = []): User
{
    $r = Role::findOrCreate($role, 'web');

    foreach (array_merge(['store.requisition.view'], $permissions) as $p) {
        $r->givePermissionTo(Permission::findOrCreate($p, 'web'));
    }

    return User::factory()->create($attributes)->assignRole($role);
}

function histItem(string $name = 'Machine Oil'): StockItem
{
    return StockItem::firstOrCreate(['name' => $name], ['uom' => 'Ltr', 'is_active' => true]);
}

function histPayload(string $action, array $lines): array
{
    return [
        'action' => $action,
        'mode' => PurchaseRequisition::MODE_MULTI,
        'requisition_date' => '2026-09-22',
        'items' => $lines,
    ];
}

function histSheet(User $by, string $status, array $attributes = []): PurchaseRequisition
{
    $sheet = PurchaseRequisition::create($attributes + [
        'requisition_no' => 'HIST-'.uniqid(),
        'requisition_date' => '2026-09-22',
        'mode' => PurchaseRequisition::MODE_MULTI,
        'level' => PurchaseRequisition::LEVEL_FINAL,
        'status' => $status,
        'submitted_at' => $status === PurchaseRequisition::STATUS_DRAFT ? null : now(),
        'created_by' => $by->id,
    ]);

    PurchaseRequisitionItem::create([
        'purchase_requisition_id' => $sheet->id,
        'stock_item_id' => histItem()->id,
        'sort_order' => 1,
        'qty_requested' => 5,
    ]);

    return $sheet;
}

/** @return list<string> the sheet's actions, oldest first */
function histActions(PurchaseRequisition $sheet): array
{
    return PurchaseRequisitionAction::where('purchase_requisition_id', $sheet->id)
        ->orderBy('id')->pluck('action')->all();
}

// --- Writing history ---------------------------------------------------------

it('records a draft as created and a submission as submitted, with the name at the time', function () {
    $store = histUser('store', ['store.requisition.create'], ['name' => 'Rahim Store']);

    $this->actingAs($store)->post(route('store.stock.requisitions.store'),
        histPayload('draft', [['stock_item_id' => histItem()->id, 'qty_requested' => 5]]));
    $this->actingAs($store)->post(route('store.stock.requisitions.store'),
        histPayload('submit', [['stock_item_id' => histItem()->id, 'qty_requested' => 5]]));

    [$draft, $submitted] = PurchaseRequisition::orderBy('id')->get()->all();

    expect(histActions($draft))->toBe(['created'])
        ->and(histActions($submitted))->toBe(['submitted'])
        ->and($submitted->actions->first()->user_name)->toBe('Rahim Store')
        ->and($submitted->actions->first()->stage)->toBe('raiser');
});

it('keeps a head\'s return reason after resubmission, and starts round 2', function () {
    $head = histUser('production', ['store.requisition.create']);
    $section = IndentSection::create(['name' => 'Cutting', 'department_key' => 'production', 'head_user_id' => $head->id, 'is_active' => true]);
    $raiser = histUser('production', ['store.requisition.create'], ['requisition_section_id' => $section->id]);

    $this->actingAs($raiser)->post(route('store.stock.requisitions.store'),
        histPayload('submit', [['stock_item_id' => histItem()->id, 'qty_requested' => 5]]));
    $sheet = PurchaseRequisition::firstOrFail();

    $this->actingAs($head)->post(route('store.stock.requisitions.department-reject', $sheet), ['note' => 'Quantity too high']);
    $this->actingAs($raiser)->put(route('store.stock.requisitions.update', $sheet),
        histPayload('submit', [['stock_item_id' => histItem()->id, 'qty_requested' => 3]]));
    $this->actingAs($head)->post(route('store.stock.requisitions.department-approve', $sheet));

    $rows = PurchaseRequisitionAction::where('purchase_requisition_id', $sheet->id)->orderBy('id')->get();

    expect($rows->pluck('action')->all())->toBe(['submitted', 'head_returned', 'resubmitted', 'head_approved'])
        ->and($rows[1]->comment)->toBe('Quantity too high')
        ->and($rows[1]->stage)->toBe('section_head')
        ->and($rows->pluck('cycle')->all())->toBe([1, 1, 2, 2])
        // The sheet's own note is cleared on resubmit; the history keeps it.
        ->and($sheet->fresh()->department_review_note)->toBeNull();
});

it('records Received and Approve with their optional comments', function () {
    $raiser = histUser('merchant');
    $store = histUser('store', ['store.requisition.review']);
    $admin = histUser('admin', ['store.requisition.approve']);
    $sheet = histSheet($raiser, PurchaseRequisition::STATUS_SUBMITTED);

    $this->actingAs($store)->post(route('store.stock.requisitions.received', $sheet), ['comment' => 'Checked stock first']);
    $this->actingAs($admin)->post(route('store.stock.requisitions.approve', $sheet));

    $rows = PurchaseRequisitionAction::where('purchase_requisition_id', $sheet->id)->orderBy('id')->get();

    expect($rows->pluck('action')->all())->toBe(['received', 'approved'])
        ->and($rows[0]->comment)->toBe('Checked stock first')
        ->and($rows[0]->from_status)->toBe('submitted')
        ->and($rows[0]->to_status)->toBe('store_reviewed')
        ->and($rows[1]->comment)->toBeNull()
        ->and($sheet->fresh()->status)->toBe(PurchaseRequisition::STATUS_APPROVED);
});

it('refuses a second click on Received and records it only once', function () {
    $store = histUser('store', ['store.requisition.review']);
    $sheet = histSheet(histUser('merchant'), PurchaseRequisition::STATUS_SUBMITTED);

    $this->actingAs($store)->post(route('store.stock.requisitions.received', $sheet))->assertRedirect();
    $this->actingAs($store)->post(route('store.stock.requisitions.received', $sheet))->assertStatus(422);

    expect(histActions($sheet))->toBe(['received']);
});

it('records a Store correction as old and new quantity per item', function () {
    $store = histUser('store', ['store.requisition.edit']);
    $sheet = histSheet(histUser('merchant'), PurchaseRequisition::STATUS_SUBMITTED);

    $this->actingAs($store)->put(route('store.stock.requisitions.update', $sheet), histPayload('submit', [
        ['stock_item_id' => histItem()->id, 'qty_requested' => 8],
        ['stock_item_id' => histItem('Sewing Thread')->id, 'qty_requested' => 2],
    ]))->assertRedirect();

    $row = PurchaseRequisitionAction::where('purchase_requisition_id', $sheet->id)->sole();

    expect($row->action)->toBe('corrected')
        ->and($row->meta['lines'])->toEqual([
            ['item' => 'Machine Oil', 'change' => 'changed', 'old_qty' => 5.0, 'new_qty' => 8.0],
            ['item' => 'Sewing Thread', 'change' => 'added', 'old_qty' => null, 'new_qty' => 2.0],
        ]);
});

it('shows the history on the requisition page, with the return reason', function () {
    $store = histUser('store');
    $sheet = histSheet($store, PurchaseRequisition::STATUS_SUBMITTED);
    PurchaseRequisitionAction::create([
        'purchase_requisition_id' => $sheet->id, 'requisition_no' => $sheet->requisition_no,
        'action' => 'head_returned', 'stage' => 'department_head', 'user_name' => 'Karim Head',
        'comment' => 'Split by section', 'cycle' => 1, 'created_at' => now(),
    ]);

    $this->actingAs($store)->get(route('store.stock.requisitions.show', $sheet))
        ->assertOk()
        ->assertSeeInOrder(['History', 'Returned by Head', 'Department Head', 'Karim Head', 'Split by section']);
});

// --- Delete ------------------------------------------------------------------

it('lets the raiser delete their own draft without a reason', function () {
    $raiser = histUser('merchant');
    $sheet = histSheet($raiser, PurchaseRequisition::STATUS_DRAFT);

    $this->actingAs($raiser)->delete(route('store.stock.requisitions.destroy', $sheet))->assertRedirect();

    expect(PurchaseRequisition::find($sheet->id))->toBeNull();
});

it('does not let someone else delete a draft, even with the delete permission', function () {
    $sheet = histSheet(histUser('merchant'), PurchaseRequisition::STATUS_DRAFT);
    $other = histUser('management', ['store.requisition.delete', 'store.delete']);

    $this->actingAs($other)->delete(route('store.stock.requisitions.destroy', $sheet))
        ->assertSessionHas('error');

    expect(PurchaseRequisition::find($sheet->id))->not->toBeNull();
});

it('lets only Admin delete a submitted requisition', function () {
    $raiser = histUser('merchant');
    $sheet = histSheet($raiser, PurchaseRequisition::STATUS_SUBMITTED);

    foreach ([$raiser, histUser('store', ['store.requisition.delete', 'store.delete']), histUser('management', ['store.delete'])] as $user) {
        $this->actingAs($user)->delete(route('store.stock.requisitions.destroy', $sheet), ['comment' => 'Duplicate'])
            ->assertSessionHas('error');
    }

    expect(PurchaseRequisition::find($sheet->id))->not->toBeNull();
});

it('lets Admin delete a submitted requisition, and keeps the history of a deleted draft', function () {
    $admin = histUser('admin', [], ['name' => 'System Admin']);

    // A submitted sheet: Admin may clear it (test/duplicate), with a reason.
    $submitted = histSheet(histUser('merchant'), PurchaseRequisition::STATUS_SUBMITTED);
    $this->actingAs($admin)->delete(route('store.stock.requisitions.destroy', $submitted), ['comment' => 'Duplicate'])
        ->assertRedirect();
    expect(PurchaseRequisition::find($submitted->id))->toBeNull();

    // A draft is still deleted — and its history outlives it.
    $draft = histSheet(histUser('merchant'), PurchaseRequisition::STATUS_DRAFT);
    $no = $draft->requisition_no;

    $this->actingAs($admin)->delete(route('store.stock.requisitions.destroy', $draft), ['comment' => 'Raised twice by mistake'])
        ->assertRedirect(route('store.stock.requisitions.index'));

    $row = PurchaseRequisitionAction::where('requisition_no', $no)->sole();

    expect(PurchaseRequisition::find($draft->id))->toBeNull()
        ->and($row->purchase_requisition_id)->toBeNull()
        ->and($row->action)->toBe('deleted')
        ->and($row->stage)->toBe('admin')
        ->and($row->from_status)->toBe('draft')
        ->and($row->to_status)->toBeNull()
        ->and($row->comment)->toBe('Raised twice by mistake')
        ->and($row->user_name)->toBe('System Admin')
        ->and($row->meta['lines'][0]['qty'])->toEqual(5);
});

it('keeps the actor\'s name after the user is removed', function () {
    $store = histUser('store', ['store.requisition.review'], ['name' => 'Former Staff']);
    $sheet = histSheet(histUser('merchant'), PurchaseRequisition::STATUS_SUBMITTED);
    $this->actingAs($store)->post(route('store.stock.requisitions.received', $sheet));

    DB::table('purchase_requisitions')->where('id', $sheet->id)->update(['store_ack_by' => null]);
    $store->delete();

    $row = PurchaseRequisitionAction::where('purchase_requisition_id', $sheet->id)->sole();

    expect($row->user_id)->toBeNull()
        ->and($row->actorName())->toBe('Former Staff');
});

// --- Backfill ----------------------------------------------------------------

it('backfills history from the stamps, and its down() removes only its own rows', function () {
    $raiser = User::factory()->create(['name' => 'Old Raiser']);
    $storeUser = User::factory()->create(['name' => 'Old Store']);
    $sheet = histSheet($raiser, PurchaseRequisition::STATUS_STORE_REVIEWED, [
        'level' => PurchaseRequisition::LEVEL_DEPARTMENT,
        'department_review_status' => PurchaseRequisition::DEPT_REVIEW_APPROVED,
        'review_level' => 'department',
        'department_checked_by' => $storeUser->id,
        'department_checked_at' => now()->subDays(3),
        'store_ack_by' => $storeUser->id,
        'store_ack_at' => now()->subDays(2),
    ]);
    $live = PurchaseRequisitionAction::create([
        'purchase_requisition_id' => null, 'requisition_no' => 'LIVE-1',
        'action' => 'deleted', 'cycle' => 1, 'created_at' => now(),
    ]);

    $migration = require database_path('migrations/2026_09_28_000002_backfill_purchase_requisition_actions.php');
    $migration->up();

    $rows = PurchaseRequisitionAction::where('purchase_requisition_id', $sheet->id)->orderBy('id')->get();

    expect($rows->pluck('action')->all())->toBe(['submitted', 'head_approved', 'received'])
        ->and($rows->every->isBackfilled())->toBeTrue()
        ->and($rows[0]->user_name)->toBe('Old Raiser')
        ->and($rows[2]->user_name)->toBe('Old Store');

    // Running it again adds nothing.
    $migration->up();
    expect(PurchaseRequisitionAction::where('purchase_requisition_id', $sheet->id)->count())->toBe(3);

    $migration->down();
    expect(PurchaseRequisitionAction::pluck('id')->all())->toBe([$live->id]);
});
