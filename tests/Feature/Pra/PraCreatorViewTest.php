<?php

use App\Models\Buyer;
use App\Models\ExcelFile;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestItem;
use App\Models\PraApproval;
use App\Models\PraApprover;
use App\Models\User;
use App\Services\PraWorkflow;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * WHAT THE SUPPLY CHAIN CREATOR SEES about their own PRA.
 *
 * Two bugs are pinned here, both of the same shape: flow-1 code answering a
 * question about a flow-2 record.
 *
 *   approvalProgress()  describes a fixed set of chosen approvers holding
 *                       pending rows. A departmental PRA has neither, so the
 *                       creator of one halfway down the chain was told it had
 *                       never been sent for approval.
 *   the picker fields   "Send for check to" / "Send for approval to" post
 *                       values that store() no longer reads on this flow. A
 *                       control that silently does nothing is worse than no
 *                       control.
 */
function cvUser(string $role, bool $head = false, string $name = 'X'): User
{
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create(['is_department_admin' => $head, 'name' => $name]);
    $user->assignRole($role);

    return $user->fresh();
}

function cvPra(User $creator, Buyer $buyer, bool $departmental = true): PaymentRequest
{
    $pra = PaymentRequest::create([
        'request_no' => 'PRA-' . fake()->unique()->numerify('#####'),
        'status' => 'draft',
        'created_by' => $creator->id,
        'buyer_name' => $buyer->buyer_name,
    ]);

    $file = ExcelFile::create([
        'file_name' => 'b.xlsx', 'original_file_name' => 'b.xlsx', 'file_path' => 'b.xlsx',
        'uploaded_by' => $creator->id, 'buyer_id' => $buyer->id, 'total_rows' => 1,
    ]);
    PaymentRequestItem::create(['payment_request_id' => $pra->id, 'excel_file_id' => $file->id]);

    return $departmental ? app(PraWorkflow::class)->start($pra->fresh()) : $pra->fresh();
}

beforeEach(function () {
    Permission::findOrCreate('pra.departmental.act', 'web');
    foreach (['merchant', 'account', 'commercial'] as $role) {
        Role::findOrCreate($role, 'web')->givePermissionTo('pra.departmental.act');
    }

    $this->buyer = Buyer::create(['buyer_name' => 'Hugo Boss']);
    $this->creator = cvUser('supply_chain', name: 'Creator');
    $this->merchantTeam = cvUser('merchant', name: 'M Team');
    $this->merchantHead = cvUser('merchant', head: true, name: 'M Head');
    $this->buyer->forceFill(['department_admin_id' => $this->merchantHead->id])->save();

    $this->pra = cvPra($this->creator, $this->buyer);
});

// --- Loose end 1: correct status for a departmental PRA ---------------------

it('shows the creator which step their PRA is on, not "not sent for approval"', function () {
    $html = $this->actingAs($this->creator)
        ->get(route('supply_chain.payment_requests.show', $this->pra))
        ->assertOk()->getContent();

    expect($html)->toContain('Approval Progress')
        ->toContain('Merchant Team Check')
        // The exact misreport this fixes.
        ->not->toContain('Not sent for approval');
});

it('shows every step of the chain with what has happened so far', function () {
    app(PraWorkflow::class)->approve($this->pra, $this->merchantTeam, 'Rates checked.');

    $html = $this->actingAs($this->creator)
        ->get(route('supply_chain.payment_requests.show', $this->pra->fresh()))
        ->assertOk()->getContent();

    expect($html)->toContain('Rates checked.')
        ->toContain('M Team')
        ->toContain('Commercial Head Completion');
});

it('reports the step in flowProgress without disturbing approvalProgress', function () {
    $progress = $this->pra->flowProgress();

    expect($progress['has_flow'])->toBeTrue()
        ->and($progress['label'])->toBe('Step 2 — Merchant Team Check')
        ->and($progress['state'])->toBe(PaymentRequest::STATUS_PENDING_CHECK);
});

it('gives a legacy PRA the identical array from flowProgress and approvalProgress', function () {
    // THE ISOLATION CHECK for the wrapper: flow 1 must travel the same path and
    // get the same answer, byte for byte.
    $approver = cvUser('management', name: 'Mgmt');
    $legacy = cvPra($this->creator, $this->buyer, departmental: false);
    $legacy->update(['status' => PaymentRequest::STATUS_PENDING_APPROVAL]);
    PraApproval::create([
        'payment_request_id' => $legacy->id, 'approver_id' => $approver->id,
        'cycle' => 1, 'stage' => PraApproval::STAGE_APPROVE, 'status' => PraApproval::STATUS_PENDING,
    ]);
    $legacy = $legacy->fresh()->load('approvals');

    expect($legacy->flowProgress())->toBe($legacy->approvalProgress());
});

it('lists a brand-new departmental PRA on the creator My PRA Status page', function () {
    // It has no approval rows yet, and whereHas('approvals') — the flow-1 test
    // for "was it sent" — would have hidden it from its own creator.
    $this->actingAs($this->creator)
        ->get(route('supply_chain.payment_requests.my_status'))
        ->assertOk()
        ->assertSee($this->pra->request_no);
});

it('shows the closed cycle after a rejection so the creator sees who approved', function () {
    $workflow = app(PraWorkflow::class);
    $workflow->approve($this->pra, $this->merchantTeam, 'Checked.');
    $workflow->reject($this->pra->fresh(), $this->merchantHead, 'Wrong supplier.');

    $html = $this->actingAs($this->creator)
        ->get(route('supply_chain.payment_requests.show', $this->pra->fresh()))
        ->assertOk()->getContent();

    expect($html)->toContain('Checked.')
        ->toContain('Wrong supplier.');
});

// --- Loose end 2: no dead picker fields --------------------------------------

it('does not offer checker or approver pickers on the departmental create screen', function () {
    // THE CREATE SCREEN IS THE PREVIEW ROUTE, not show() — the "Create PRA"
    // modal that carries these fields only renders there. The pool is
    // deliberately populated: the controls must be absent because this flow does
    // not read them, not because there was nobody to list.
    $pooled = cvUser('management', name: 'Pooled');
    PraApprover::create(['user_id' => $pooled->id, 'is_active' => true, 'can_check' => true]);

    // STRUCTURAL, NOT RENDERED, and worth saying why. Reaching the preview
    // route needs a BookingPo whose BOM row carries PI-received / payment-pending
    // cells against seeded headers — a fixture several times the size of the
    // thing under test, and one that would fail for reasons unrelated to it.
    // This asserts the template shape instead: both fields sit inside the
    // legacy @else, and the notice inside the departmental branch. It catches
    // the regression that matters (a field escaping back out of the @else)
    // without pretending to be an end-to-end check — the rendered screen is
    // verified in the browser.
    $blade = file_get_contents(resource_path('views/supply-chain/payment-requests/show.blade.php'));

    $departmentalBranch = str($blade)->after('@if($createOnDepartmentalFlow ?? false)')->before('@else')->toString();
    $legacyBranch = str($blade)->after('@if($createOnDepartmentalFlow ?? false)')->after('@else')->toString();

    expect($departmentalBranch)->toContain('Reviewers are assigned automatically')
        ->and($departmentalBranch)->not->toContain('name="checker_id"')
        ->and($departmentalBranch)->not->toContain('name="approver_ids[]"')
        // The legacy controls still exist for flow-1 resubmission — they moved,
        // they were not deleted.
        ->and($legacyBranch)->toContain('name="checker_id"')
        ->and($legacyBranch)->toContain('name="approver_ids[]"');
});
