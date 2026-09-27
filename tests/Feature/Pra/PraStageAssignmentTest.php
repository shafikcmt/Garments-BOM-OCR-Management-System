<?php

use App\Exceptions\PraWorkflowException;
use App\Models\Buyer;
use App\Models\ExcelFile;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestItem;
use App\Models\PraApproval;
use App\Models\PraStageAssignment;
use App\Models\User;
use App\Services\PraStageResolver;
use App\Services\PraWorkflow;
use App\Support\BuyerScope;
use Spatie\Permission\Models\Role;

/**
 * THE PRIMARY ASSIGNEE — the creator's optional, informational override.
 *
 * The contract under test, in one sentence: pointing a step at somebody adds
 * information to that step and never takes an actor away from it. The
 * resolver's eligible set is the same with or without an assignment, anyone in
 * it can still act, and the decision row remembers who had been named.
 *
 * "Assigned but not acted" and "acted" are different tables: an assignment is a
 * pra_stage_assignments row, a decision is a pra_approvals row. There is no
 * status value to misread — the two states cannot be confused because they are
 * not the same kind of record.
 */
function asgUser(string $role, bool $head = false, string $name = 'X'): User
{
    Role::findOrCreate($role, 'web');

    $user = User::factory()->create(['is_department_admin' => $head, 'name' => $name]);
    $user->assignRole($role);

    return $user->fresh();
}

function asgConfine(User $user, Buyer $buyer): User
{
    $user->buyer_scope_mode = BuyerScope::MODE_SPECIFIC;
    $user->save();
    $user->buyers()->sync([$buyer->id]);

    return $user->fresh();
}

function asgPra(?Buyer $buyer): PaymentRequest
{
    $pr = PaymentRequest::create([
        'request_no' => 'PRA-' . fake()->unique()->numerify('#####'),
        'status' => 'draft',
    ]);

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
    $this->resolver = app(PraStageResolver::class);
    $this->buyer = Buyer::create(['buyer_name' => 'Hugo Boss']);

    // TWO merchant team members on the buyer, so step 2 has a real choice —
    // and a second head, so step 3 has one too.
    $this->creator = asgUser('supply_chain', name: 'Creator');
    $this->merchantA = asgConfine(asgUser('merchant', name: 'Merchant A'), $this->buyer);
    $this->merchantB = asgConfine(asgUser('merchant', name: 'Merchant B'), $this->buyer);
    $this->merchantHead = asgUser('merchant', head: true, name: 'Merchant Head');
    $this->accountsTeam = asgUser('account', name: 'Accounts Team');
    $this->accountsHead = asgUser('account', head: true, name: 'Accounts Head');
    $this->commercialHead = asgUser('commercial', head: true, name: 'Commercial Head');

    $this->buyer->forceFill(['department_admin_id' => $this->merchantHead->id])->save();

    $this->pra = $this->workflow->start(asgPra($this->buyer));
});

// --- Proof 1: the three states are unambiguous ------------------------------

it('has no assignment and no decision on a PRA nobody has touched', function () {
    $stage = $this->workflow->currentStage($this->pra);

    expect(PraStageAssignment::count())->toBe(0)
        ->and(PraApproval::count())->toBe(0)
        ->and($stage->assignee)->toBeNull()
        ->and($stage->unavailableAssignee)->toBeNull()
        ->and($stage->namedAssignee())->toBeNull();
});

it('records "assigned but not acted" as an assignment row and NO decision row', function () {
    $this->workflow->assign($this->pra, [2 => $this->merchantB->id], $this->creator);

    $stage = $this->workflow->currentStage($this->pra->fresh());

    expect(PraStageAssignment::count())->toBe(1)
        ->and(PraApproval::count())->toBe(0)
        ->and($stage->assignee?->id)->toBe($this->merchantB->id)
        ->and($this->pra->fresh()->current_sequence)->toBe(2);

    $row = PraStageAssignment::first();
    expect($row->payment_request_id)->toBe($this->pra->id)
        ->and($row->sequence)->toBe(2)
        ->and($row->user_id)->toBe($this->merchantB->id)
        ->and($row->assigned_by)->toBe($this->creator->id);
});

it('records "acted" as a decision row that snapshots who had been named', function () {
    $this->workflow->assign($this->pra, [2 => $this->merchantB->id], $this->creator);
    $this->workflow->approve($this->pra->fresh(), $this->merchantB, 'Checked.');

    $decision = PraApproval::sole();

    expect($decision->approver_id)->toBe($this->merchantB->id)
        ->and($decision->assigned_user_id)->toBe($this->merchantB->id)
        ->and($decision->acted_at)->not->toBeNull()
        ->and($decision->status)->toBe(PraApproval::STATUS_APPROVED);
});

