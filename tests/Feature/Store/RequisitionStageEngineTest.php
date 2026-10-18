<?php

use App\Models\Department;
use App\Models\IndentSection;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionAction;
use App\Models\PurchaseRequisitionStage;
use App\Models\RequisitionSignatureBlock;
use App\Models\StockItem;
use App\Models\User;
use App\Services\RequisitionSignatureResolver;
use App\Services\RequisitionWorkflow;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Step 3b — the requisition stage engine.
 *
 *   Final sheet:      Prepared → Store Head → HR Audit → Finance & Commercial → Operation Head
 *   Department sheet: Prepared → Section/Department Head → Received by Store (any Store user)
 *
 *   - The plan is saved per sheet at submit, with the approver resolved then.
 *   - Submit is refused when a stage has no approver, or the raiser holds one.
 *   - Only the planned person approves a stage: never the raiser, never
 *     Admin or Management standing in; one person on two stages clicks twice.
 *   - Stages write the same status and stamp columns as before.
 *   - Old sheets (workflow_version NULL) keep the old flow.
 *
 * In-memory sqlite. No real record touched.
 */
beforeEach(fn () => $this->approvers = requisitionApprovers());

function engineUser(string $role, array $permissions = [], array $attributes = []): User
{
    $user = User::factory()->create($attributes)->assignRole(Role::findOrCreate($role, 'web'));

    foreach (array_merge(['store.requisition.view'], $permissions) as $p) {
        $user->givePermissionTo(Permission::findOrCreate($p, 'web'));
    }

    return $user;
}

function engineSubmit(User $user)
{
    $item = StockItem::firstOrCreate(['name' => 'Machine Oil'], ['uom' => 'Ltr', 'is_active' => true]);

    return test()->actingAs($user)->post(route('store.stock.requisitions.store'), [
        'action' => 'submit',
        'mode' => PurchaseRequisition::MODE_MULTI,
        'requisition_date' => '2026-09-22',
        'items' => [['stock_item_id' => $item->id, 'qty_requested' => 5]],
    ]);
}

function engineApprove(User $user, PurchaseRequisition $sheet)
{
    return test()->actingAs($user)->post(route('store.stock.requisitions.stage-approve', $sheet));
}

function engineStages(PurchaseRequisition $sheet): array
{
    return $sheet->stages()->orderBy('sequence')->get()
        ->map(fn ($s) => $s->sequence.':'.$s->status.':'.($s->assignee_name ?? '-'))->all();
}

// --- The plan --------------------------------------------------------------------

it('plans a final sheet over the five boxes, with each approver resolved at submit', function () {
    $clerk = engineUser('store', ['store.requisition.create'], ['name' => 'Store Clerk']);

    engineSubmit($clerk)->assertRedirect();
    $sheet = PurchaseRequisition::latest('id')->firstOrFail();

    expect($sheet->workflow_version)->toBe(RequisitionWorkflow::VERSION)
        ->and($sheet->current_stage)->toBe(2)
        ->and(engineStages($sheet))->toBe([
            '1:approved:Store Clerk',
            '2:pending:Store Head Approver',
            '3:pending:HR Audit Approver',
            '4:pending:Finance Approver',
            '5:pending:Operation Approver',
        ]);
});

it('walks a final sheet stage by stage to Approved, writing the same columns as before', function () {
    $sheet = tap(engineSubmit(engineUser('store', ['store.requisition.create'])), fn ($r) => $r->assertRedirect())
        ? PurchaseRequisition::latest('id')->firstOrFail() : null;

    engineApprove($this->approvers['store_head'], $sheet)->assertRedirect();
    $sheet->refresh();
    expect($sheet->status)->toBe(PurchaseRequisition::STATUS_STORE_REVIEWED)
        ->and($sheet->store_ack_by)->toBe($this->approvers['store_head']->id)
        ->and($sheet->current_stage)->toBe(3);

    engineApprove($this->approvers['hr'], $sheet)->assertRedirect();
    engineApprove($this->approvers['finance'], $sheet)->assertRedirect();
    expect($sheet->fresh()->status)->toBe(PurchaseRequisition::STATUS_STORE_REVIEWED)
        ->and($sheet->fresh()->current_stage)->toBe(5);

    engineApprove($this->approvers['operation'], $sheet)->assertRedirect();
    $sheet->refresh();

    expect($sheet->status)->toBe(PurchaseRequisition::STATUS_APPROVED)
        ->and($sheet->approved_by)->toBe($this->approvers['operation']->id)
        ->and($sheet->current_stage)->toBeNull()
        ->and(PurchaseRequisitionAction::where('purchase_requisition_id', $sheet->id)->orderBy('id')->pluck('action')->all())
            ->toBe(['submitted', 'stage_approved', 'stage_approved', 'stage_approved', 'approved']);

    // Every box is signed by whoever approved that stage.
    $boxes = app(RequisitionSignatureResolver::class)->forRequisition($sheet);
    expect(array_column($boxes, 'name'))->toBe([
        $sheet->createdBy->name, 'Store Head Approver', 'HR Audit Approver', 'Finance Approver', 'Operation Approver',
    ])->and(collect($boxes)->every(fn ($b) => $b['signed_at'] !== null))->toBeTrue();
});

