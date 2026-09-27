<?php

use App\Models\AppNotification;
use App\Models\Buyer;
use App\Models\ExcelFile;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestItem;
use App\Models\PraApproval;
use App\Models\PraApprover;
use App\Models\User;
use App\Services\PraWorkflow;
use App\Support\BuyerScope;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * WHO GETS TOLD, at each step of the departmental chain.
 *
 * The rule that matters most here is a NEGATIVE one: notification fan-out must
 * reach exactly the people who can act, and nobody else. A merchant scoped to
 * another buyer must not be emailed about this PRA — not because the email
 * leaks much on its own, but because an approval request implies authority the
 * recipient does not have, and chasing it wastes their day.
 *
 * That correctness is INHERITED, not re-implemented: notifyStageActors() fans
 * out over PraStageActors::$users, which PraStageResolver has already confined.
 * There is deliberately no second buyer filter in the notifier — a duplicated
 * rule is how the earlier fan-out bug happened.
 */
function pnRole(string $name): Role
{
    return Role::findOrCreate($name, 'web');
}

function pnUser(string $role, bool $head = false, string $name = 'X'): User
{
    pnRole($role);

    $user = User::factory()->create(['is_department_admin' => $head, 'name' => $name]);
    $user->assignRole($role);

    return $user->fresh();
}

function pnConfine(User $user, Buyer $buyer): User
{
    $user->buyer_scope_mode = BuyerScope::MODE_SPECIFIC;
    $user->save();
    $user->buyers()->sync([$buyer->id]);

    return $user->fresh();
}

function pnNotifiedIds(string $type): array
{
    return AppNotification::where('type', $type)->pluck('user_id')
        ->map(fn ($id) => (int) $id)->sort()->values()->all();
}

beforeEach(function () {
    Permission::findOrCreate('pra.departmental.act', 'web');
    foreach (['merchant', 'account', 'commercial', 'admin'] as $role) {
        pnRole($role)->givePermissionTo('pra.departmental.act');
    }

    $this->workflow = app(PraWorkflow::class);
    $this->buyer = Buyer::create(['buyer_name' => 'Hugo Boss']);
    $this->otherBuyer = Buyer::create(['buyer_name' => 'MACYS']);

    $this->creator = pnUser('supply_chain', name: 'Creator');
    $this->merchantTeam = pnConfine(pnUser('merchant', name: 'M Team'), $this->buyer);
    $this->merchantHead = pnUser('merchant', head: true, name: 'M Head');
    $this->accountsTeam = pnUser('account', name: 'A Team');
    $this->accountsHead = pnUser('account', head: true, name: 'A Head');
    $this->commercialHead = pnUser('commercial', head: true, name: 'C Head');
    $this->buyer->forceFill(['department_admin_id' => $this->merchantHead->id])->save();

    $pra = PaymentRequest::create([
        'request_no' => 'PRA-' . fake()->unique()->numerify('#####'),
        'status' => 'draft',
        'created_by' => $this->creator->id,
    ]);
    $file = ExcelFile::create([
        'file_name' => 'b.xlsx', 'original_file_name' => 'b.xlsx', 'file_path' => 'b.xlsx',
        'uploaded_by' => $this->creator->id, 'buyer_id' => $this->buyer->id, 'total_rows' => 1,
    ]);
    PaymentRequestItem::create(['payment_request_id' => $pra->id, 'excel_file_id' => $file->id]);

    $this->pra = $this->workflow->start($pra->fresh());
});

// --- Each transition notifies the next actors --------------------------------

it('notifies the next step actors as the PRA advances', function () {
    $this->actingAs($this->merchantTeam)
        ->post(route('pra_departmental.approve', $this->pra))->assertRedirect();

    // Step 3 is the merchant head of THIS buyer.
    expect(pnNotifiedIds('pra_stage_request'))->toBe([$this->merchantHead->id]);

    AppNotification::query()->delete();

    $this->actingAs($this->merchantHead)
        ->post(route('pra_departmental.approve', $this->pra->fresh()))->assertRedirect();

    expect(pnNotifiedIds('pra_stage_request'))->toBe([$this->accountsTeam->id]);
});

it('records the step in the notification so the queue can be reached directly', function () {
    $this->actingAs($this->merchantTeam)
        ->post(route('pra_departmental.approve', $this->pra))->assertRedirect();

    $notification = AppNotification::where('type', 'pra_stage_request')->first();

    expect($notification->data['sequence'])->toBe(3)
        ->and($notification->data['payment_request_id'])->toBe($this->pra->id)
        ->and($notification->url)->toBe(route('pra_departmental.show', $this->pra));
});

it('never notifies a merchant scoped to another buyer', function () {
    // THE FAN-OUT RULE. The set comes from the resolver, so it is confined
    // already — this pins that the notifier did not widen it back out.
    $outsider = pnConfine(pnUser('merchant', name: 'Other buyer'), $this->otherBuyer);

    $this->actingAs($this->merchantTeam)
        ->post(route('pra_departmental.approve', $this->pra))->assertRedirect();

    expect(pnNotifiedIds('pra_stage_request'))->not->toContain($outsider->id);
});