// --- Proof 2: assigned -> acted, no duplicate, nothing lost ----------------

it('moves from assigned to acted with exactly one decision row and the assignment intact', function () {
    $this->workflow->assign($this->pra, [2 => $this->merchantB->id], $this->creator);

    $pra = $this->workflow->approve($this->pra->fresh(), $this->merchantB);

    expect(PraApproval::count())->toBe(1)
        ->and(PraStageAssignment::count())->toBe(1)
        ->and(PraStageAssignment::first()->user_id)->toBe($this->merchantB->id)
        ->and($pra->current_sequence)->toBe(3);
});

it('keeps the named assignee on the decision row even when somebody else acted', function () {
    $this->workflow->assign($this->pra, [2 => $this->merchantB->id], $this->creator);

    // Merchant A carries the step. Allowed — see Proof 3 — and the row must
    // still say it had been B's.
    $this->workflow->approve($this->pra->fresh(), $this->merchantA);

    $decision = PraApproval::sole();

    expect($decision->approver_id)->toBe($this->merchantA->id)
        ->and($decision->assigned_user_id)->toBe($this->merchantB->id)
        ->and(PraApproval::count())->toBe(1);
});

it('reassigning replaces the row rather than adding a second one', function () {
    $this->workflow->assign($this->pra, [2 => $this->merchantA->id], $this->creator);
    $this->workflow->assign($this->pra->fresh(), [2 => $this->merchantB->id], $this->creator);

    expect(PraStageAssignment::count())->toBe(1)
        ->and(PraStageAssignment::first()->user_id)->toBe($this->merchantB->id);
});

it('clears a step back to automatic with null', function () {
    $this->workflow->assign($this->pra, [2 => $this->merchantB->id], $this->creator);
    $this->workflow->assign($this->pra->fresh(), [2 => null], $this->creator);

    expect(PraStageAssignment::count())->toBe(0)
        ->and($this->workflow->currentStage($this->pra->fresh())->assignee)->toBeNull();
});

it('leaves steps it was not asked about alone', function () {
    $this->workflow->assign($this->pra, [2 => $this->merchantB->id, 4 => $this->accountsTeam->id], $this->creator);
    $this->workflow->assign($this->pra->fresh(), [2 => null], $this->creator);

    expect(PraStageAssignment::pluck('sequence')->all())->toBe([4]);
});

// --- Proof 3: team-wide visibility — the eligible set is never narrowed -----

it('returns the same eligible set with and without an assignment', function () {
    $before = $this->workflow->currentStage($this->pra)->userIds();

    $this->workflow->assign($this->pra, [2 => $this->merchantB->id], $this->creator);
    $after = $this->workflow->currentStage($this->pra->fresh());

    expect($after->userIds())->toBe($before)
        ->and($after->users->count())->toBe(2)
        ->and($after->includes($this->merchantA))->toBeTrue()
        ->and($after->includes($this->merchantB))->toBeTrue()
        ->and($after->assignee?->id)->toBe($this->merchantB->id);
});

it('puts the assignee first for display without changing who is listed', function () {
    // Name order puts A before B; the assignment must move B in front.
    $this->workflow->assign($this->pra, [2 => $this->merchantB->id], $this->creator);
    $stage = $this->workflow->currentStage($this->pra->fresh());

    expect($stage->users->pluck('id')->all())->toBe([$this->merchantA->id, $this->merchantB->id])
        ->and($stage->usersAssigneeFirst()->pluck('id')->all())->toBe([$this->merchantB->id, $this->merchantA->id])
        ->and($stage->isAssignee($this->merchantB))->toBeTrue()
        ->and($stage->isAssignee($this->merchantA))->toBeFalse();
});

it('lets any other eligible team member act on an assigned step', function () {
    $this->workflow->assign($this->pra, [2 => $this->merchantB->id], $this->creator);
    $pra = $this->pra->fresh();

    expect($this->resolver->mayAct($pra, 2, $this->merchantA))->toBeTrue();

    $pra = $this->workflow->approve($pra, $this->merchantA);

    expect($pra->current_sequence)->toBe(3);
});

it('does not let an assignment grant access to someone outside the eligible set', function () {
    // Accounts Team is not a merchant. Naming them for step 2 is refused
    // outright, and nothing is written.
    expect(fn () => $this->workflow->assign($this->pra, [2 => $this->accountsTeam->id], $this->creator))
        ->toThrow(PraWorkflowException::class, 'not eligible');

    expect(PraStageAssignment::count())->toBe(0)
        ->and($this->resolver->mayAct($this->pra->fresh(), 2, $this->accountsTeam))->toBeFalse();
});

