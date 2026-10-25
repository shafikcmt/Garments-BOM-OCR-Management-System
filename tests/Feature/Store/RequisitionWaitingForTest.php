<?php

use App\Models\IndentSection;
use App\Models\PurchaseRequisition;
use App\Models\StockItem;
use App\Models\User;
use App\Services\RequisitionWorkflow;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * "Sheets waiting for me" (My Approvals, step 3e) must be exactly what the
 * stage engine lets a user act on: RequisitionWorkflow::waitingFor() filters
 * through the same mayApprove() that canAct() uses. These tests walk both
 * sheet levels and compare the list with canAct() for every user.
 *
 * In-memory sqlite. No real record touched.
 */
beforeEach(fn () => $this->approvers = requisitionApprovers());

function rwfUser(string $role, array $attributes = [], array $permissions = ['store.requisition.view']): User
{
    $user = User::factory()->create($attributes)->assignRole(Role::findOrCreate($role, 'web'));

    foreach ($permissions as $p) {
        $user->givePermissionTo(Permission::findOrCreate($p, 'web'));
    }

    return $user;
}

/** @return array{0: IndentSection, 1: User, 2: User} section, its head, a raiser in it */
function rwfSection(string $name): array
{
    $head = rwfUser('production', ['name' => $name.' Head']);
    $section = IndentSection::create(['name' => $name, 'department_key' => 'production', 'head_user_id' => $head->id, 'is_active' => true]);
    $raiser = rwfUser('production', ['name' => $name.' Raiser', 'requisition_section_id' => $section->id], ['store.requisition.view', 'store.requisition.create']);

    return [$section, $head, $raiser];
}

function rwfSubmit(User $user, string $item = 'Machine Oil', int $qty = 5): PurchaseRequisition
{
    $stock = StockItem::firstOrCreate(['name' => $item], ['uom' => 'Pcs', 'is_active' => true]);

    test()->actingAs($user)->post(route('store.stock.requisitions.store'), [
        'action' => 'submit', 'mode' => PurchaseRequisition::MODE_MULTI, 'requisition_date' => '2026-10-05',
        'items' => [['stock_item_id' => $stock->id, 'qty_requested' => $qty]],
    ])->assertSessionHasNoErrors()->assertRedirect();

    return PurchaseRequisition::latest('id')->firstOrFail();
}

function rwfApprove(User $user, PurchaseRequisition $sheet): void
{
    test()->actingAs($user)->post(route('store.stock.requisitions.stage-approve', $sheet))->assertSessionHasNoErrors();
}

/** Who has this sheet in their list. */
function rwfWaiting(PurchaseRequisition $sheet, array $users): array
{
    $workflow = app(RequisitionWorkflow::class);

    return collect($users)->filter(fn ($u) => $workflow->waitingFor($u)->contains('id', $sheet->id))->map->name->values()->all();
}

it('lists a department sheet for its head, then for every Store user, and never for the raiser or Admin', function () {
    [, $head, $raiser] = rwfSection('Cutting');
    $clerk = rwfUser('store', ['name' => 'Clerk One']);
    $other = rwfUser('store', ['name' => 'Clerk Two']);
    $admin = rwfUser('admin');
    $management = rwfUser('management');
    $everyone = [$head, $raiser, $clerk, $other, $admin, $management, $this->approvers['hr']];

    $sheet = rwfSubmit($raiser);

    expect(rwfWaiting($sheet, $everyone))->toBe([$head->name]);

    rwfApprove($head, $sheet);
    expect(rwfWaiting($sheet->fresh(), $everyone))->toBe(['Clerk One', 'Clerk Two']);

    rwfApprove($clerk, $sheet);
    expect(rwfWaiting($sheet->fresh(), $everyone))->toBe([]);
});

it('always agrees with canAct(), for every user at every stage of a department sheet', function () {
    [, $head, $raiser] = rwfSection('Sewing');
    $users = [$head, $raiser, rwfUser('store'), rwfUser('admin'), rwfUser('management'), $this->approvers['store_head'], $this->approvers['hr']];
    $workflow = app(RequisitionWorkflow::class);

    $sheet = rwfSubmit($raiser);

    foreach ([$head, rwfUser('store')] as $actor) {
        foreach ($users as $user) {
            expect($workflow->waitingFor($user)->contains('id', $sheet->id))->toBe($workflow->canAct($sheet->fresh(), $user), $user->name);
        }
        rwfApprove($actor, $sheet);
    }
});