it('takes a department sheet through its head to the Store head, and stops there', function () {
    $sectionHead = engineUser('production');
    $section = IndentSection::create(['name' => 'Cutting', 'department_key' => 'production', 'head_user_id' => $sectionHead->id, 'is_active' => true]);
    $raiser = engineUser('production', ['store.requisition.create'], ['requisition_section_id' => $section->id]);

    engineSubmit($raiser)->assertRedirect();
    $sheet = PurchaseRequisition::latest('id')->firstOrFail();

    expect($sheet->level)->toBe(PurchaseRequisition::LEVEL_DEPARTMENT)
        ->and($sheet->current_stage)->toBe(2)
        ->and($sheet->awaitingDepartmentHead())->toBeTrue();

    engineApprove($sectionHead, $sheet)->assertRedirect();
    expect($sheet->fresh()->department_review_status)->toBe(PurchaseRequisition::DEPT_REVIEW_APPROVED)
        ->and($sheet->fresh()->awaitingStoreReceipt())->toBeTrue()
        ->and($sheet->fresh()->current_stage)->toBe(3);

    engineApprove($this->approvers['store_head'], $sheet)->assertRedirect(route('store.stock.requisitions.show', $sheet));
    // A department sheet ends with the Store; it then waits to be merged.
    expect($sheet->fresh()->status)->toBe(PurchaseRequisition::STATUS_STORE_REVIEWED)
        ->and($sheet->fresh()->current_stage)->toBeNull();
});

it('skips the head stage when no head is above the raiser', function () {
    $raiser = engineUser('production', ['store.requisition.create']);

    engineSubmit($raiser)->assertRedirect();
    $sheet = PurchaseRequisition::latest('id')->firstOrFail();

    expect(engineStages($sheet)[1])->toStartWith('2:skipped')
        ->and($sheet->current_stage)->toBe(3);
});

// --- Refusals at submit ---------------------------------------------------------

it('refuses to submit, and saves nothing, when a stage has no approver', function () {
    RequisitionSignatureBlock::query()->forLevel('final')->get()->values()[2]->update(['user_id' => null]);
    $clerk = engineUser('store', ['store.requisition.create']);

    engineSubmit($clerk)->assertSessionHasErrors('workflow');

    expect(PurchaseRequisition::count())->toBe(0)
        ->and(session('errors')->first('workflow'))->toContain('approver is not set. Ask Admin.');
});

it('refuses to submit a final sheet when there is no Store head', function () {
    Department::where('key', 'store')->update(['head_user_id' => null]);

    engineSubmit(engineUser('store', ['store.requisition.create']))->assertSessionHasErrors('workflow');
    expect(PurchaseRequisition::count())->toBe(0);
});

it('still takes a department sheet with no Store head — any Store user receives it', function () {
    Department::where('key', 'store')->update(['head_user_id' => null]);

    engineSubmit(engineUser('production', ['store.requisition.create']))->assertSessionHasNoErrors();
    expect(PurchaseRequisition::count())->toBe(1);
});

it('refuses a final sheet whose raiser holds one of its stages', function () {
    $hr = $this->approvers['hr'];
    $hr->assignRole(Role::findOrCreate('store', 'web'));
    $hr->givePermissionTo(Permission::findOrCreate('store.requisition.create', 'web'));

    engineSubmit($hr->fresh())->assertSessionHasErrors('workflow');

    expect(session('errors')->first('workflow'))->toContain('nobody may approve their own requisition')
        ->and(PurchaseRequisition::count())->toBe(0);
});

