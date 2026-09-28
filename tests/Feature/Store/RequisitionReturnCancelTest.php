<?php

use App\Models\IndentSection;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionAction;
use App\Models\PurchaseRequisitionStage;
use App\Models\StockItem;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Step 3c — Return and Cancel.
 *
 *   - Return goes to the PREVIOUS stage, with a reason kept in the history;
 *     from the first stage it goes to the raiser, who amends and resubmits
 *     (a new cycle) or cancels.
 *   - Cancel closes a sheet for good: its raiser while it is theirs, Admin
 *     until approval. A cancelled sheet is counted nowhere.
 *   - Delete is for drafts only; a submitted sheet is cancelled instead.
 *
 * In-memory sqlite. No real record touched.
 */
beforeEach(fn () => $this->approvers = requisitionApprovers());

function rcUser(string $role, array $permissions = [], array $attributes = []): User
{
    $user = User::factory()->create($attributes)->assignRole(Role::findOrCreate($role, 'web'));

    foreach (array_merge(['store.requisition.view'], $permissions) as $p) {
        $user->givePermissionTo(Permission::findOrCreate($p, 'web'));
    }

    return $user;
}

function rcPayload(string $action = 'submit', float $qty = 5): array
{
    $item = StockItem::firstOrCreate(['name' => 'Machine Oil'], ['uom' => 'Ltr', 'is_active' => true]);

    return [
        'action' => $action, 'mode' => PurchaseRequisition::MODE_MULTI, 'requisition_date' => '2026-09-22',
        'items' => [['stock_item_id' => $item->id, 'qty_requested' => $qty]],
    ];
}

/** A final sheet raised by a Store clerk, on the stage engine. */
function rcFinal(?User $clerk = null): array
{
    $clerk ??= rcUser('store', ['store.requisition.create']);
    test()->actingAs($clerk)->post(route('store.stock.requisitions.store'), rcPayload())->assertRedirect();

    return [$clerk, PurchaseRequisition::latest('id')->firstOrFail()];
}

function rcApprove(User $user, PurchaseRequisition $sheet)
{
    return test()->actingAs($user)->post(route('store.stock.requisitions.stage-approve', $sheet));
}

function rcReturn(User $user, PurchaseRequisition $sheet, ?string $reason = 'Please recheck the quantity')
{
    return test()->actingAs($user)->post(route('store.stock.requisitions.stage-return', $sheet), array_filter(['comment' => $reason]));
}

function rcCounted(PurchaseRequisition $sheet): bool
{
    return PurchaseRequisition::query()->countedInTotals()->whereKey($sheet->id)->exists();
}

// --- Return ------------------------------------------------------------------------

it('returns to the previous stage, which acts again, with the reason kept', function () {
    [, $sheet] = rcFinal();
    rcApprove($this->approvers['store_head'], $sheet);
    expect($sheet->fresh()->status)->toBe(PurchaseRequisition::STATUS_STORE_REVIEWED);

    rcReturn($this->approvers['hr'], $sheet, 'Split the order in two')->assertRedirect();
    $sheet->refresh();

    // Back with the Store Head, whose stamp is taken back.
    expect($sheet->current_stage)->toBe(2)
        ->and($sheet->status)->toBe(PurchaseRequisition::STATUS_SUBMITTED)
        ->and($sheet->store_ack_by)->toBeNull()
        ->and($sheet->stages()->where('sequence', 2)->where('cycle', 1)->value('status'))->toBe(PurchaseRequisitionStage::STATUS_PENDING);

    $row = PurchaseRequisitionAction::where('purchase_requisition_id', $sheet->id)->where('action', 'returned')->sole();
    expect($row->comment)->toBe('Split the order in two')
        ->and($row->user_id)->toBe($this->approvers['hr']->id)
        ->and($row->meta['returned_to'])->toContain('Store Head');

    // The Store Head approves again and it moves on.
    rcApprove($this->approvers['store_head'], $sheet)->assertRedirect();
    expect($sheet->fresh()->current_stage)->toBe(3);

    $this->actingAs($this->approvers['store_head'])->get(route('store.stock.requisitions.show', $sheet))->assertOk();
});

