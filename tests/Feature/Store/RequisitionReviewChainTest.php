<?php

use App\Models\Department;
use App\Models\IndentSection;
use App\Models\PurchaseRequisition;
use App\Models\RequisitionDepartmentHead;
use App\Models\RequisitionSignatureBlock;
use App\Models\StockItem;
use App\Models\User;
use App\Services\RequisitionReviewChain;
use App\Services\RequisitionSignatureResolver;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The section head → department head → Store chain for a department
 * requisition, and the Departments settings screen that names the heads.
 *
 *   - A sheet stops at the NEAREST head only: the section head when the
 *     section has one, else the department head, else the Store.
 *   - A head's own sheet skips the step they would have held.
 *   - A returned sheet goes back to the SAME head on resubmit — unless
 *     that step no longer applies, when the next one takes it.
 *   - The Review queue shows each head only what waits on them.
 *   - The one head check stamps the box for whichever level made it.
 *
 * In-memory sqlite; the 2026_09_23 migrations run on an empty master, so
 * every department, section and head is set up here.
 */

function chainUser(string $department = 'production', ?IndentSection $section = null): User
{
    Role::findOrCreate($department, 'web');
    $role = Role::findOrCreate('dept-requisition', 'web');

    foreach (['store.requisition.view', 'store.requisition.create'] as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return User::factory()->create(['requisition_section_id' => $section?->id])
        ->assignRole($department, 'dept-requisition');
}

function chainSection(string $name = 'Cutting', string $department = 'production', ?User $head = null): IndentSection
{
    return IndentSection::create([
        'name' => $name,
        'department_key' => $department,
        'head_user_id' => $head?->id,
        'is_active' => true,
    ]);
}

function chainStore(): User
{
    $role = Role::findOrCreate('store', 'web');
    $role->givePermissionTo(Permission::findOrCreate('store.requisition.review', 'web'));

    return User::factory()->create()->assignRole('store');
}

function chainSubmit(User $user, array $overrides = [])
{
    $item = StockItem::firstOrCreate(['name' => 'Machine Oil'], ['uom' => 'Ltr', 'is_active' => true]);

    $response = test()->actingAs($user)->post(route('store.stock.requisitions.store'), $overrides + [
        'action' => 'submit',
        'mode' => PurchaseRequisition::MODE_MULTI,
        'requisition_date' => '2026-09-22',
        'items' => [['stock_item_id' => $item->id, 'qty_requested' => 5]],
    ]);

    test()->flushSession();

    return $response;
}

function chainResubmit(User $user, PurchaseRequisition $requisition)
{
    $item = StockItem::firstOrCreate(['name' => 'Machine Oil'], ['uom' => 'Ltr', 'is_active' => true]);

    $response = test()->actingAs($user)->put(route('store.stock.requisitions.update', $requisition), [
        'action' => 'submit',
        'mode' => PurchaseRequisition::MODE_MULTI,
        'requisition_date' => '2026-09-22',
        'items' => [['stock_item_id' => $item->id, 'qty_requested' => 3]],
    ]);

    test()->flushSession();

    return $response;
}

function latestRequisition(): PurchaseRequisition
{
    return PurchaseRequisition::query()->latest('id')->first();
}

beforeEach(function () {
    RequisitionSignatureBlock::query()->delete();
    RequisitionSignatureBlock::create(['level' => 'department', 'sequence' => 1, 'label' => 'Prepared by', 'assignee_type' => 'requester', 'stamp_source' => 'prepared']);
    RequisitionSignatureBlock::create(['level' => 'department', 'sequence' => 2, 'label' => 'Checked by', 'assignee_type' => 'department_head', 'stamp_source' => 'department_head']);
    // The Store Head stage, as the live settings have it: after the head,
    // the sheet waits for the Store.
    RequisitionSignatureBlock::create(['level' => 'department', 'sequence' => 3, 'label' => 'Checked by', 'title' => 'Store Head', 'assignee_type' => 'store_head', 'stamp_source' => 'store_head']);
    // Stage engine: every approval stage has a person, as Admin would set it.
    $this->approvers = requisitionApprovers();
});

// --- Routing on submit ---------------------------------------------------------

it('stops at the section head when the section has one', function () {
    $deptHead = chainUser();
    RequisitionDepartmentHead::assign('production', $deptHead);
    $sectionHead = chainUser();
    $section = chainSection('Cutting', 'production', $sectionHead);
    $requester = chainUser('production', $section);

    chainSubmit($requester)->assertRedirect();

    $r = latestRequisition();

    expect($r->department_role)->toBe('production')
        ->and($r->indent_section_id)->toBe($section->id)
        ->and($r->department_review_status)->toBe(PurchaseRequisition::DEPT_REVIEW_PENDING)
        ->and($r->review_level)->toBe(RequisitionReviewChain::LEVEL_SECTION)
        ->and($r->progressLabel())->toBe('With Section Head')
        ->and($r->isDepartmentHeadFor($sectionHead))->toBeTrue()
        ->and($r->isDepartmentHeadFor($deptHead))->toBeFalse()
        ->and($r->awaitingStoreReceipt())->toBeFalse();
});

it('fixes Requested By on the create form to the section head, the person the sheet goes to', function () {
    $deptHead = chainUser();
    RequisitionDepartmentHead::assign('production', $deptHead);
    $sectionHead = chainUser();
    $section = chainSection('Cutting', 'production', $sectionHead);
    $requester = chainUser('production', $section);

    $this->actingAs($requester)
        ->get(route('store.stock.requisitions.create'))
        ->assertOk()
        ->assertSee('value="'.e($sectionHead->name).'"', false)
        ->assertDontSee('value="'.e($deptHead->name).'"', false);

    // No section head: the department head, as before.
    $section->update(['head_user_id' => null]);

    $this->actingAs($requester->fresh())
        ->get(route('store.stock.requisitions.create'))
        ->assertOk()
        ->assertSee('value="'.e($deptHead->name).'"', false);
});

it('falls through to the department head when the section has no head', function () {
    $deptHead = chainUser();
    RequisitionDepartmentHead::assign('production', $deptHead);
    $section = chainSection('Cutting');
    $requester = chainUser('production', $section);

    chainSubmit($requester)->assertRedirect();

    $r = latestRequisition();

    expect($r->review_level)->toBe(RequisitionReviewChain::LEVEL_DEPARTMENT)
        ->and($r->progressLabel())->toBe('With Department Head')
        ->and($r->isDepartmentHeadFor($deptHead))->toBeTrue();
});

it('goes straight to the Store when neither section nor department has a head', function () {
    $section = chainSection('Cutting');
    $requester = chainUser('production', $section);

    chainSubmit($requester)->assertRedirect();

    $r = latestRequisition();

    expect($r->department_review_status)->toBeNull()
        ->and($r->review_level)->toBeNull()
        ->and($r->progressLabel())->toBe('Sent to Store')
        ->and($r->awaitingStoreReceipt())->toBeTrue();
});

it('skips the section step for the section head\'s own sheet and stops at the department head', function () {
    $deptHead = chainUser();
    RequisitionDepartmentHead::assign('production', $deptHead);
    $sectionHead = chainUser();
    $section = chainSection('Cutting', 'production', $sectionHead);
    $sectionHead->update(['requisition_section_id' => $section->id]);

    chainSubmit($sectionHead)->assertRedirect();

    $r = latestRequisition();

    expect($r->review_level)->toBe(RequisitionReviewChain::LEVEL_DEPARTMENT)
        ->and($r->isDepartmentHeadFor($deptHead))->toBeTrue()
        ->and($r->isDepartmentHeadFor($sectionHead))->toBeFalse();
});

it('sends the section head\'s own sheet straight to the Store when the department has no head', function () {
    $sectionHead = chainUser();
    $section = chainSection('Cutting', 'production', $sectionHead);
    $sectionHead->update(['requisition_section_id' => $section->id]);

    chainSubmit($sectionHead->fresh())->assertRedirect();

    $r = latestRequisition();

    expect($r->department_review_status)->toBeNull()
        ->and($r->review_level)->toBeNull()
        ->and($r->progressLabel())->toBe('Sent to Store')
        ->and($r->awaitingStoreReceipt())->toBeTrue();

    // And their own name is never the "Requested By" head on the form: with
    // nobody above them, the form has no head to fix and offers the picker.
    $this->actingAs($sectionHead->fresh())
        ->get(route('store.stock.requisitions.create'))
        ->assertOk()
        ->assertSee('name="indent_person_id"', false);
});

it('labels the main list "My Requisitions" for a head and "All Requisitions" for the Store', function () {
    $sectionHead = chainUser();
    $section = chainSection('Cutting', 'production', $sectionHead);
    chainSubmit(chainUser('production', $section));

    $this->actingAs($sectionHead)
        ->get(route('store.stock.requisitions.department-review'))
        ->assertOk()
        ->assertSee('My Requisitions')
        ->assertDontSee('All Requisitions');

    $this->actingAs(chainStore())
        ->get(route('store.stock.requisitions.index'))
        ->assertOk()
        ->assertSee('All Requisitions');
});

it('sends the department head\'s own sheet straight to the Store', function () {
    $deptHead = chainUser();
    RequisitionDepartmentHead::assign('production', $deptHead);
    $section = chainSection('Cutting', 'production', chainUser());
    $deptHead->update(['requisition_section_id' => $section->id]);

    // The department head is not the section head, so the section step
    // WOULD apply — and does. Only their own step is skipped.
    chainSubmit($deptHead)->assertRedirect();
    expect(latestRequisition()->review_level)->toBe(RequisitionReviewChain::LEVEL_SECTION);

    // With no section of their own, nothing stands between them and the Store.
    // (fresh(): a real request loads the user anew; this one instance would
    // otherwise still carry the section relation it loaded a moment ago.)
    $deptHead->update(['requisition_section_id' => null]);
    chainSubmit($deptHead->fresh())->assertRedirect();

    $r = latestRequisition();

    expect($r->department_review_status)->toBeNull()
        ->and($r->review_level)->toBeNull();
});

// --- The head's decision ----------------------------------------------------------

it('lets the section head approve, stamping the section box and releasing the sheet to the Store', function () {
    $sectionHead = chainUser();
    $section = chainSection('Cutting', 'production', $sectionHead);
    $requester = chainUser('production', $section);

    chainSubmit($requester);
    $r = latestRequisition();

    $this->actingAs($sectionHead)
        ->post(route('store.stock.requisitions.department-approve', $r))
        ->assertRedirect();

    $r->refresh();

    expect($r->department_review_status)->toBe(PurchaseRequisition::DEPT_REVIEW_APPROVED)
        ->and($r->section_checked_by)->toBe($sectionHead->id)
        ->and($r->section_checked_at)->not->toBeNull()
        ->and($r->department_checked_by)->toBeNull()
        ->and($r->headCheckedBy()?->id)->toBe($sectionHead->id)
        ->and($r->awaitingStoreReceipt())->toBeTrue();

    // The "Checked by" box prints the section head, titled for the section.
    $boxes = app(RequisitionSignatureResolver::class)->forRequisition($r->fresh(['indentSection', 'sectionCheckedBy', 'departmentCheckedBy', 'createdBy']));

    expect($boxes[1]['name'])->toBe($sectionHead->name)
        ->and($boxes[1]['title'])->toBe('Cutting Section Head')
        ->and($boxes[1]['signed_at'])->not->toBeNull();

    // And the Store Head may now take it, at the Store Head stage.
    $this->actingAs($this->approvers['store_head'])
        ->post(route('store.stock.requisitions.stage-approve', $r))
        ->assertRedirect();

    expect($r->fresh()->status)->toBe(PurchaseRequisition::STATUS_STORE_REVIEWED);
});

it('keeps the department head out of a sheet that is with the section head', function () {
    $deptHead = chainUser();
    RequisitionDepartmentHead::assign('production', $deptHead);
    $sectionHead = chainUser();
    $section = chainSection('Cutting', 'production', $sectionHead);
    $requester = chainUser('production', $section);

    chainSubmit($requester);
    $r = latestRequisition();

    $this->actingAs($deptHead)
        ->post(route('store.stock.requisitions.department-approve', $r))
        ->assertForbidden();

    $this->actingAs($deptHead)
        ->get(route('store.stock.requisitions.department-review'))
        ->assertOk()
        ->assertDontSee($r->requisition_no);

    $this->actingAs($sectionHead)
        ->get(route('store.stock.requisitions.department-review'))
        ->assertOk()
        ->assertSee($r->requisition_no);
});

it('lets Management stand in for a section head on an old-flow sheet only', function () {
    $section = chainSection('Cutting', 'production', chainUser());
    $requester = chainUser('production', $section);
    $management = User::factory()->create()->assignRole(Role::findOrCreate('management', 'web'));

    chainSubmit($requester);
    $r = latestRequisition();

    // Stage engine: no stand-ins, not even Management.
    $this->actingAs($management)
        ->post(route('store.stock.requisitions.department-approve', $r))
        ->assertForbidden();

    // An old-flow sheet keeps the old rule.
    $r->forceFill(['workflow_version' => null, 'current_stage' => null])->save();
    $this->actingAs($management)
        ->post(route('store.stock.requisitions.department-approve', $r))
        ->assertRedirect();

    expect($r->fresh()->department_review_status)->toBe(PurchaseRequisition::DEPT_REVIEW_APPROVED);
});

// --- Return and resubmit ------------------------------------------------------------

it('returns a sheet to the requester and takes it back to the same section head on resubmit', function () {
    $deptHead = chainUser();
    RequisitionDepartmentHead::assign('production', $deptHead);
    $sectionHead = chainUser();
    $section = chainSection('Cutting', 'production', $sectionHead);
    $requester = chainUser('production', $section);

    chainSubmit($requester);
    $r = latestRequisition();

    $this->actingAs($sectionHead)
        ->post(route('store.stock.requisitions.department-reject', $r), ['note' => 'Quantity too high'])
        ->assertRedirect();

    $r->refresh();

    expect($r->department_review_status)->toBe(PurchaseRequisition::DEPT_REVIEW_REJECTED)
        ->and($r->review_level)->toBe(RequisitionReviewChain::LEVEL_SECTION)
        ->and($r->progressLabel())->toBe('Returned by Section Head')
        ->and($r->section_checked_by)->toBe($sectionHead->id)
        ->and($r->department_review_note)->toBe('Quantity too high')
        ->and($r->isEditable())->toBeTrue();

    $this->actingAs($requester)
        ->get(route('store.stock.requisitions.show', $r))
        ->assertOk()
        ->assertSee('Returned by '.$sectionHead->name)
        ->assertSee('Quantity too high');

    chainResubmit($requester, $r)->assertRedirect();
    $r->refresh();

    expect($r->department_review_status)->toBe(PurchaseRequisition::DEPT_REVIEW_PENDING)
        ->and($r->review_level)->toBe(RequisitionReviewChain::LEVEL_SECTION)
        ->and($r->section_checked_by)->toBeNull()
        ->and($r->department_review_note)->toBeNull();
});

it('moves a returned sheet to the department head on resubmit when the section head is gone', function () {
    $deptHead = chainUser();
    RequisitionDepartmentHead::assign('production', $deptHead);
    $sectionHead = chainUser();
    $section = chainSection('Cutting', 'production', $sectionHead);
    $requester = chainUser('production', $section);

    chainSubmit($requester);
    $r = latestRequisition();

    $this->actingAs($sectionHead)
        ->post(route('store.stock.requisitions.department-reject', $r), ['note' => 'Wrong item']);

    $section->update(['head_user_id' => null]);

    chainResubmit($requester, $r->fresh())->assertRedirect();
    $r->refresh();

    expect($r->review_level)->toBe(RequisitionReviewChain::LEVEL_DEPARTMENT)
        ->and($r->department_review_status)->toBe(PurchaseRequisition::DEPT_REVIEW_PENDING)
        ->and($r->isDepartmentHeadFor($deptHead))->toBeTrue();
});

it('sends a returned sheet to the Store on resubmit when no head applies any more', function () {
    $sectionHead = chainUser();
    $section = chainSection('Cutting', 'production', $sectionHead);
    $requester = chainUser('production', $section);

    chainSubmit($requester);
    $r = latestRequisition();

    $this->actingAs($sectionHead)
        ->post(route('store.stock.requisitions.department-reject', $r), ['note' => 'Wrong item']);

    $section->update(['head_user_id' => null]);

    chainResubmit($requester, $r->fresh())->assertRedirect();
    $r->refresh();

    expect($r->review_level)->toBeNull()
        ->and($r->department_review_status)->toBeNull()
        ->and($r->awaitingStoreReceipt())->toBeTrue();
});

// --- The queue ------------------------------------------------------------------------

it('shows a user who heads both a section and a department the sheets waiting at each', function () {
    $head = chainUser();
    RequisitionDepartmentHead::assign('production', $head);
    $cutting = chainSection('Cutting', 'production', $head);
    $finishing = chainSection('Finishing', 'production');

    chainSubmit(chainUser('production', $cutting));
    $atSection = latestRequisition();
    chainSubmit(chainUser('production', $finishing));
    $atDepartment = latestRequisition();

    expect($atSection->review_level)->toBe(RequisitionReviewChain::LEVEL_SECTION)
        ->and($atDepartment->review_level)->toBe(RequisitionReviewChain::LEVEL_DEPARTMENT)
        ->and(PurchaseRequisition::query()->awaitingDepartmentHead($head)->pluck('id')->sort()->values()->all())
            ->toBe(collect([$atSection->id, $atDepartment->id])->sort()->values()->all());
});

it('hides the Review tab from a user who heads nothing', function () {
    $user = chainUser('production', chainSection('Cutting'));

    $this->actingAs($user)
        ->get(route('store.stock.requisitions.department-review'))
        ->assertForbidden();
});

// --- Admin > Settings > Departments ----------------------------------------------------

function chainAdmin(): User
{
    return User::factory()->create()->assignRole(Role::findOrCreate('admin', 'web'));
}

it('renders the Departments screen with the seeded departments', function () {
    $this->actingAs(chainAdmin())
        ->get(route('admin.departments.index'))
        ->assertOk()
        ->assertSee('Production')
        ->assertSee('HARC')
        ->assertSee('Maintenance')
        ->assertSee('Add Department');
});

it('names a department head and a section head from the Departments screen', function () {
    $deptHead = User::factory()->create();
    $sectionHead = User::factory()->create();
    $section = chainSection('Cutting');

    $this->actingAs(chainAdmin())
        ->put(route('admin.departments.update', Department::where('key', 'production')->first()), [
            'label' => 'Production',
            'head_user_id' => $deptHead->id,
            'is_active' => 1,
            'sort_order' => 60,
            'roles' => ['production', 'cutting'],
            'sections' => [['id' => $section->id, 'name' => 'Cutting Section', 'head_user_id' => $sectionHead->id]],
        ])
        ->assertRedirect(route('admin.departments.index'));

    expect(RequisitionDepartmentHead::headOf('production')?->id)->toBe($deptHead->id)
        ->and(RequisitionDepartmentHead::headOf('cutting')?->id)->toBe($deptHead->id)
        ->and(RequisitionDepartmentHead::sectionsHeadedBy($sectionHead))->toBe([$section->id])
        ->and($section->fresh()->name)->toBe('Cutting Section');
});

it('adds a section under a department and files an existing register section rather than duplicating it', function () {
    $production = Department::where('key', 'production')->first();
    $head = User::factory()->create();

    $this->actingAs(chainAdmin())
        ->post(route('admin.departments.sections.store', $production), ['name' => 'Finishing', 'head_user_id' => $head->id])
        ->assertRedirect(route('admin.departments.index'));

    $finishing = IndentSection::where('name', 'Finishing')->first();

    expect($finishing)->not->toBeNull()
        ->and($finishing->department_key)->toBe('production')
        ->and($finishing->head_user_id)->toBe($head->id);

    // A plain register section of the same name is filed, not copied.
    $plain = IndentSection::create(['name' => 'Quality', 'is_active' => true]);

    $this->actingAs(chainAdmin())
        ->post(route('admin.departments.sections.store', $production), ['name' => 'quality'])
        ->assertRedirect();

    expect(IndentSection::where('name', 'Quality')->count())->toBe(1)
        ->and($plain->fresh()->department_key)->toBe('production');

    // One already under another department is refused, not moved.
    $this->actingAs(chainAdmin())
        ->from(route('admin.departments.index'))
        ->post(route('admin.departments.sections.store', Department::where('key', 'harc')->first()), ['name' => 'Quality'])
        ->assertSessionHasErrors('name');
});

it('removes a section from its department without deleting the register row', function () {
    $section = chainSection('Cutting', 'production', User::factory()->create());

    $this->actingAs(chainAdmin())
        ->delete(route('admin.departments.sections.detach', $section))
        ->assertRedirect(route('admin.departments.index'));

    $section->refresh();

    expect($section->trashed())->toBeFalse()
        ->and($section->department_key)->toBeNull()
        ->and($section->head_user_id)->toBeNull();
});

it('adds a department and moves a role into it, so the role\'s users now belong there', function () {
    Role::findOrCreate('cutting', 'web');

    $this->actingAs(chainAdmin())
        ->post(route('admin.departments.store'), ['label' => 'Washing Plant', 'roles' => ['cutting']])
        ->assertRedirect(route('admin.departments.index'));

    $department = Department::where('label', 'Washing Plant')->first();

    expect($department)->not->toBeNull()
        ->and($department->key)->toBe('washing_plant')
        ->and(\App\Support\DepartmentRoles::departmentOf('cutting'))->toBe('washing_plant')
        ->and(\App\Support\DepartmentRoles::rolesFor('production'))->not->toContain('cutting');
});

it('keeps admin and management under Management / Admin whatever is posted', function () {
    $this->actingAs(chainAdmin())
        ->post(route('admin.departments.store'), ['label' => 'Rogue', 'roles' => ['admin']])
        ->assertSessionHasErrors('roles.0');

    expect(\App\Support\DepartmentRoles::departmentOf('admin'))->toBe('management');
});

it('keeps the Departments screen to the admin', function () {
    $this->actingAs(chainUser())
        ->get(route('admin.departments.index'))
        ->assertForbidden();
});
