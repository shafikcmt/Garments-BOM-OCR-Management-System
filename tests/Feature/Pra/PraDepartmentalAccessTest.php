<?php

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
 * WHO CAN REACH WHAT, once the departmental chain is wired to real routes.
 *
 * This is the highest-risk file in the feature. Almost every test here asserts
 * a REFUSAL, because a permission gate that is too loose does not announce
 * itself — the screen simply works for somebody it should not.
 *
 * The shape being defended:
 *
 *   pra.departmental.act   merchant + account + commercial + admin. Outer gate
 *                          only; PraStageResolver narrows it per record.
 *   approve-pra            admin + management, via the pra_approvers pool.
 *                          UNCHANGED, and the three new roles must never hold it.
 *
 * The two flows must not be able to see, let alone touch, each other's records.
 */
function daxRole(string $name): Role
{
    return Role::findOrCreate($name, 'web');
}

function daxUser(string $role, bool $head = false, string $name = 'X'): User
{
    daxRole($role);

    $user = User::factory()->create(['is_department_admin' => $head, 'name' => $name]);
    $user->assignRole($role);

    return $user->fresh();
}

function daxConfine(User $user, Buyer $buyer): User
{
    $user->buyer_scope_mode = BuyerScope::MODE_SPECIFIC;
    $user->save();
    $user->buyers()->sync([$buyer->id]);

    return $user->fresh();
}

function daxPra(?Buyer $buyer): PaymentRequest
{
    $pra = PaymentRequest::create([
        'request_no' => 'PRA-' . fake()->unique()->numerify('#####'),
        'status' => 'draft',
    ]);

    $file = ExcelFile::create([
        'file_name' => 'bom.xlsx', 'original_file_name' => 'bom.xlsx', 'file_path' => 'bom.xlsx',
        'uploaded_by' => User::factory()->create()->id,
        'buyer_id' => $buyer?->id, 'total_rows' => 1,
    ]);

    PaymentRequestItem::create(['payment_request_id' => $pra->id, 'excel_file_id' => $file->id]);

    return app(PraWorkflow::class)->start($pra->fresh());
}

/** A legacy PRA with a pending approver row, exactly as production holds them. */
function daxLegacyPra(User $approver): PaymentRequest
{
    $pra = PaymentRequest::create([
        'request_no' => 'PRA-OLD-' . fake()->unique()->numerify('#####'),
        'status' => PaymentRequest::STATUS_PENDING_APPROVAL,
    ]);

    PraApproval::create([
        'payment_request_id' => $pra->id, 'approver_id' => $approver->id,
        'cycle' => 1, 'stage' => PraApproval::STAGE_APPROVE, 'status' => PraApproval::STATUS_PENDING,
    ]);

    return $pra->fresh();
}

beforeEach(function () {
    // The permission the migration seeds. Tests build it directly so they do not
    // depend on migration ordering.
    $permission = Permission::findOrCreate('pra.departmental.act', 'web');
    foreach (['merchant', 'account', 'commercial', 'admin'] as $role) {
        daxRole($role)->givePermissionTo($permission);
    }
    Permission::findOrCreate('approve-pra', 'web');
    daxRole('management')->givePermissionTo('approve-pra');
    daxRole('admin')->givePermissionTo('approve-pra');

    $this->buyer = Buyer::create(['buyer_name' => 'Hugo Boss']);
    $this->otherBuyer = Buyer::create(['buyer_name' => 'MACYS']);

    $this->merchantTeam = daxConfine(daxUser('merchant', name: 'M Team'), $this->buyer);
    $this->merchantHead = daxUser('merchant', head: true, name: 'M Head');
    $this->accountsTeam = daxUser('account', name: 'A Team');
    $this->accountsHead = daxUser('account', head: true, name: 'A Head');
    $this->commercialHead = daxUser('commercial', head: true, name: 'C Head');
    $this->buyer->forceFill(['department_admin_id' => $this->merchantHead->id])->save();

    $this->pra = daxPra($this->buyer);
    $this->workflow = app(PraWorkflow::class);
});

// --- The permission boundary between the two flows ---------------------------

