<?php

use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Models\RequisitionDepartmentHead;
use App\Models\RequisitionSignatureBlock;
use App\Models\StockItem;
use App\Models\User;
use App\Services\RequisitionSignatureResolver;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The Department Head step of a department requisition, and the signature
 * boxes that come from Admin > Settings instead of the views.
 *
 *   - A department user's submission waits on the configured head of THEIR
 *     department before it reaches the Store's queue.
 *   - The head of one department cannot see, let alone check, another's.
 *   - A returned sheet is the requester's to amend and resubmit.
 *   - PDF, Excel and screen all print whatever boxes are configured.
 *
 * In-memory sqlite. No real record touched.
 */

function deptUser(string $department = 'merchant'): User
{
    Role::findOrCreate($department, 'web');
    $role = Role::findOrCreate('dept-requisition', 'web');

    foreach (['store.requisition.view', 'store.requisition.create'] as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return User::factory()->create()->assignRole($department, 'dept-requisition');
}

function deptHead(string $department = 'merchant'): User
{
    $head = deptUser($department);
    RequisitionDepartmentHead::assign($department, $head);

    return $head;
}

function storeReviewer(): User
{
    $role = Role::findOrCreate('store', 'web');
    $role->givePermissionTo(Permission::findOrCreate('store.requisition.review', 'web'));

    return User::factory()->create()->assignRole('store');
}

function seedSignatureBlocks(): void
{
    RequisitionSignatureBlock::query()->delete();

    RequisitionSignatureBlock::create(['level' => 'department', 'sequence' => 1, 'label' => 'Prepared by', 'assignee_type' => 'requester', 'stamp_source' => 'prepared']);
    RequisitionSignatureBlock::create(['level' => 'department', 'sequence' => 2, 'label' => 'Checked by', 'title' => 'Department Head', 'assignee_type' => 'department_head', 'stamp_source' => 'department_head']);
    // Stage engine: an approval stage names one person — the Store
    // department head here, a specific user on the final sheet below.
    RequisitionSignatureBlock::create(['level' => 'department', 'sequence' => 3, 'label' => 'Checked by', 'title' => 'Store Head', 'assignee_type' => 'store_head', 'stamp_source' => 'store_head']);

    RequisitionSignatureBlock::create(['level' => 'final', 'sequence' => 1, 'label' => 'Prepared by', 'title' => 'Store', 'assignee_type' => 'role', 'role_name' => 'store', 'stamp_source' => 'prepared']);
    RequisitionSignatureBlock::create(['level' => 'final', 'sequence' => 2, 'label' => 'Audit by', 'title' => 'HR Head', 'assignee_type' => 'user']);
    RequisitionSignatureBlock::create(['level' => 'final', 'sequence' => 3, 'label' => 'Approved by', 'title' => 'Operation Head', 'assignee_type' => 'user', 'stamp_source' => 'approved']);
}

function submitAs(User $user, array $overrides = [])
{
    $item = StockItem::create(['name' => 'Machine Oil', 'uom' => 'Ltr', 'is_active' => true]);

    $response = test()->actingAs($user)->post(route('store.stock.requisitions.store'), $overrides + [
        'action' => 'submit',
        'mode' => PurchaseRequisition::MODE_MULTI,
        'requisition_date' => '2026-09-21',
        'items' => [['stock_item_id' => $item->id, 'qty_requested' => 5]],
    ]);

    // The success flash names the requisition; flush it so a later
    // assertDontSee on another user's screen reads that screen, not the flash.
    test()->flushSession();

    return $response;
}

beforeEach(function () {
    seedSignatureBlocks();
    $this->approvers = requisitionApprovers();
    RequisitionSignatureBlock::query()->forLevel('final')->where('title', 'HR Head')->update(['user_id' => $this->approvers['hr']->id]);
    RequisitionSignatureBlock::query()->forLevel('final')->where('title', 'Operation Head')->update(['user_id' => $this->approvers['operation']->id]);
});

// --- Routing ---------------------------------------------------------------

it('sends a department submission to its department head, not to the Store', function () {
    $head = deptHead('merchant');
    $requester = deptUser('merchant');

    submitAs($requester)->assertRedirect();

    $requisition = PurchaseRequisition::query()->latest('id')->first();

    expect($requisition->level)->toBe(PurchaseRequisition::LEVEL_DEPARTMENT)
        ->and($requisition->department_role)->toBe('merchandising')
        ->and($requisition->department_review_status)->toBe(PurchaseRequisition::DEPT_REVIEW_PENDING)
        ->and($requisition->progressLabel())->toBe('With Department Head');

    // Not on the Store's queue yet.
    $this->actingAs(storeReviewer())
        ->get(route('store.stock.requisitions.requested'))
        ->assertOk()
        ->assertDontSee($requisition->requisition_no);

    // On the head's queue.
    $this->actingAs($head)
        ->get(route('store.stock.requisitions.department-review'))
        ->assertOk()
        ->assertSee($requisition->requisition_no);
});

it('goes straight to the Store when the department has no head configured', function () {
    submitAs(deptUser('commercial'))->assertRedirect();

    $requisition = PurchaseRequisition::query()->latest('id')->first();

    expect($requisition->department_review_status)->toBeNull()
        ->and($requisition->awaitingStoreReceipt())->toBeTrue();

    $this->actingAs(storeReviewer())
        ->get(route('store.stock.requisitions.requested'))
        ->assertSee($requisition->requisition_no);
});

it('raises a final-level requisition for a Store user, with no department step', function () {
    submitAs(storeReviewer())->assertRedirect();

    $requisition = PurchaseRequisition::query()->latest('id')->first();

    expect($requisition->level)->toBe(PurchaseRequisition::LEVEL_FINAL)
        ->and($requisition->department_review_status)->toBeNull();
});

// --- The head's decision -----------------------------------------------------

it('lets the department head approve, which hands the sheet to the Store', function () {
    $head = deptHead('merchant');
    submitAs(deptUser('merchant'));
    $requisition = PurchaseRequisition::query()->latest('id')->first();

    $this->actingAs($head)
        ->post(route('store.stock.requisitions.department-approve', $requisition))
        ->assertRedirect();

    $requisition->refresh();

    expect($requisition->department_review_status)->toBe(PurchaseRequisition::DEPT_REVIEW_APPROVED)
        ->and($requisition->department_checked_by)->toBe($head->id)
        ->and($requisition->status)->toBe(PurchaseRequisition::STATUS_SUBMITTED)
        ->and($requisition->awaitingStoreReceipt())->toBeTrue();

    $this->actingAs(storeReviewer())
        ->get(route('store.stock.requisitions.requested'))
        ->assertSee($requisition->requisition_no);
});

it('returns a sheet with a reason, and the requester can amend and resubmit it', function () {
    $head = deptHead('merchant');
    $requester = deptUser('merchant');
    submitAs($requester);
    $requisition = PurchaseRequisition::query()->latest('id')->first();

    $this->actingAs($head)
        ->post(route('store.stock.requisitions.department-reject', $requisition), ['note' => 'Quantity too high'])
        ->assertRedirect();

    $requisition->refresh();

    expect($requisition->rejectedByDepartmentHead())->toBeTrue()
        ->and($requisition->isEditable())->toBeTrue()
        ->and($requisition->progressLabel())->toBe('Returned by Department Head');

    $this->actingAs($requester)
        ->get(route('store.stock.requisitions.show', $requisition))
        ->assertOk()
        ->assertSee('Quantity too high');

    // Resubmitting starts the head's step afresh.
    $item = $requisition->items()->first();
    $this->actingAs($requester)
        ->put(route('store.stock.requisitions.update', $requisition), [
            'action' => 'submit',
            'mode' => PurchaseRequisition::MODE_MULTI,
            'requisition_date' => '2026-09-21',
            'items' => [['stock_item_id' => $item->stock_item_id, 'qty_requested' => 2]],
        ])
        ->assertRedirect();

    $requisition->refresh();

    expect($requisition->department_review_status)->toBe(PurchaseRequisition::DEPT_REVIEW_PENDING)
        ->and($requisition->department_review_note)->toBeNull();
});

it('refuses to return a sheet without a reason', function () {
    $head = deptHead('merchant');
    submitAs(deptUser('merchant'));
    $requisition = PurchaseRequisition::query()->latest('id')->first();

    $this->actingAs($head)
        ->from(route('store.stock.requisitions.department-review'))
        ->post(route('store.stock.requisitions.department-reject', $requisition), ['note' => ''])
        ->assertSessionHasErrors('note');
});

// --- Confinement -------------------------------------------------------------

it('keeps another department\'s head out of the sheet entirely', function () {
    deptHead('merchant');
    $commercialHead = deptHead('commercial');
    submitAs(deptUser('merchant'));
    $requisition = PurchaseRequisition::query()->latest('id')->first();

    $this->actingAs($commercialHead)
        ->get(route('store.stock.requisitions.department-review'))
        ->assertOk()
        ->assertDontSee($requisition->requisition_no);

    // 404, not 403: to them the number does not exist.
    $this->actingAs($commercialHead)
        ->get(route('store.stock.requisitions.show', $requisition))
        ->assertNotFound();

    $this->actingAs($commercialHead)
        ->post(route('store.stock.requisitions.department-approve', $requisition))
        ->assertNotFound();
});

it('keeps a plain department colleague from acting as head', function () {
    deptHead('merchant');
    $colleague = deptUser('merchant');
    submitAs(deptUser('merchant'));
    $requisition = PurchaseRequisition::query()->latest('id')->first();

    // Not the requester, not the head: cannot even open it.
    $this->actingAs($colleague)
        ->get(route('store.stock.requisitions.show', $requisition))
        ->assertNotFound();

    $this->actingAs($colleague)
        ->get(route('store.stock.requisitions.department-review'))
        ->assertForbidden();
});

it('hides a colleague\'s DRAFT from the department head until it is submitted', function () {
    $head = deptHead('merchant');
    $requester = deptUser('merchant');

    $item = StockItem::create(['name' => 'Machine Oil', 'uom' => 'Ltr', 'is_active' => true]);
    submitAs($requester, ['action' => 'draft']);
    $draft = PurchaseRequisition::query()->latest('id')->first();

    expect($draft->status)->toBe(PurchaseRequisition::STATUS_DRAFT)
        ->and($draft->department_review_status)->toBeNull();

    // Not on the list, not on the queue, not openable: a draft is still
    // being typed and is nobody's business but the requester's.
    $this->actingAs($head)
        ->get(route('store.stock.requisitions.index'))
        ->assertOk()
        ->assertDontSee($draft->requisition_no);

    $this->actingAs($head)
        ->get(route('store.stock.requisitions.department-review'))
        ->assertDontSee($draft->requisition_no);

    $this->actingAs($head)
        ->get(route('store.stock.requisitions.show', $draft))
        ->assertNotFound();

    // The requester still sees their own draft.
    $this->actingAs($requester)
        ->get(route('store.stock.requisitions.show', $draft))
        ->assertOk();
});

it('lets the head see their department\'s submitted sheets on the main list, but not another department\'s', function () {
    $head = deptHead('merchant');
    submitAs(deptUser('merchant'));
    $own = PurchaseRequisition::query()->latest('id')->first();
    submitAs(deptUser('commercial'));
    $other = PurchaseRequisition::query()->latest('id')->first();

    $this->actingAs($head)
        ->get(route('store.stock.requisitions.index'))
        ->assertOk()
        ->assertSee($own->requisition_no)
        ->assertDontSee($other->requisition_no);
});

it('lets Management stand in for a department head on an old-flow sheet only, and Admin too', function () {
    deptHead('merchant');
    submitAs(deptUser('merchant'));
    $requisition = PurchaseRequisition::query()->latest('id')->first();

    $management = User::factory()->create()->assignRole(Role::findOrCreate('management', 'web'));

    $this->actingAs($management)
        ->get(route('store.stock.requisitions.department-review'))
        ->assertOk()
        ->assertSee($requisition->requisition_no);

    // Stage engine: only the head named on the plan approves — no stand-ins.
    $this->actingAs($management)
        ->post(route('store.stock.requisitions.department-approve', $requisition))
        ->assertForbidden();
    expect($requisition->fresh()->department_review_status)->toBe(PurchaseRequisition::DEPT_REVIEW_PENDING);

    // An old-flow sheet (from before the stage engine) keeps the old rule.
    $requisition->forceFill(['workflow_version' => null, 'current_stage' => null])->save();
    $this->actingAs($management)
        ->post(route('store.stock.requisitions.department-approve', $requisition))
        ->assertRedirect();

    expect($requisition->fresh()->department_review_status)->toBe(PurchaseRequisition::DEPT_REVIEW_APPROVED)
        ->and($requisition->fresh()->department_checked_by)->toBe($management->id);

    // Admin: the queue opens even with nothing in it.
    $admin = User::factory()->create()->assignRole(Role::findOrCreate('admin', 'web'));
    $this->actingAs($admin)
        ->get(route('store.stock.requisitions.department-review'))
        ->assertOk();
});

it('leaves a pre-migration requisition (NULL department review) on the Store queue as before', function () {
    $requester = deptUser('merchant');
    deptHead('merchant');

    // Shaped like a row the backfill produced: department level, but raised
    // before the head step existed, so no review status at all.
    $legacy = PurchaseRequisition::create([
        'requisition_no' => 'LEGACY-'.uniqid(),
        'requisition_date' => '2026-08-01',
        'mode' => PurchaseRequisition::MODE_MULTI,
        'level' => PurchaseRequisition::LEVEL_DEPARTMENT,
        'department_role' => 'merchant',
        'status' => PurchaseRequisition::STATUS_SUBMITTED,
        'submitted_at' => now(),
        'created_by' => $requester->id,
    ]);

    expect($legacy->heldByDepartmentHead())->toBeFalse()
        ->and($legacy->awaitingStoreReceipt())->toBeTrue()
        ->and($legacy->progressLabel())->toBe('Sent to Store');

    $store = storeReviewer();
    $this->actingAs($store)
        ->get(route('store.stock.requisitions.requested'))
        ->assertSee($legacy->requisition_no);

    $this->actingAs($store)
        ->post(route('store.stock.requisitions.received', $legacy))
        ->assertRedirect();

    expect($legacy->fresh()->status)->toBe(PurchaseRequisition::STATUS_STORE_REVIEWED);
});

it('does not let the Store receive a sheet still with its department head', function () {
    deptHead('merchant');
    submitAs(deptUser('merchant'));
    $requisition = PurchaseRequisition::query()->latest('id')->first();

    $this->actingAs(storeReviewer())
        ->post(route('store.stock.requisitions.received', $requisition))
        ->assertStatus(422);
});

// --- Dynamic signature boxes -------------------------------------------------

it('resolves the configured boxes with the names of whoever actually acted', function () {
    $head = deptHead('merchant');
    $requester = deptUser('merchant');
    submitAs($requester);
    $requisition = PurchaseRequisition::query()->latest('id')->first();

    $this->actingAs($head)->post(route('store.stock.requisitions.department-approve', $requisition));

    $boxes = app(RequisitionSignatureResolver::class)->forRequisition($requisition->fresh());

    expect($boxes)->toHaveCount(3)
        ->and($boxes[0]['label'])->toBe('Prepared by')
        ->and($boxes[0]['name'])->toBe($requester->name)
        ->and($boxes[0]['signed_at'])->not->toBeNull()
        ->and($boxes[1]['name'])->toBe($head->name)
        ->and($boxes[1]['title'])->toBe('Department Head')
        ->and($boxes[1]['signed_at'])->not->toBeNull()
        // Stage engine: the box names the person the stage waits for — the
        // Store department head — unsigned until they approve.
        ->and($boxes[2]['name'])->toBe($this->approvers['store_head']->name)
        ->and($boxes[2]['title'])->toBe('Store Head')
        ->and($boxes[2]['signed_at'])->toBeNull()
        ->and($boxes[2]['stamped'])->toBeTrue();
});

it('prints the final-level boxes, in order, on the Store\'s own sheet', function () {
    $auditor = User::factory()->create(['name' => 'Karim HR']);
    RequisitionSignatureBlock::query()->where('level', 'final')->where('sequence', 2)->update(['user_id' => $auditor->id]);

    submitAs(storeReviewer());
    $requisition = PurchaseRequisition::query()->latest('id')->first();

    $boxes = app(RequisitionSignatureResolver::class)->forRequisition($requisition);

    expect(array_column($boxes, 'label'))->toBe(['Prepared by', 'Audit by', 'Approved by'])
        ->and($boxes[1]['name'])->toBe('Karim HR')
        ->and($boxes[1]['title'])->toBe('HR Head')
        // No longer "signed on paper only": a real stage, Pending until approved.
        ->and($boxes[1]['stamped'])->toBeTrue()
        ->and($boxes[1]['signed_at'])->toBeNull();

    // The screen shows the same boxes.
    $this->actingAs(storeReviewer())
        ->get(route('store.stock.requisitions.show', $requisition))
        ->assertOk()
        ->assertSee('Audit by')
        ->assertSee('Karim HR')
        ->assertSee('Operation Head');
});

it('renders the document view with the configured boxes for PDF and Excel', function () {
    submitAs(storeReviewer());
    $requisition = PurchaseRequisition::query()->latest('id')->with('items.stockItem')->first();
    $boxes = app(RequisitionSignatureResolver::class)->forRequisition($requisition);

    $pdf = view('store.stock.requisitions.document-pdf', [
        'requisition' => $requisition,
        'rows' => app(\App\Services\MonthlyRequisitionReportService::class)->requisitionRows($requisition),
        'title' => 'Purchase Requisition',
        'signatures' => $boxes,
    ])->render();

    $excel = view('store.stock.requisitions.document-pdf', [
        'requisition' => $requisition,
        'rows' => app(\App\Services\MonthlyRequisitionReportService::class)->requisitionRows($requisition),
        'title' => 'Purchase Requisition',
        'signatures' => $boxes,
        'forExcel' => true,
    ])->render();

    foreach (['Audit by', 'HR Head', 'Operation Head'] as $text) {
        expect($pdf)->toContain($text)->and($excel)->toContain($text);
    }

    // The old hardcoded footer names are gone from both. (The item table's
    // "Acknowledgement" column groups are part of the sheet layout and stay.)
    expect($pdf)->not->toContain('Store In-charge')
        ->and($excel)->not->toContain('Store In-charge');
});

// --- Admin settings ----------------------------------------------------------

it('lets the admin replace the boxes', function () {
    $admin = User::factory()->create()->assignRole(Role::findOrCreate('admin', 'web'));
    $head = User::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.requisition-signatures.update'), [
            'blocks' => [
                'department' => [
                    ['label' => 'Requested by', 'assignee_type' => 'requester', 'stamp_source' => 'prepared'],
                    ['label' => 'Verified by', 'title' => 'Head', 'assignee_type' => 'department_head', 'stamp_source' => 'department_head'],
                ],
                'final' => [
                    ['label' => 'Prepared by', 'assignee_type' => 'role', 'role_name' => 'store', 'stamp_source' => 'prepared'],
                    ['label' => 'Approved by', 'assignee_type' => 'user', 'user_id' => $head->id, 'stamp_source' => ''],
                ],
            ],
        ])
        ->assertRedirect(route('admin.requisition-signatures.edit'));

    expect(RequisitionSignatureBlock::query()->forLevel('department')->pluck('label')->all())->toBe(['Requested by', 'Verified by'])
        ->and(RequisitionSignatureBlock::query()->forLevel('final')->pluck('label')->all())->toBe(['Prepared by', 'Approved by'])
        ->and(RequisitionSignatureBlock::query()->forLevel('final')->get()[1]->stamp_source)->toBeNull();
});

it('refuses a level with no boxes at all', function () {
    $admin = User::factory()->create()->assignRole(Role::findOrCreate('admin', 'web'));

    $this->actingAs($admin)
        ->from(route('admin.requisition-signatures.edit'))
        ->post(route('admin.requisition-signatures.update'), [
            'blocks' => [
                'department' => [],
                'final' => [['label' => 'Prepared by', 'assignee_type' => 'role', 'role_name' => 'store']],
            ],
        ])
        ->assertSessionHasErrors('blocks');

    expect(RequisitionSignatureBlock::query()->forLevel('department')->count())->toBe(3);
});

it('renders the settings screen for the admin', function () {
    $admin = User::factory()->create()->assignRole(Role::findOrCreate('admin', 'web'));

    $this->actingAs($admin)
        ->get(route('admin.requisition-signatures.edit'))
        ->assertOk()
        // Heads moved to the Departments screen; this one points there.
        ->assertSee('Manage Departments')
        ->assertSee('Final Purchase Requisition');
});
