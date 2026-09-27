<?php

use App\Exceptions\PraWorkflowException;
use App\Models\Buyer;
use App\Models\ExcelFile;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestItem;
use App\Models\PraApproval;
use App\Models\User;
use App\Services\PraStageResolver;
use App\Services\PraWorkflow;
use App\Support\BuyerScope;
use Spatie\Permission\Models\Role;

/**
 * THE 6-STEP DEPARTMENTAL CHAIN, driven end to end.
 *
 * Supply Chain creates -> Merchant Team checks -> Merchant Head approves ->
 * Accounts Team checks -> Accounts Head approves -> Commercial Head completes.
 *
 * The cast below mirrors PraWorkflowDemoUsersSeeder exactly, so what these tests
 * exercise is the same shape a person can log in and click through.
 */
function wfUser(string $role, bool $head = false, string $name = 'X'): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['is_department_admin' => $head, 'name' => $name]);
    $user->assignRole($role);

    return $user->fresh();
}

function wfConfine(User $user, Buyer $buyer): User
{
    $user->buyer_scope_mode = BuyerScope::MODE_SPECIFIC;
    $user->save();
    $user->buyers()->sync([$buyer->id]);

    return $user->fresh();
}

function wfPra(?Buyer $buyer, array $overrides = []): PaymentRequest
{
    $pr = PaymentRequest::create(array_merge([
        'request_no' => 'PRA-' . fake()->unique()->numerify('#####'),
        'status' => 'draft',
    ], $overrides));

    $file = ExcelFile::create([
        'file_name' => 'bom.xlsx',
        'original_file_name' => 'bom.xlsx',
        'file_path' => 'bom.xlsx',
        'uploaded_by' => User::factory()->create()->id,
        'buyer_id' => $buyer?->id,
        'total_rows' => 1,
    ]);

    PaymentRequestItem::create(['payment_request_id' => $pr->id, 'excel_file_id' => $file->id]);

    return $pr->fresh();
}

beforeEach(function () {
    $this->workflow = app(PraWorkflow::class);
    $this->buyer = Buyer::create(['buyer_name' => 'Hugo Boss']);

    // The full cast, one per post.
    $this->merchantTeam = wfConfine(wfUser('merchant', name: 'Merchant Team'), $this->buyer);
    $this->merchantHead = wfUser('merchant', head: true, name: 'Merchant Head');
    $this->accountsTeam = wfUser('account', name: 'Accounts Team');
    $this->accountsHead = wfUser('account', head: true, name: 'Accounts Head');
    $this->commercialHead = wfUser('commercial', head: true, name: 'Commercial Head');

    $this->buyer->forceFill(['department_admin_id' => $this->merchantHead->id])->save();

    $this->pra = $this->workflow->start(wfPra($this->buyer));
});

// --- The happy path, all six steps ------------------------------------------

it('walks a PRA through all six steps to completion', function () {
    expect($this->pra->flow_version)->toBe(PaymentRequest::FLOW_DEPARTMENTAL)
        ->and($this->pra->current_sequence)->toBe(2)
        ->and($this->pra->status)->toBe(PaymentRequest::STATUS_PENDING_CHECK);

    // 2 — Merchant Team checks.
    $pra = $this->workflow->approve($this->pra, $this->merchantTeam, 'Rates verified.');
    expect($pra->current_sequence)->toBe(3)
        ->and($pra->status)->toBe(PaymentRequest::STATUS_PENDING_APPROVAL);

    // 3 — Merchant Head approves.
    $pra = $this->workflow->approve($pra, $this->merchantHead);
    expect($pra->current_sequence)->toBe(4)
        ->and($pra->status)->toBe(PaymentRequest::STATUS_PENDING_CHECK);

    // 4 — Accounts Team checks.
    $pra = $this->workflow->approve($pra, $this->accountsTeam);
    expect($pra->current_sequence)->toBe(5)
        ->and($pra->status)->toBe(PaymentRequest::STATUS_PENDING_APPROVAL);

    // 5 — Accounts Head approves.
    $pra = $this->workflow->approve($pra, $this->accountsHead);
    expect($pra->current_sequence)->toBe(6)
        ->and($pra->status)->toBe(PaymentRequest::STATUS_PENDING_COMPLETION);

    // 6 — Commercial Head completes. The chain ends in a payment.
    $pra = $this->workflow->approve($pra, $this->commercialHead, 'Paid.');
    expect($pra->status)->toBe(PaymentRequest::STATUS_COMPLETED)
        ->and($pra->current_sequence)->toBeNull()
        ->and($this->workflow->currentStage($pra))->toBeNull();
});

