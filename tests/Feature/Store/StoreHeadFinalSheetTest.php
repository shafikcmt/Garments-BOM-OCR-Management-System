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