it('refuses the whole call when one of several assignments is ineligible', function () {
    expect(fn () => $this->workflow->assign($this->pra, [
        2 => $this->merchantB->id,
        3 => $this->accountsHead->id, // wrong department for step 3
    ], $this->creator))->toThrow(PraWorkflowException::class);

    expect(PraStageAssignment::count())->toBe(0);
});

it('falls back to the full team when the assignee is no longer eligible, and says so', function () {
    $this->workflow->assign($this->pra, [2 => $this->merchantB->id], $this->creator);

    // B loses access to the buyer after being named.
    $this->merchantB->buyers()->sync([]);

    $stage = $this->workflow->currentStage($this->pra->fresh());

    expect($stage->assignee)->toBeNull()
        ->and($stage->unavailableAssignee?->id)->toBe($this->merchantB->id)
        ->and($stage->userIds())->toBe([$this->merchantA->id])
        ->and($stage->isActionable())->toBeTrue();

    // And the decision still records who had been named.
    $this->workflow->approve($this->pra->fresh(), $this->merchantA);
    expect(PraApproval::sole()->assigned_user_id)->toBe($this->merchantB->id);
});

// --- The workflow never assigns on its own --------------------------------

it('writes no assignment row anywhere in an unassisted walk through all six steps', function () {
    foreach ([$this->merchantA, $this->merchantHead, $this->accountsTeam, $this->accountsHead, $this->commercialHead] as $actor) {
        $this->pra = $this->workflow->approve($this->pra->fresh(), $actor);
    }

    expect($this->pra->status)->toBe(PaymentRequest::STATUS_COMPLETED)
        ->and(PraStageAssignment::count())->toBe(0)
        ->and(PraApproval::whereNotNull('assigned_user_id')->count())->toBe(0);
});

it('carries an assignment across a reject and resubmit, and snapshots it in the new cycle', function () {
    $this->workflow->assign($this->pra, [3 => $this->merchantHead->id], $this->creator);

    $pra = $this->workflow->approve($this->pra->fresh(), $this->merchantA);
    $pra = $this->workflow->reject($pra, $this->merchantHead, 'Wrong rates.');
    $pra = $this->workflow->resubmit($pra);
    $pra = $this->workflow->approve($pra, $this->merchantA);

    expect(PraStageAssignment::count())->toBe(1)
        ->and($this->workflow->currentStage($pra)->assignee?->id)->toBe($this->merchantHead->id);

    $pra = $this->workflow->approve($pra, $this->merchantHead);

    $cycle2 = PraApproval::where('cycle', 2)->where('sequence', 3)->sole();
    expect($cycle2->assigned_user_id)->toBe($this->merchantHead->id);
});

// --- Legacy isolation --------------------------------------------------------

it('refuses to assign anything on a legacy PRA and writes nothing', function () {
    $legacy = asgPra($this->buyer); // flow_version 1 by default

    expect(fn () => $this->workflow->assign($legacy, [2 => $this->merchantA->id], $this->creator))
        ->toThrow(PraWorkflowException::class, 'not on the departmental');

    expect(PraStageAssignment::count())->toBe(0)
        ->and($legacy->fresh()->flow_version)->toBe(PaymentRequest::FLOW_LEGACY);
});

// --- The resolver extraction is a pure move --------------------------------

it('gives the same answer through resolveForBuyers() as through resolve()', function () {
    $buyerIds = $this->resolver->buyerIdsFor($this->pra);

    foreach ($this->resolver->sequences() as $sequence) {
        $viaPra = $this->resolver->resolve($this->pra, $sequence);
        $viaIds = $this->resolver->resolveForBuyers($sequence, $buyerIds);

        expect($viaIds->userIds())->toBe($viaPra->userIds(), "step {$sequence}")
            ->and($viaIds->buyerIds)->toBe($viaPra->buyerIds)
            ->and($viaIds->buyerScoped)->toBe($viaPra->buyerScoped)
            ->and($viaIds->note)->toBe($viaPra->note)
            ->and($viaIds->assignee)->toBeNull()
            ->and($viaPra->assignee)->toBeNull();
    }
});

it('never attaches an assignee at the resolver level, even when one exists', function () {
    $this->workflow->assign($this->pra, [2 => $this->merchantB->id], $this->creator);

    $fromResolver = $this->resolver->resolve($this->pra->fresh(), 2);
    $fromWorkflow = $this->workflow->stage($this->pra->fresh(), 2);

    expect($fromResolver->assignee)->toBeNull()
        ->and($fromWorkflow->assignee?->id)->toBe($this->merchantB->id)
        ->and($fromWorkflow->userIds())->toBe($fromResolver->userIds());
});