it('writes one audit row per step, in order, naming who and where', function () {
    foreach ([$this->merchantTeam, $this->merchantHead, $this->accountsTeam, $this->accountsHead, $this->commercialHead] as $actor) {
        $this->pra = $this->workflow->approve($this->pra->fresh(), $actor);
    }

    $rows = $this->workflow->currentCycleDecisions($this->pra->fresh());

    expect($rows)->toHaveCount(5)
        ->and($rows->pluck('sequence')->all())->toBe([2, 3, 4, 5, 6])
        ->and($rows->pluck('department')->all())
            ->toBe(['merchandising', 'merchandising', 'accounts', 'accounts', 'commercial'])
        ->and($rows->pluck('actor_kind')->all())
            ->toBe(['team', 'head', 'team', 'head', 'head'])
        ->and($rows->pluck('stage')->all())
            ->toBe(['check', 'approve', 'check', 'approve', 'complete'])
        ->and($rows->every(fn (PraApproval $r) => $r->isApproved()))->toBeTrue()
        ->and($rows->pluck('cycle')->unique()->all())->toBe([1]);
});

it('fills the legacy actor columns so existing screens still read true', function () {
    $pra = $this->workflow->approve($this->pra, $this->merchantTeam);
    expect($pra->checked_by)->toBe($this->merchantTeam->id);

    $pra = $this->workflow->approve($pra, $this->merchantHead);
    $pra = $this->workflow->approve($pra, $this->accountsTeam);
    $pra = $this->workflow->approve($pra, $this->accountsHead);

    // The final APPROVAL is step 5. Step 6 is a payment and claims neither
    // column, deliberately.
    expect($pra->approved_by)->toBe($this->accountsHead->id);
});

// --- Who may act -------------------------------------------------------------

it('refuses someone who is not on the current step', function () {
    expect(fn () => $this->workflow->approve($this->pra, $this->accountsHead))
        ->toThrow(PraWorkflowException::class, 'not the assigned approver');

    // And nothing was written.
    expect($this->pra->fresh()->current_sequence)->toBe(2)
        ->and(PraApproval::count())->toBe(0);
});

it('refuses a merchant from another buyer', function () {
    $other = wfConfine(wfUser('merchant', name: 'Other buyer'), Buyer::create(['buyer_name' => 'MACYS']));

    expect(fn () => $this->workflow->approve($this->pra, $other))
        ->toThrow(PraWorkflowException::class);
});

it('lets any one of several heads carry the step alone', function () {
    // Q3 in practice: three Accounts Heads, one acts, the step is done.
    $second = wfUser('account', head: true, name: 'Second Head');
    wfUser('account', head: true, name: 'Third Head');

    $pra = $this->workflow->approve($this->pra, $this->merchantTeam);
    $pra = $this->workflow->approve($pra, $this->merchantHead);
    $pra = $this->workflow->approve($pra, $this->accountsTeam);

    $pra = $this->workflow->approve($pra, $second);

    expect($pra->current_sequence)->toBe(6)
        ->and($this->workflow->currentCycleDecisions($pra)->firstWhere('sequence', 5)->approver_id)
            ->toBe($second->id);

    // The others find nothing waiting for them.
    expect(fn () => $this->workflow->approve($pra, $this->accountsHead))
        ->toThrow(PraWorkflowException::class);
});

// --- The configuration gap ---------------------------------------------------

it('blocks a step whose post nobody holds, naming the fix', function () {
    // Commercial Head removed after the PRA was already moving.
    $this->commercialHead->forceFill(['is_department_admin' => false])->save();

    $pra = $this->pra;
    foreach ([$this->merchantTeam, $this->merchantHead, $this->accountsTeam, $this->accountsHead] as $actor) {
        $pra = $this->workflow->approve($pra, $actor);
    }

    expect($pra->current_sequence)->toBe(6)
        ->and($this->workflow->currentStage($pra)->isActionable())->toBeFalse();

    // The PRA advanced to step 6 and waits there visibly, rather than skipping
    // it or quietly finishing.
    expect(fn () => $this->workflow->approve($pra, $this->commercialHead))
        ->toThrow(PraWorkflowException::class, 'no Commercial Head is configured');

    expect($pra->fresh()->status)->toBe(PaymentRequest::STATUS_PENDING_COMPLETION);
});