it('warns on the create screen before the form is filled', function () {
    RequisitionSignatureBlock::query()->forLevel('final')->get()->values()[2]->update(['user_id' => null]);

    $this->actingAs(engineUser('store', ['store.requisition.create']))
        ->get(route('store.stock.requisitions.create'))
        ->assertOk()
        ->assertSee('This requisition cannot be submitted yet.')
        ->assertSee('approver is not set. Ask Admin.');
});

// --- Who approves ------------------------------------------------------------------

it('lets only the planned person approve: not the raiser, Admin, Management or the Store', function () {
    $clerk = engineUser('store', ['store.requisition.create', 'store.requisition.review', 'store.requisition.approve']);
    engineSubmit($clerk)->assertRedirect();
    $sheet = PurchaseRequisition::latest('id')->firstOrFail();

    engineApprove($clerk, $sheet)->assertSessionHas('error');
    foreach ([engineUser('admin'), engineUser('management'), engineUser('store', ['store.requisition.review'])] as $other) {
        engineApprove($other, $sheet)->assertForbidden();
    }
    // The next stage's approver cannot act out of turn.
    engineApprove($this->approvers['hr'], $sheet)->assertForbidden();

    // The old flow's buttons are refused on a stage-engine sheet.
    $this->actingAs(engineUser('store', ['store.requisition.review']))
        ->post(route('store.stock.requisitions.received', $sheet))->assertStatus(422);

    expect($sheet->fresh()->current_stage)->toBe(2);
});

it('makes one person on two stages click each one, and refuses a double click', function () {
    RequisitionSignatureBlock::query()->forLevel('final')->get()->values()[3]->update(['user_id' => $this->approvers['hr']->id]);
    engineSubmit(engineUser('store', ['store.requisition.create']))->assertRedirect();
    $sheet = PurchaseRequisition::latest('id')->firstOrFail();

    engineApprove($this->approvers['store_head'], $sheet);
    engineApprove($this->approvers['hr'], $sheet)->assertRedirect();
    expect($sheet->fresh()->current_stage)->toBe(4);   // still waits for the same person

    engineApprove($this->approvers['hr'], $sheet)->assertRedirect();
    expect($sheet->fresh()->current_stage)->toBe(5);

    // Stage 5 belongs to someone else: a repeat click from HR is refused.
    engineApprove($this->approvers['hr'], $sheet)->assertForbidden();
});

it('lets a stage approver open a sheet they are on, and nobody else outside it', function () {
    engineSubmit(engineUser('store', ['store.requisition.create']))->assertRedirect();
    $sheet = PurchaseRequisition::latest('id')->firstOrFail();

    $this->actingAs($this->approvers['operation'])->get(route('store.stock.requisitions.show', $sheet))->assertOk();
    $this->actingAs(engineUser('production'))->get(route('store.stock.requisitions.show', $sheet))->assertNotFound();
});

it('keeps an old-flow sheet on the old buttons', function () {
    $raiser = engineUser('merchant');
    $old = PurchaseRequisition::create([
        'requisition_no' => 'OLD-1', 'requisition_date' => '2026-08-10', 'mode' => PurchaseRequisition::MODE_MULTI,
        'level' => PurchaseRequisition::LEVEL_FINAL, 'status' => PurchaseRequisition::STATUS_SUBMITTED,
        'submitted_at' => now(), 'created_by' => $raiser->id,
    ]);

    expect($old->usesStageEngine())->toBeFalse();

    $this->actingAs(engineUser('store', ['store.requisition.review']))
        ->post(route('store.stock.requisitions.received', $old))->assertRedirect();
    expect($old->fresh()->status)->toBe(PurchaseRequisition::STATUS_STORE_REVIEWED);

    // The stage-approve endpoint refuses it (as a Store user, who can see it).
    $this->approvers['store_head']->assignRole(Role::findOrCreate('store', 'web'));
    engineApprove($this->approvers['store_head']->fresh(), $old->fresh())->assertStatus(422);
});

it('does not let the Store correct a stage-engine sheet', function () {
    engineSubmit(engineUser('production', ['store.requisition.create']))->assertRedirect();
    $sheet = PurchaseRequisition::latest('id')->firstOrFail();

    $this->actingAs(engineUser('store', ['store.requisition.edit']))
        ->get(route('store.stock.requisitions.edit', $sheet))->assertForbidden();
});

// --- Settings ---------------------------------------------------------------------

