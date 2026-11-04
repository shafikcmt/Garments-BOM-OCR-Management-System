<?php

use App\Models\Department;
use App\Models\RequisitionSignatureBlock;
use App\Models\User;
use App\Services\RequisitionWorkflow;

/**
 * The Store Head preparing the Store's own Final Requisition. The Store Head box
 * can name "Store Department Head" or a specific person; both must let the Store
 * Head's own final sheet through, and nobody else approving their own sheet.
 */
beforeEach(function () {
    $this->head = User::factory()->create(['name' => 'Store Head']);
    Department::updateOrCreate(['key' => 'store'], ['label' => 'Store', 'head_user_id' => $this->head->id, 'is_active' => true]);
    $this->workflow = app(RequisitionWorkflow::class);

    // Finance and Operation set, so the only thing under test is the Store Head box.
    $final = RequisitionSignatureBlock::query()->forLevel('final')->get()->values();
    foreach ([2, 3] as $i) {
        $final->get($i)->update(['assignee_type' => 'user', 'user_id' => User::factory()->create()->id]);
    }
    $this->storeHeadBox = $final->firstWhere('stamp_source', 'store_head');
});

function selfApprovalProblems(User $raiser): array
{
    return collect(app(RequisitionWorkflow::class)->problems('final', $raiser, null, null))
        ->filter(fn ($p) => str_contains($p, 'nobody may approve their own requisition'))->values()->all();
}

it('lets the Store Head prepare the final sheet when the box says Store Department Head', function () {
    expect($this->storeHeadBox->assignee_type)->toBe('store_head')
        ->and(selfApprovalProblems($this->head))->toBe([]);
});

it('lets the Store Head prepare the final sheet when the box names them as a specific user', function () {
    $this->storeHeadBox->update(['assignee_type' => 'user', 'user_id' => $this->head->id]);

    expect(selfApprovalProblems($this->head))->toBe([]);

    $stage = collect($this->workflow->plan('final', $this->head, null, null))->firstWhere('label', 'Checked by');
    expect($stage['self_approved'])->toBeTrue()->and($stage['user']->id)->toBe($this->head->id);
});

it('still refuses a named approver who is not the Store Head approving their own final sheet', function () {
    $other = User::factory()->create();
    $this->storeHeadBox->update(['assignee_type' => 'user', 'user_id' => $other->id]);

    expect(selfApprovalProblems($other))->toHaveCount(1);
});

it('does not need an exemption when another person prepares and the Store Head approves', function () {
    $this->storeHeadBox->update(['assignee_type' => 'user', 'user_id' => $this->head->id]);
    $preparer = User::factory()->create();

    expect(selfApprovalProblems($preparer))->toBe([]);
    $stage = collect($this->workflow->plan('final', $preparer, null, null))->firstWhere('label', 'Checked by');
    expect($stage['self_approved'])->toBeFalse()->and($stage['user']->id)->toBe($this->head->id);
});

it('lets the Store Head prepare the final sheet when the box is a named user with approval only', function () {
    $this->storeHeadBox->update(['assignee_type' => 'user', 'user_id' => $this->head->id, 'stamp_source' => null]);

    expect(selfApprovalProblems($this->head))->toBe([]);
});

it('makes the Store user who submits a Final draft its preparer, through the real submit', function () {
    $this->storeHeadBox->update(['assignee_type' => 'user', 'user_id' => $this->head->id, 'stamp_source' => null]);
    $item = \App\Models\StockItem::create(['name' => 'Machine Oil', 'uom' => 'Ltr', 'is_active' => true]);

    $amirul = User::factory()->create(['name' => 'Amirul']);
    foreach (['store.requisition.view', 'store.requisition.edit', 'store.requisition.create', 'store.requisition.final'] as $name) {
        $amirul->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($name, 'web'));
    }

    // The Store Head merged it earlier, so the draft carries their name.
    $draft = \App\Models\PurchaseRequisition::create([
        'requisition_no' => 'F/1', 'requisition_date' => '2026-10-31', 'mode' => 'multi', 'level' => 'final',
        'requisition_type' => 'monthly', 'status' => 'draft', 'created_by' => $this->head->id,
    ]);

    $this->actingAs($amirul)->put(route('store.stock.requisitions.update', $draft), [
        'action' => 'submit', 'mode' => 'multi', 'requisition_date' => '2026-10-31',
        'items' => [['stock_item_id' => $item->id, 'qty_requested' => 5]],
    ])->assertSessionHasNoErrors();

    $draft->refresh();
    expect($draft->created_by)->toBe($amirul->id)
        ->and($draft->stages()->orderBy('sequence')->first()->assignee_user_id)->toBe($amirul->id);
});