// --- Reject and resubmit -----------------------------------------------------

it('sends a rejected PRA back to the creator and keeps earlier approvals', function (int $rejectAt) {
    $actors = [
        2 => $this->merchantTeam, 3 => $this->merchantHead,
        4 => $this->accountsTeam, 5 => $this->accountsHead, 6 => $this->commercialHead,
    ];

    $pra = $this->pra;
    foreach ($actors as $seq => $actor) {
        if ($seq === $rejectAt) {
            $pra = $this->workflow->reject($pra, $actor, 'Amount does not match the PI.');
            break;
        }
        $pra = $this->workflow->approve($pra, $actor);
    }

    expect($pra->status)->toBe(PaymentRequest::STATUS_REJECTED)
        ->and($pra->current_sequence)->toBeNull()
        ->and($pra->approved_by)->toBeNull();

    // Q2 answer (c): every step that had already passed is still on record.
    $rows = $pra->fresh()->approvals->where('cycle', 1);
    $approvedSteps = $rows->where('status', PraApproval::STATUS_APPROVED)->pluck('sequence')->sort()->values()->all();
    $expected = array_values(array_filter(array_keys($actors), fn ($s) => $s < $rejectAt));

    expect($approvedSteps)->toBe($expected)
        ->and($rows->firstWhere('status', PraApproval::STATUS_REJECTED)->sequence)->toBe($rejectAt);
})->with([2, 3, 4, 5, 6]);

it('opens a new cycle on resubmit, leaving the old one intact', function () {
    $pra = $this->workflow->approve($this->pra, $this->merchantTeam);
    $pra = $this->workflow->reject($pra, $this->merchantHead, 'Wrong supplier.');

    $pra = $this->workflow->resubmit($pra);

    expect($pra->status)->toBe(PaymentRequest::STATUS_PENDING_CHECK)
        ->and($pra->current_sequence)->toBe(2)
        ->and($this->workflow->activeCycle($pra))->toBe(2);

    $pra = $this->workflow->approve($pra, $this->merchantTeam, 'Supplier corrected.');

    $all = $pra->fresh()->approvals;
    expect($all)->toHaveCount(3)
        // Cycle 1's two rows survive untouched beside cycle 2's new one.
        ->and($all->where('cycle', 1))->toHaveCount(2)
        ->and($all->where('cycle', 2))->toHaveCount(1)
        ->and($this->workflow->currentCycleDecisions($pra)->pluck('cycle')->unique()->all())->toBe([2]);
});

it('refuses to resubmit a PRA that was not rejected', function () {
    expect(fn () => $this->workflow->resubmit($this->pra))
        ->toThrow(PraWorkflowException::class, 'only be resubmitted after it has been rejected');
});

it('refuses any action on a finished PRA', function () {
    $pra = $this->pra;
    foreach ([$this->merchantTeam, $this->merchantHead, $this->accountsTeam, $this->accountsHead, $this->commercialHead] as $actor) {
        $pra = $this->workflow->approve($pra, $actor);
    }

    expect(fn () => $this->workflow->approve($pra, $this->commercialHead))
        ->toThrow(PraWorkflowException::class, 'not waiting on any approval step');
});

// --- Untagged PRA ------------------------------------------------------------

it('routes an untagged PRA to the whole merchant department', function () {
    // Kept deliberately wide rather than blocking — see NOTE_UNTAGGED_PRA.
    $pra = $this->workflow->start(wfPra(null));
    $unconfined = wfUser('merchant', name: 'Any merchant');

    $pra = $this->workflow->approve($pra, $unconfined);

    expect($pra->current_sequence)->toBe(3);
});

// --- Status mapping ----------------------------------------------------------

it('maps each step to the status a waiting PRA holds', function () {
    expect($this->workflow->statusFor(2))->toBe(PaymentRequest::STATUS_PENDING_CHECK)
        ->and($this->workflow->statusFor(3))->toBe(PaymentRequest::STATUS_PENDING_APPROVAL)
        ->and($this->workflow->statusFor(4))->toBe(PaymentRequest::STATUS_PENDING_CHECK)
        ->and($this->workflow->statusFor(5))->toBe(PaymentRequest::STATUS_PENDING_APPROVAL)
        ->and($this->workflow->statusFor(6))->toBe(PaymentRequest::STATUS_PENDING_COMPLETION);
});
