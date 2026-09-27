<?php

use App\Exceptions\PraWorkflowException;
use App\Models\PaymentRequest;
use App\Models\PraApproval;
use App\Models\PraApprover;
use App\Models\User;
use App\Services\PraWorkflow;
use Spatie\Permission\Models\Role;

/**
 * THE GUARANTEE THIS WHOLE FEATURE RESTS ON: a legacy PRA is untouched.
 *
 * Nineteen real PRAs exist on the live database, all flow_version 1, six of them
 * carrying approval rows. None of them asked for a departmental chain and none
 * may be dragged into one. This file is the proof, and it is deliberately
 * paranoid — it asserts not just that the new machine refuses them, but that
 * refusing writes nothing, that the legacy screens still work end to end, and
 * that the legacy state machine still reaches every one of its own outcomes.
 *
 * If a change ever makes one of these fail, the change is wrong. There is no
 * reading of "we improved the legacy flow" that is worth a surprised accountant.
 */
function legacyApprover(string $name = 'Approver'): User
{
    Role::findOrCreate('management', 'web');

    $user = User::factory()->create(['name' => $name]);
    $user->assignRole('management');
    $user->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('approve-pra', 'web'));
    PraApprover::create(['user_id' => $user->id, 'is_active' => true, 'can_check' => true]);

    return $user->fresh();
}

/** A legacy PRA shaped exactly like the ones already in production. */
function legacyPra(array $overrides = []): PaymentRequest
{
    return PaymentRequest::create(array_merge([
        'request_no' => 'PRA-OLD-' . fake()->unique()->numerify('#####'),
        'status' => PaymentRequest::STATUS_PENDING_APPROVAL,
    ], $overrides));
}

function legacyRow(PaymentRequest $pra, User $approver, string $stage = PraApproval::STAGE_APPROVE): PraApproval
{
    return PraApproval::create([
        'payment_request_id' => $pra->id,
        'approver_id' => $approver->id,
        'cycle' => 1,
        'stage' => $stage,
        'status' => PraApproval::STATUS_PENDING,
    ]);
}

beforeEach(function () {
    $this->workflow = app(PraWorkflow::class);
});

// --- A legacy PRA is version 1 and stays that way ---------------------------

it('creates a PRA on the legacy flow by default', function () {
    // Nothing in phases 1-3 changed what an ordinary create produces. The
    // column defaults to 1 and no code path flips it without being asked.
    $pra = legacyPra();

    expect($pra->flow_version)->toBe(PaymentRequest::FLOW_LEGACY)
        ->and($pra->current_sequence)->toBeNull()
        ->and($pra->isDepartmentalFlow())->toBeFalse();
});

it('leaves legacy approval rows with no sequence, kind or department', function () {
    $pra = legacyPra();
    $row = legacyRow($pra, legacyApprover());

    expect($row->fresh()->sequence)->toBe(PraApproval::SEQUENCE_LEGACY)
        ->and($row->fresh()->actor_kind)->toBeNull()
        ->and($row->fresh()->department)->toBeNull();
});

// --- The new machine refuses them, and writes nothing doing so --------------

it('refuses to advance a legacy PRA', function () {
    $pra = legacyPra();
    $approver = legacyApprover();
    legacyRow($pra, $approver);

    expect(fn () => $this->workflow->approve($pra, $approver))
        ->toThrow(PraWorkflowException::class, 'not on the departmental approval flow');
});

it('refuses to reject a legacy PRA', function () {
    $pra = legacyPra();
    $approver = legacyApprover();

    expect(fn () => $this->workflow->reject($pra, $approver, 'no'))
        ->toThrow(PraWorkflowException::class, 'not on the departmental approval flow');
});

it('refuses to resubmit a legacy PRA', function () {
    $pra = legacyPra(['status' => PaymentRequest::STATUS_REJECTED]);

    expect(fn () => $this->workflow->resubmit($pra))
        ->toThrow(PraWorkflowException::class, 'not on the departmental approval flow');
});

it('changes not one byte of a legacy PRA when it refuses', function () {
    // The guard is the FIRST statement in every mutator, so a refusal cannot
    // half-apply. This pins that rather than trusting the reading.
    $pra = legacyPra();
    $approver = legacyApprover();
    legacyRow($pra, $approver);

    $before = $pra->fresh()->getAttributes();
    $rowsBefore = PraApproval::orderBy('id')->get()->map->getAttributes()->toArray();

    foreach ([
        fn () => $this->workflow->approve($pra, $approver),
        fn () => $this->workflow->reject($pra, $approver, 'reason'),
        fn () => $this->workflow->resubmit($pra),
    ] as $attempt) {
        try {
            $attempt();
        } catch (PraWorkflowException) {
            // expected
        }
    }

    expect($pra->fresh()->getAttributes())->toBe($before)
        ->and(PraApproval::orderBy('id')->get()->map->getAttributes()->toArray())->toBe($rowsBefore);
});