it('does not give the three departments the legacy approve-pra permission', function (string $role) {
    // THE CENTRAL GUARANTEE OF THIS PHASE. Granting approve-pra instead of a new
    // permission would have handed 19 live hand-routed PRAs to three whole
    // departments.
    expect(daxRole($role)->hasPermissionTo('approve-pra'))->toBeFalse();
})->with(['merchant', 'account', 'commercial']);

it('keeps the three departments out of every legacy PRA route', function (string $role) {
    $user = daxUser($role, name: 'Dept user');
    $management = daxUser('management', name: 'Mgmt');
    $legacy = daxLegacyPra($management);

    $this->actingAs($user)->get(route('pra_approvals.index'))->assertForbidden();
    $this->actingAs($user)->get(route('pra_approvals.show', $legacy))->assertForbidden();
    $this->actingAs($user)->post(route('pra_approvals.approve', $legacy))->assertForbidden();
    $this->actingAs($user)->post(route('pra_approvals.reject', $legacy), ['comment' => 'x'])->assertForbidden();
})->with(['merchant', 'account', 'commercial']);

it('keeps management out of the departmental routes', function () {
    // management holds approve-pra and sees the whole factory. Putting it on
    // both queues would mean one person acting under two rulebooks.
    $management = daxUser('management', name: 'Mgmt');

    $this->actingAs($management)->get(route('pra_departmental.index'))->assertForbidden();
    $this->actingAs($management)->post(route('pra_departmental.approve', $this->pra))->assertForbidden();
});

it('refuses a departmental PRA on the legacy screens even to someone who holds approve-pra', function () {
    // The reverse fence. A management user cannot reach a flow-2 record through
    // the old controller by typing its id.
    $management = daxUser('management', name: 'Mgmt');

    $this->actingAs($management)->get(route('pra_approvals.show', $this->pra))->assertNotFound();
    $this->actingAs($management)->post(route('pra_approvals.approve', $this->pra))->assertNotFound();
    $this->actingAs($management)->post(route('pra_approvals.reject', $this->pra), ['comment' => 'x'])->assertNotFound();
});

it('refuses a legacy PRA on the departmental screens', function () {
    $management = daxUser('management', name: 'Mgmt');
    $legacy = daxLegacyPra($management);

    $this->actingAs($this->merchantTeam)->get(route('pra_departmental.show', $legacy))->assertNotFound();
    $this->actingAs($this->merchantTeam)->post(route('pra_departmental.approve', $legacy))->assertNotFound();
});

it('never lists a legacy PRA in the departmental queue', function () {
    $management = daxUser('management', name: 'Mgmt');
    $legacy = daxLegacyPra($management);

    $this->actingAs($this->merchantTeam)
        ->get(route('pra_departmental.index'))
        ->assertOk()
        ->assertDontSee($legacy->request_no);
});

it('never lists a departmental PRA in the legacy queue', function () {
    $management = daxUser('management', name: 'Mgmt');
    daxLegacyPra($management);

    $this->actingAs($management)
        ->get(route('pra_approvals.index'))
        ->assertOk()
        ->assertDontSee($this->pra->request_no);
});

// --- Wrong step, wrong tier, wrong buyer -------------------------------------

it('refuses a merchant TEAM member acting as the HEAD', function () {
    // Step 2 is theirs; step 3 is not. Advance to step 3 and try again.
    $this->workflow->approve($this->pra, $this->merchantTeam);

    $this->actingAs($this->merchantTeam)
        ->post(route('pra_departmental.approve', $this->pra->fresh()))
        ->assertRedirect();

    // Still on step 3, and no second decision was recorded for them.
    expect($this->pra->fresh()->current_sequence)->toBe(3)
        ->and(PraApproval::where('sequence', 3)->count())->toBe(0);
});

it('refuses a merchant from a different buyer', function () {
    $outsider = daxConfine(daxUser('merchant', name: 'Other'), $this->otherBuyer);

    $this->actingAs($outsider)
        ->post(route('pra_departmental.approve', $this->pra))
        ->assertRedirect();

    expect($this->pra->fresh()->current_sequence)->toBe(2)
        ->and(PraApproval::count())->toBe(0);
});