it('refuses a role on an approval stage, and grants the named users requisition view', function () {
    $admin = engineUser('admin');
    $hr = User::factory()->create();
    $payload = fn (string $type, array $extra = []) => [
        'blocks' => [
            'department' => [['label' => 'Prepared by', 'assignee_type' => 'requester', 'stamp_source' => 'prepared']],
            'final' => [
                ['label' => 'Prepared by', 'assignee_type' => 'role', 'role_name' => 'store', 'stamp_source' => 'prepared'],
                ['label' => 'Audit by', 'assignee_type' => $type] + $extra,
            ],
        ],
    ];

    $this->actingAs($admin)->post(route('admin.requisition-signatures.update'), $payload('role', ['role_name' => 'store']))
        ->assertSessionHasErrors('blocks');

    $this->actingAs($admin)->post(route('admin.requisition-signatures.update'), $payload('user', ['user_id' => $hr->id]))
        ->assertRedirect();

    expect($hr->fresh()->hasPermissionTo('store.requisition.view'))->toBeTrue();
});

it('shows "not set" warnings on Requisition Signatures', function () {
    RequisitionSignatureBlock::query()->forLevel('final')->get()->values()[2]->update(['user_id' => null]);

    $this->actingAs(engineUser('admin'))->get(route('admin.requisition-signatures.edit'))
        ->assertOk()
        ->assertSee('approval stage is not set')
        ->assertSee('Not set. Choose a user.');
});

it('gives a new Store head requisition view with the head change', function () {
    $head = User::factory()->create();
    Department::where('key', 'store')->firstOrFail()->update(['head_user_id' => $head->id]);

    expect($head->fresh()->hasPermissionTo('store.requisition.view'))->toBeTrue();
});

it('turns "anyone with the store role" Store Head boxes into the Store department head', function () {
    RequisitionSignatureBlock::query()->where('title', 'Store Head')->orWhere('stamp_source', 'store_head')
        ->update(['assignee_type' => 'role', 'role_name' => 'store']);

    (require database_path('migrations/2026_10_01_000003_store_head_signature_blocks.php'))->up();

    expect(RequisitionSignatureBlock::where('stamp_source', 'store_head')->pluck('assignee_type')->unique()->all())->toBe(['store_head']);
});

it('does not let the section head who approved the sheet also take its Store team step', function () {
    $sectionHead = engineUser('production', ['store.requisition.review']);
    $section = IndentSection::create(['name' => 'Cutting', 'department_key' => 'production', 'head_user_id' => $sectionHead->id, 'is_active' => true]);
    $raiser = engineUser('production', ['store.requisition.create'], ['requisition_section_id' => $section->id]);

    engineSubmit($raiser)->assertRedirect();
    $sheet = PurchaseRequisition::latest('id')->firstOrFail();

    engineApprove($sectionHead, $sheet)->assertRedirect();

    // Same person, now at the Store step: refused. The Store head still can.
    engineApprove($sectionHead, $sheet)->assertForbidden();
    expect($sheet->fresh()->current_stage)->toBe(3);

    engineApprove($this->approvers['store_head'], $sheet)->assertRedirect();
    expect($sheet->fresh()->current_stage)->toBeNull();
});

it('lets the sender name which Store user gets the sheet, and tells only that person', function () {
    $sectionHead = engineUser('production');
    $section = IndentSection::create(['name' => 'Cutting', 'department_key' => 'production', 'head_user_id' => $sectionHead->id, 'is_active' => true]);
    $raiser = engineUser('production', ['store.requisition.create'], ['requisition_section_id' => $section->id]);
    $ebrahim = engineUser('store', [], ['name' => 'Ebrahim', 'status' => 1]);
    engineUser('store', [], ['name' => 'Aminur', 'status' => 1]);

    engineSubmit($raiser)->assertRedirect();
    $sheet = PurchaseRequisition::latest('id')->firstOrFail();

    test()->actingAs($sectionHead)->post(route('store.stock.requisitions.stage-approve', $sheet), ['send_to' => $ebrahim->id])
        ->assertRedirect();

    $store = $sheet->stages()->where('sequence', 3)->first();
    expect($store->assignee_user_id)->toBe($ebrahim->id)
        ->and($store->assignee_name)->toBe('Ebrahim');

    // The rest of the Store team can still take it, so an absent person never blocks it.
    engineApprove(engineUser('store', [], ['status' => 1]), $sheet)->assertRedirect();
    expect($sheet->fresh()->current_stage)->toBeNull();
});