it('returns no departmental stage for a legacy PRA', function () {
    expect($this->workflow->currentStage(legacyPra()))->toBeNull();
});

// --- The legacy machine still works, untouched -------------------------------

it('still runs the legacy check then approve flow to completion', function () {
    // The existing controller path, exercised for real. This is the behaviour
    // the 19 live PRAs depend on.
    $checker = legacyApprover('Checker');
    $approver = legacyApprover('Approver');

    $pra = legacyPra(['status' => PaymentRequest::STATUS_PENDING_CHECK]);
    legacyRow($pra, $checker, PraApproval::STAGE_CHECK);
    legacyRow($pra, $approver, PraApproval::STAGE_APPROVE);

    $this->actingAs($checker)
        ->post(route('pra_approvals.approve', $pra), ['comment' => 'checked'])
        ->assertRedirect();

    expect($pra->fresh()->status)->toBe(PaymentRequest::STATUS_PENDING_APPROVAL)
        ->and($pra->fresh()->checked_by)->toBe($checker->id);

    $this->actingAs($approver)
        ->post(route('pra_approvals.approve', $pra), ['comment' => 'approved'])
        ->assertRedirect();

    $pra = $pra->fresh();

    expect($pra->status)->toBe(PaymentRequest::STATUS_APPROVED)
        ->and($pra->approved_by)->toBe($approver->id)
        // Still 1, still no sequence. The legacy path never learned about them.
        ->and($pra->flow_version)->toBe(PaymentRequest::FLOW_LEGACY)
        ->and($pra->current_sequence)->toBeNull()
        ->and($pra->approvals->pluck('sequence')->unique()->all())->toBe([PraApproval::SEQUENCE_LEGACY]);
});

it('still rejects the whole legacy PRA on a single rejection', function () {
    // The legacy rule is terminal-on-first-reject, which is NOT what flow 2
    // does. Both remain true, each on its own flow.
    $a = legacyApprover('A');
    $b = legacyApprover('B');

    $pra = legacyPra();
    legacyRow($pra, $a);
    legacyRow($pra, $b);

    $this->actingAs($a)
        ->post(route('pra_approvals.reject', $pra), ['comment' => 'Wrong amount.'])
        ->assertRedirect();

    expect($pra->fresh()->status)->toBe(PaymentRequest::STATUS_REJECTED)
        ->and($pra->fresh()->flow_version)->toBe(PaymentRequest::FLOW_LEGACY);
});

it('still requires every legacy approver to approve before finalising', function () {
    // All-must-approve is the legacy semantic. Flow 2's any-one-of must not
    // have leaked into it.
    $a = legacyApprover('A');
    $b = legacyApprover('B');

    $pra = legacyPra();
    legacyRow($pra, $a);
    legacyRow($pra, $b);

    $this->actingAs($a)->post(route('pra_approvals.approve', $pra), [])->assertRedirect();

    expect($pra->fresh()->status)->toBe(PaymentRequest::STATUS_PENDING_APPROVAL);

    $this->actingAs($b)->post(route('pra_approvals.approve', $pra), [])->assertRedirect();

    expect($pra->fresh()->status)->toBe(PaymentRequest::STATUS_APPROVED);
});

it('still reports legacy progress through the untouched model method', function () {
    $a = legacyApprover('A');
    $pra = legacyPra();
    legacyRow($pra, $a);

    $progress = $pra->fresh()->approvalProgress();

    expect($progress['state'])->toBe(PaymentRequest::STATUS_PENDING_APPROVAL)
        ->and($progress['total'])->toBe(1)
        ->and($progress['approved'])->toBe(0)
        ->and($progress['has_flow'])->toBeTrue();
});

it('keeps the legacy approver queue showing legacy PRAs', function () {
    $a = legacyApprover('A');
    $pra = legacyPra();
    legacyRow($pra, $a);

    $this->actingAs($a)
        ->get(route('pra_approvals.index'))
        ->assertOk()
        ->assertSee($pra->request_no);
});