it('hides a PRA from a merchant of a different buyer', function () {
    $outsider = daxConfine(daxUser('merchant', name: 'Other'), $this->otherBuyer);

    $this->actingAs($outsider)->get(route('pra_departmental.index'))
        ->assertOk()->assertDontSee($this->pra->request_no);

    $this->actingAs($outsider)->get(route('pra_departmental.show', $this->pra))->assertForbidden();
});

it('refuses accounts and commercial while the PRA is still at a merchant step', function (string $actor) {
    $user = $this->{$actor};

    $this->actingAs($user)->post(route('pra_departmental.approve', $this->pra))->assertRedirect();
    $this->actingAs($user)->get(route('pra_departmental.show', $this->pra))->assertForbidden();

    expect($this->pra->fresh()->current_sequence)->toBe(2)
        ->and(PraApproval::count())->toBe(0);
})->with(['accountsTeam', 'accountsHead', 'commercialHead']);

it('refuses rejection from someone whose step it is not', function () {
    $this->actingAs($this->accountsHead)
        ->post(route('pra_departmental.reject', $this->pra), ['comment' => 'no'])
        ->assertRedirect();

    expect($this->pra->fresh()->status)->toBe(PaymentRequest::STATUS_PENDING_CHECK)
        ->and(PraApproval::count())->toBe(0);
});

it('refuses anyone without the permission at all', function () {
    $outsider = daxUser('supply_chain', name: 'SC');

    $this->actingAs($outsider)->get(route('pra_departmental.index'))->assertForbidden();
    $this->actingAs($outsider)->get(route('pra_departmental.show', $this->pra))->assertForbidden();
    $this->actingAs($outsider)->post(route('pra_departmental.approve', $this->pra))->assertForbidden();
});

it('refuses a guest', function () {
    $this->get(route('pra_departmental.index'))->assertRedirect(route('login'));
});

// --- What each role CAN do ---------------------------------------------------

it('lets the right actor act at each step, in turn', function () {
    $cast = [
        2 => $this->merchantTeam, 3 => $this->merchantHead,
        4 => $this->accountsTeam, 5 => $this->accountsHead, 6 => $this->commercialHead,
    ];

    foreach ($cast as $seq => $actor) {
        expect($this->pra->fresh()->current_sequence)->toBe($seq);

        $this->actingAs($actor)
            ->post(route('pra_departmental.approve', $this->pra->fresh()), ['comment' => 'ok'])
            ->assertRedirect(route('pra_departmental.index'));
    }

    expect($this->pra->fresh()->status)->toBe(PaymentRequest::STATUS_COMPLETED);
});

it('shows each actor only the PRAs at their own step', function () {
    $this->actingAs($this->merchantTeam)->get(route('pra_departmental.index'))
        ->assertOk()->assertSee($this->pra->request_no);

    // Same PRA, wrong actor.
    $this->actingAs($this->accountsTeam)->get(route('pra_departmental.index'))
        ->assertOk()->assertDontSee($this->pra->request_no);
});

it('lets someone who already acted look back at the record', function () {
    $this->workflow->approve($this->pra, $this->merchantTeam);

    // No longer their step, but their own decision is theirs to see.
    $this->actingAs($this->merchantTeam)
        ->get(route('pra_departmental.show', $this->pra->fresh()))
        ->assertOk();
});

it('requires a reason when rejecting', function () {
    $this->actingAs($this->merchantTeam)
        ->post(route('pra_departmental.reject', $this->pra), ['comment' => ''])
        ->assertSessionHasErrors('comment');

    expect($this->pra->fresh()->status)->toBe(PaymentRequest::STATUS_PENDING_CHECK);
});

// --- New PRAs are born on flow 2, old ones stay put --------------------------

it('leaves every existing legacy PRA on flow 1 when a new one is created', function () {
    $management = daxUser('management', name: 'Mgmt');
    $legacy = daxLegacyPra($management);

    daxPra($this->buyer);

    expect($legacy->fresh()->flow_version)->toBe(PaymentRequest::FLOW_LEGACY)
        ->and($legacy->fresh()->current_sequence)->toBeNull()
        ->and($legacy->fresh()->status)->toBe(PaymentRequest::STATUS_PENDING_APPROVAL);
});