it('notifies every eligible head, since any one of them may act', function () {
    $secondHead = pnUser('account', head: true, name: 'A Head 2');

    foreach ([$this->merchantTeam, $this->merchantHead] as $actor) {
        $this->workflow->approve($this->pra->fresh(), $actor);
    }
    AppNotification::query()->delete();

    $this->actingAs($this->accountsTeam)
        ->post(route('pra_departmental.approve', $this->pra->fresh()))->assertRedirect();

    expect(pnNotifiedIds('pra_stage_request'))
        ->toBe(collect([$this->accountsHead->id, $secondHead->id])->sort()->values()->all());
});

it('notifies nobody when the next step has no one in post', function () {
    // Emailing an empty set is not how a configuration gap gets reported — the
    // PRA parks at the step with problem() on screen instead.
    $this->commercialHead->forceFill(['is_department_admin' => false])->save();

    foreach ([$this->merchantTeam, $this->merchantHead, $this->accountsTeam] as $actor) {
        $this->workflow->approve($this->pra->fresh(), $actor);
    }
    AppNotification::query()->delete();

    $response = $this->actingAs($this->accountsHead)
        ->post(route('pra_departmental.approve', $this->pra->fresh()));

    $response->assertRedirect();
    expect(AppNotification::where('type', 'pra_stage_request')->count())->toBe(0)
        ->and(session('warning'))->toContain('no Commercial Head is configured');
});

// --- Completion --------------------------------------------------------------

it('tells the creator when the chain completes', function () {
    foreach ([$this->merchantTeam, $this->merchantHead, $this->accountsTeam, $this->accountsHead] as $actor) {
        $this->workflow->approve($this->pra->fresh(), $actor);
    }
    AppNotification::query()->delete();

    $this->actingAs($this->commercialHead)
        ->post(route('pra_departmental.approve', $this->pra->fresh()))->assertRedirect();

    expect(pnNotifiedIds('pra_completed'))->toContain($this->creator->id);

    $notification = AppNotification::where('type', 'pra_completed')->first();
    expect($notification->message)->toContain('Payment has been recorded');
});

// --- Rejection ---------------------------------------------------------------

it('tells the creator AND everyone who already approved when it is rejected', function () {
    // Steps 2, 3 and 4 pass; step 5 rejects. The three who signed off have a
    // stake in knowing it turned.
    foreach ([$this->merchantTeam, $this->merchantHead, $this->accountsTeam] as $actor) {
        $this->workflow->approve($this->pra->fresh(), $actor);
    }
    AppNotification::query()->delete();

    $this->actingAs($this->accountsHead)
        ->post(route('pra_departmental.reject', $this->pra->fresh()), ['comment' => 'PI mismatch.'])
        ->assertRedirect();

    expect(pnNotifiedIds('pra_rejected'))->toBe(
        collect([$this->creator->id, $this->merchantTeam->id, $this->merchantHead->id, $this->accountsTeam->id])
            ->sort()->values()->all()
    );
});

it('does not notify the person who just rejected', function () {
    $this->actingAs($this->merchantTeam)
        ->post(route('pra_departmental.reject', $this->pra), ['comment' => 'no'])
        ->assertRedirect();

    expect(pnNotifiedIds('pra_rejected'))->toBe([$this->creator->id]);
});

it('notifies step 2 again on resubmit', function () {
    $this->workflow->reject($this->pra, $this->merchantTeam, 'no');
    AppNotification::query()->delete();

    $this->actingAs($this->creator)
        ->post(route('supply_chain.payment_requests.resubmit', $this->pra->fresh()))
        ->assertRedirect();

    expect($this->pra->fresh()->current_sequence)->toBe(2)
        ->and(pnNotifiedIds('pra_stage_request'))->toBe([$this->merchantTeam->id]);
});

// --- Legacy notifications are untouched --------------------------------------

it('leaves the legacy notification path exactly as it was', function () {
    // The flow-1 approver still gets pra_approval_request, and none of the new
    // types are emitted for a legacy PRA.
    pnRole('management')->givePermissionTo(Permission::findOrCreate('approve-pra', 'web'));
    $approver = pnUser('management', name: 'Mgmt');
    PraApprover::create(['user_id' => $approver->id, 'is_active' => true]);

    $legacy = PaymentRequest::create([
        'request_no' => 'PRA-OLD-' . fake()->unique()->numerify('#####'),
        'status' => PaymentRequest::STATUS_PENDING_APPROVAL,
        'created_by' => $this->creator->id,
    ]);
    PraApproval::create([
        'payment_request_id' => $legacy->id, 'approver_id' => $approver->id,
        'cycle' => 1, 'stage' => PraApproval::STAGE_APPROVE, 'status' => PraApproval::STATUS_PENDING,
    ]);

    AppNotification::query()->delete();

    $this->actingAs($approver)
        ->post(route('pra_approvals.approve', $legacy), ['comment' => 'ok'])
        ->assertRedirect();

    expect($legacy->fresh()->status)->toBe(PaymentRequest::STATUS_APPROVED)
        // The legacy result notification, unchanged.
        ->and(pnNotifiedIds('pra_approved'))->toBe([$this->creator->id])
        // And none of the departmental types.
        ->and(AppNotification::whereIn('type', ['pra_stage_request', 'pra_completed'])->count())->toBe(0);
});