it('ignores a send_to who is not on the Store team', function () {
    $sectionHead = engineUser('production');
    $section = IndentSection::create(['name' => 'Cutting', 'department_key' => 'production', 'head_user_id' => $sectionHead->id, 'is_active' => true]);
    $raiser = engineUser('production', ['store.requisition.create'], ['requisition_section_id' => $section->id]);
    $outsider = engineUser('merchant');

    engineSubmit($raiser)->assertRedirect();
    $sheet = PurchaseRequisition::latest('id')->firstOrFail();

    test()->actingAs($sectionHead)->post(route('store.stock.requisitions.stage-approve', $sheet), ['send_to' => $outsider->id])
        ->assertRedirect();

    expect($sheet->stages()->where('sequence', 3)->value('assignee_user_id'))->toBeNull();
});

it('offers Received, not Approve, to the Store at its step, and a Send-to list to the head before it', function () {
    $this->withoutVite();
    $sectionHead = engineUser('production');
    $section = IndentSection::create(['name' => 'Cutting', 'department_key' => 'production', 'head_user_id' => $sectionHead->id, 'is_active' => true]);
    $raiser = engineUser('production', ['store.requisition.create'], ['requisition_section_id' => $section->id]);
    $store = engineUser('store', [], ['name' => 'Ebrahim', 'status' => 1]);

    engineSubmit($raiser)->assertRedirect();
    $sheet = PurchaseRequisition::latest('id')->firstOrFail();

    // The head, before the Store step: a Send-to choice listing the Store user.
    $html = test()->actingAs($sectionHead)->get(route('store.stock.requisitions.show', $sheet))->assertOk()->getContent();
    expect($html)->toContain('Send to (Store)')->toContain('Ebrahim');

    engineApprove($sectionHead, $sheet)->assertRedirect();

    // The Store at its own step: Received.
    $html = test()->actingAs($store)->get(route('store.stock.requisitions.show', $sheet))->assertOk()->getContent();
    expect($html)->toContain('Mark Received');
});

it('shows a staff member who their requisition goes to in the Submit popup', function () {
    $this->withoutVite();
    $sectionHead = engineUser('production', [], ['name' => 'Oli-ur Rahman']);
    $section = IndentSection::create(['name' => 'Cutting', 'department_key' => 'production', 'head_user_id' => $sectionHead->id, 'is_active' => true]);
    $staff = engineUser('production', ['store.requisition.create'], ['requisition_section_id' => $section->id]);

    $html = test()->actingAs($staff)->get(route('store.stock.requisitions.create'))->assertOk()->getContent();
    expect($html)->toContain('id="submitRouteModal"')->toContain('Oli-ur Rahman');

    // A section head's own sheet goes straight to the Store: they pick the user.
    $ebrahim = engineUser('store', [], ['name' => 'Ebrahim', 'status' => 1]);
    $sectionHead->givePermissionTo(Permission::findOrCreate('store.requisition.create', 'web'));
    $sectionHead->update(['requisition_section_id' => $section->id]);

    $html = test()->actingAs($sectionHead)->get(route('store.stock.requisitions.create'))->assertOk()->getContent();
    expect($html)->toContain('id="submitRouteSelect"')->toContain('Ebrahim');
});

it('lets the sender choose the Store user even when the Store box names the Store head', function () {
    RequisitionSignatureBlock::query()->forLevel('department')->get()->values()[2]
        ->update(['assignee_type' => RequisitionSignatureBlock::ASSIGNEE_STORE_HEAD]);

    $sectionHead = engineUser('production');
    $section = IndentSection::create(['name' => 'Cutting', 'department_key' => 'production', 'head_user_id' => $sectionHead->id, 'is_active' => true]);
    $raiser = engineUser('production', ['store.requisition.create'], ['requisition_section_id' => $section->id]);
    $aminur = engineUser('store', [], ['name' => 'Aminur', 'status' => 1]);

    engineSubmit($raiser)->assertRedirect();
    $sheet = PurchaseRequisition::latest('id')->firstOrFail();

    // The head sees the Store list and the Store step reads "Received".
    $this->withoutVite();
    expect(test()->actingAs($sectionHead)->get(route('store.stock.requisitions.show', $sheet))->getContent())
        ->toContain('Send to (Store)')->toContain('Aminur');

    test()->actingAs($sectionHead)->post(route('store.stock.requisitions.stage-approve', $sheet), ['send_to' => $aminur->id])->assertRedirect();

    expect($sheet->stages()->where('sequence', 3)->value('assignee_user_id'))->toBe($aminur->id);
    engineApprove($aminur, $sheet)->assertRedirect();
    expect($sheet->fresh()->current_stage)->toBeNull();
});