it('hands each waiting sheet its waiting stage, and ignores a cancelled one', function () {
    [, $head, $raiser] = rwfSection('Cutting');
    $sheet = rwfSubmit($raiser);

    $waiting = app(RequisitionWorkflow::class)->waitingFor($head);
    expect($waiting)->toHaveCount(1)
        ->and($waiting->first()->waitingStage->sequence)->toBe($sheet->current_stage)
        ->and($waiting->first()->waitingStage->assignee_user_id)->toBe($head->id);

    $this->actingAs(rwfUser('admin'))->post(route('store.stock.requisitions.cancel', $sheet), ['comment' => 'Not needed'])->assertRedirect();

    expect(app(RequisitionWorkflow::class)->waitingFor($head))->toHaveCount(0);
});

it('lists a sheet only at its latest cycle, after a return and resubmit', function () {
    [, $head, $raiser] = rwfSection('Cutting');
    $sheet = rwfSubmit($raiser);

    $this->actingAs($head)->post(route('store.stock.requisitions.stage-return', $sheet), ['comment' => 'Fix the qty'])->assertSessionHasNoErrors();

    // Back with the raiser: nobody has it to approve.
    expect(app(RequisitionWorkflow::class)->waitingFor($head))->toHaveCount(0);

    $line = $sheet->fresh()->items()->first();
    $this->actingAs($raiser)->put(route('store.stock.requisitions.update', $sheet), [
        'action' => 'submit', 'mode' => PurchaseRequisition::MODE_MULTI, 'requisition_date' => '2026-10-05',
        'items' => [['id' => $line->id, 'stock_item_id' => $line->stock_item_id, 'qty_requested' => 4]],
    ])->assertSessionHasNoErrors();

    expect($sheet->fresh()->stages()->max('cycle'))->toBe(2)
        ->and(app(RequisitionWorkflow::class)->waitingFor($head)->pluck('id')->all())->toBe([$sheet->id]);
});

it('lets the named HR, Finance and Operation approvers open the final sheet waiting for them', function () {
    $clerk = rwfUser('store', ['name' => 'Store Clerk'], ['store.requisition.view', 'store.requisition.create']);
    [, $head, $raiser] = rwfSection('Cutting');
    $department = rwfSubmit($raiser);
    rwfApprove($head, $department);
    rwfApprove($clerk, $department);

    $this->actingAs($clerk)->post(route('store.stock.requisitions.merge'), ['ids' => [$department->id]])->assertSessionHasNoErrors();
    $final = PurchaseRequisition::where('level', PurchaseRequisition::LEVEL_FINAL)->firstOrFail();
    $line = $final->items()->first();
    $this->actingAs($clerk)->put(route('store.stock.requisitions.update', $final), [
        'action' => 'submit', 'mode' => PurchaseRequisition::MODE_MULTI, 'requisition_date' => $final->requisition_date->toDateString(),
        'items' => [['id' => $line->id, 'stock_item_id' => $line->stock_item_id, 'qty_requested' => 5, 'user_dept' => $line->user_dept]],
    ])->assertSessionHasNoErrors();

    $final->refresh();

    // Every approver can OPEN the final sheet from the first stage (it is theirs to approve later)...
    foreach (['store_head', 'hr', 'finance', 'operation'] as $who) {
        $this->actingAs($this->approvers[$who])->get(route('store.stock.requisitions.show', $final))->assertOk();
    }

    // ...and it is in the list of whoever it waits for, one stage at a time.
    foreach (['store_head', 'hr', 'finance', 'operation'] as $who) {
        expect(rwfWaiting($final->fresh(), array_values($this->approvers)))->toBe([$this->approvers[$who]->name]);
        rwfApprove($this->approvers[$who], $final);
    }

    expect(rwfWaiting($final->fresh(), array_values($this->approvers)))->toBe([]);
});

it('computes the list once per request', function () {
    [, $head, $raiser] = rwfSection('Cutting');
    rwfSubmit($raiser);
    $workflow = app(RequisitionWorkflow::class);

    $this->actingAs($head);
    $first = $workflow->waitingForOnce($head);

    expect($workflow->waitingForOnce($head))->toBe($first);
});