it('returns from the first stage to the raiser, who amends and resubmits as a new cycle', function () {
    [$clerk, $sheet] = rcFinal();

    rcReturn($this->approvers['store_head'], $sheet, 'Wrong item')->assertRedirect();
    $sheet->refresh();

    expect($sheet->returnedToRaiser())->toBeTrue()
        ->and($sheet->isEditable())->toBeTrue()
        ->and($sheet->progressLabel())->toBe('Returned to Requester')
        ->and(rcCounted($sheet))->toBeFalse();

    $this->actingAs($clerk)->get(route('store.stock.requisitions.show', $sheet))
        ->assertOk()->assertSee('Wrong item')->assertSee('Amend the requisition and submit it again, or cancel it.');

    // Nobody else edits it — not even another Store user.
    $this->actingAs(rcUser('store', ['store.requisition.edit', 'store.requisition.create']))
        ->get(route('store.stock.requisitions.edit', $sheet))->assertForbidden();

    $this->actingAs($clerk)->put(route('store.stock.requisitions.update', $sheet), rcPayload('submit', 3))->assertRedirect();
    $sheet->refresh();

    expect($sheet->current_stage)->toBe(2)
        ->and($sheet->stages()->max('cycle'))->toBe(2)
        ->and(rcCounted($sheet))->toBeTrue()
        ->and(PurchaseRequisitionAction::where('purchase_requisition_id', $sheet->id)->orderBy('id')->pluck('action')->all())
            ->toBe(['submitted', 'returned', 'resubmitted'])
        ->and(PurchaseRequisitionAction::where('purchase_requisition_id', $sheet->id)->where('action', 'resubmitted')->value('cycle'))->toBe(2);
});

it('returns a department sheet from the Store head to its head, and from the head to the raiser', function () {
    $head = rcUser('production');
    $section = IndentSection::create(['name' => 'Cutting', 'department_key' => 'production', 'head_user_id' => $head->id, 'is_active' => true]);
    $raiser = rcUser('production', ['store.requisition.create'], ['requisition_section_id' => $section->id]);

    $this->actingAs($raiser)->post(route('store.stock.requisitions.store'), rcPayload())->assertRedirect();
    $sheet = PurchaseRequisition::latest('id')->firstOrFail();

    rcApprove($head, $sheet);
    rcReturn($this->approvers['store_head'], $sheet)->assertRedirect();
    $sheet->refresh();
    expect($sheet->current_stage)->toBe(2)
        ->and($sheet->awaitingDepartmentHead())->toBeTrue()
        ->and($sheet->section_checked_by)->toBeNull();

    rcReturn($head, $sheet, 'Too many')->assertRedirect();
    $sheet->refresh();
    expect($sheet->rejectedByDepartmentHead())->toBeTrue()
        ->and($sheet->returnedToRaiser())->toBeTrue()
        ->and($sheet->department_review_note)->toBe('Too many')
        ->and(rcCounted($sheet))->toBeFalse();
});

it('needs a reason, and only the person the sheet waits for may return it', function () {
    [$clerk, $sheet] = rcFinal();

    rcReturn($this->approvers['store_head'], $sheet, null)->assertSessionHasErrors('comment');
    foreach ([$clerk, $this->approvers['hr'], rcUser('admin'), rcUser('management')] as $other) {
        rcReturn($other, $sheet)->assertForbidden();
    }

    expect($sheet->fresh()->current_stage)->toBe(2);
});

// --- Cancel -----------------------------------------------------------------------

it('lets the raiser cancel a sheet returned to them, with a reason, and counts it nowhere', function () {
    [$clerk, $sheet] = rcFinal();

    // Not while it is with an approver.
    $this->actingAs($clerk)->post(route('store.stock.requisitions.cancel', $sheet), ['comment' => 'Not needed'])
        ->assertSessionHas('error');
    expect($sheet->fresh()->isCancelled())->toBeFalse();

    rcReturn($this->approvers['store_head'], $sheet);

    $this->actingAs($clerk)->post(route('store.stock.requisitions.cancel', $sheet))->assertSessionHasErrors('comment');
    $this->actingAs($clerk)->post(route('store.stock.requisitions.cancel', $sheet), ['comment' => 'Bought locally'])
        ->assertRedirect(route('store.stock.requisitions.show', $sheet));

    $sheet->refresh();
    expect($sheet->status)->toBe(PurchaseRequisition::STATUS_CANCELLED)
        ->and($sheet->current_stage)->toBeNull()
        ->and($sheet->isEditable())->toBeFalse()
        ->and(rcCounted($sheet))->toBeFalse()
        ->and(PurchaseRequisitionAction::where('purchase_requisition_id', $sheet->id)->where('action', 'cancelled')->sole()->comment)->toBe('Bought locally');

    $this->actingAs($clerk)->get(route('store.stock.requisitions.show', $sheet))
        ->assertOk()->assertSee('Cancelled')->assertSee('Bought locally')->assertDontSee('Cancel Requisition');
});

it('lets Admin cancel a submitted sheet on either flow, but not an approved one', function () {
    $admin = rcUser('admin');
    [, $sheet] = rcFinal();

    $this->actingAs($admin)->post(route('store.stock.requisitions.cancel', $sheet), ['comment' => 'Duplicate'])->assertRedirect();
    expect($sheet->fresh()->isCancelled())->toBeTrue();

    $old = PurchaseRequisition::create([
        'requisition_no' => 'OLD-2', 'requisition_date' => '2026-08-10', 'mode' => PurchaseRequisition::MODE_MULTI,
        'level' => PurchaseRequisition::LEVEL_FINAL, 'status' => PurchaseRequisition::STATUS_SUBMITTED,
        'submitted_at' => now(), 'created_by' => rcUser('merchant')->id,
    ]);
    $this->actingAs($admin)->post(route('store.stock.requisitions.cancel', $old), ['comment' => 'Old duplicate'])->assertRedirect();
    expect($old->fresh()->isCancelled())->toBeTrue();

    $approved = PurchaseRequisition::create([
        'requisition_no' => 'OLD-3', 'requisition_date' => '2026-08-10', 'mode' => PurchaseRequisition::MODE_MULTI,
        'level' => PurchaseRequisition::LEVEL_FINAL, 'status' => PurchaseRequisition::STATUS_APPROVED,
        'submitted_at' => now(), 'created_by' => rcUser('merchant')->id,
    ]);
    $this->actingAs($admin)->post(route('store.stock.requisitions.cancel', $approved), ['comment' => 'Too late'])->assertSessionHas('error');
    expect($approved->fresh()->status)->toBe(PurchaseRequisition::STATUS_APPROVED);

    // Management cannot cancel.
    [, $another] = rcFinal();
    $this->actingAs(rcUser('management'))->post(route('store.stock.requisitions.cancel', $another), ['comment' => 'x'])->assertSessionHas('error');
});

it('lists cancelled sheets under their own status', function () {
    $admin = rcUser('admin');
    [, $sheet] = rcFinal();
    $this->actingAs($admin)->post(route('store.stock.requisitions.cancel', $sheet), ['comment' => 'Duplicate']);

    $this->actingAs($admin)->get(route('store.stock.requisitions.index', ['status' => 'cancelled']))
        ->assertOk()->assertSee($sheet->requisition_no)->assertSee('Cancelled');
});

// --- Delete -------------------------------------------------------------------------

it('deletes drafts only; a submitted sheet is cancelled instead', function () {
    $clerk = rcUser('store', ['store.requisition.create']);
    $this->actingAs($clerk)->post(route('store.stock.requisitions.store'), rcPayload('draft'))->assertRedirect();
    $draft = PurchaseRequisition::latest('id')->firstOrFail();

    [, $submitted] = rcFinal($clerk);

    $this->actingAs(rcUser('admin'))->delete(route('store.stock.requisitions.destroy', $submitted), ['comment' => 'x'])
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'Cancel it instead'));
    expect(PurchaseRequisition::find($submitted->id))->not->toBeNull();

    $this->actingAs($clerk)->delete(route('store.stock.requisitions.destroy', $draft))->assertRedirect();
    expect(PurchaseRequisition::find($draft->id))->toBeNull();
});

// --- The migration -----------------------------------------------------------------

it('adds "cancelled" to the status, and refuses to take it away while it is used', function () {
    $migration = require database_path('migrations/2026_10_02_000001_add_cancelled_to_purchase_requisition_status.php');
    [, $sheet] = rcFinal();
    $sheet->forceFill(['status' => PurchaseRequisition::STATUS_CANCELLED])->save();

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'are cancelled');
    expect($sheet->fresh()->status)->toBe(PurchaseRequisition::STATUS_CANCELLED);

    // With none cancelled, down() narrows it and up() widens it again.
    $sheet->forceFill(['status' => PurchaseRequisition::STATUS_SUBMITTED])->save();
    $migration->down();
    expect(fn () => $sheet->forceFill(['status' => 'cancelled'])->save())->toThrow(\Illuminate\Database\QueryException::class);

    $migration->up();
    $sheet->refresh()->forceFill(['status' => 'cancelled'])->save();
    expect($sheet->fresh()->status)->toBe('cancelled');
});
