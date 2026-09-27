<?php

use App\Models\AppSetting;
use App\Models\Buyer;
use App\Models\ExcelFile;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestItem;
use App\Models\PraApprover;
use App\Models\User;
use App\Http\Controllers\SupplyChain\PaymentRequestController;
use App\Services\PraWorkflow;
use App\Support\BuyerScope;
use App\Support\PaymentRequestSettings;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * THE RUNTIME TOGGLE that decides which approval process a NEW PRA is born onto.
 *
 * This reverses a deliberate earlier decision to keep the choice in a constant,
 * and the whole safety argument rests on one structural fact that these tests
 * exist to prove rather than assert:
 *
 *   flow_version is stamped onto the row at creation, and every later
 *   decision — who may act, which queue lists it, who is notified, which
 *   document it prints — reads THE ROW, never the setting.
 *
 * So the toggle picks a default at birth and governs nothing that already
 * exists. If that ever stops being true, the test below that walks an
 * in-progress PRA to completion with the toggle off is what will catch it.
 */
function tgRole(string $name): Role
{
    return Role::findOrCreate($name, 'web');
}

function tgUser(string $role, bool $head = false, string $name = 'X'): User
{
    tgRole($role);
    $user = User::factory()->create(['is_department_admin' => $head, 'name' => $name]);
    $user->assignRole($role);

    return $user->fresh();
}

/** Creates a PRA the way store() does — honouring the toggle at that moment. */
function tgCreatePra(User $creator, Buyer $buyer): PaymentRequest
{
    $pra = PaymentRequest::create([
        'request_no' => 'PRA-' . fake()->unique()->numerify('#####'),
        'status' => 'draft',
        'created_by' => $creator->id,
    ]);

    $file = ExcelFile::create([
        'file_name' => 'b.xlsx', 'original_file_name' => 'b.xlsx', 'file_path' => 'b.xlsx',
        'uploaded_by' => $creator->id, 'buyer_id' => $buyer->id, 'total_rows' => 1,
    ]);
    PaymentRequestItem::create(['payment_request_id' => $pra->id, 'excel_file_id' => $file->id]);

    $pra = $pra->fresh();

    // Mirrors the single consumption point in store().
    return PaymentRequestSettings::departmentalFlowEnabled()
        ? app(PraWorkflow::class)->start($pra)
        : $pra;
}

function tgSetToggle(bool $on): void
{
    AppSetting::put(PaymentRequestSettings::KEY_DEPARTMENTAL_FLOW, $on ? '1' : '0');
}

beforeEach(function () {
    Permission::findOrCreate('pra.departmental.act', 'web');
    foreach (['merchant', 'account', 'commercial', 'admin'] as $role) {
        tgRole($role)->givePermissionTo('pra.departmental.act');
    }

    $this->workflow = app(PraWorkflow::class);
    $this->buyer = Buyer::create(['buyer_name' => 'Hugo Boss']);

    $this->creator = tgUser('supply_chain', name: 'Creator');
    $this->merchantTeam = tgUser('merchant', name: 'M Team');
    $this->merchantTeam->buyer_scope_mode = BuyerScope::MODE_SPECIFIC;
    $this->merchantTeam->save();
    $this->merchantTeam->buyers()->sync([$this->buyer->id]);
    $this->merchantTeam = $this->merchantTeam->fresh();

    $this->merchantHead = tgUser('merchant', head: true, name: 'M Head');
    $this->accountsTeam = tgUser('account', name: 'A Team');
    $this->accountsHead = tgUser('account', head: true, name: 'A Head');
    $this->commercialHead = tgUser('commercial', head: true, name: 'C Head');
    $this->buyer->forceFill(['department_admin_id' => $this->merchantHead->id])->save();
});

// --- Test 1: the critical one ------------------------------------------------

it('finishes an in-progress departmental PRA even after the toggle is turned off', function () {
    tgSetToggle(true);
    $pra = tgCreatePra($this->creator, $this->buyer);

    expect($pra->flow_version)->toBe(PaymentRequest::FLOW_DEPARTMENTAL)
        ->and($pra->current_sequence)->toBe(2);

    // THE SWITCH IS THROWN MID-CHAIN.
    tgSetToggle(false);
    expect(PaymentRequestSettings::departmentalFlowEnabled())->toBeFalse();

    // ...and the PRA carries on exactly as it would have.
    $cast = [
        2 => $this->merchantTeam, 3 => $this->merchantHead, 4 => $this->accountsTeam,
        5 => $this->accountsHead, 6 => $this->commercialHead,
    ];

    foreach ($cast as $seq => $actor) {
        expect($pra->fresh()->current_sequence)->toBe($seq);
        $pra = $this->workflow->approve($pra->fresh(), $actor, 'Step ' . $seq);
    }

    expect($pra->fresh()->status)->toBe(PaymentRequest::STATUS_COMPLETED)
        ->and($pra->fresh()->current_sequence)->toBeNull()
        ->and($pra->fresh()->flow_version)->toBe(PaymentRequest::FLOW_DEPARTMENTAL)
        ->and($this->workflow->currentCycleDecisions($pra->fresh())->pluck('sequence')->all())
            ->toBe([2, 3, 4, 5, 6]);
});

it('lets an in-progress PRA be rejected and resubmitted with the toggle off', function () {
    tgSetToggle(true);
    $pra = tgCreatePra($this->creator, $this->buyer);
    tgSetToggle(false);

    $pra = $this->workflow->approve($pra, $this->merchantTeam);
    $pra = $this->workflow->reject($pra->fresh(), $this->merchantHead, 'Wrong supplier.');

    expect($pra->status)->toBe(PaymentRequest::STATUS_REJECTED);

    // Resubmission re-enters the DEPARTMENTAL chain, because the record says so.
    $pra = $this->workflow->resubmit($pra->fresh());

    expect($pra->flow_version)->toBe(PaymentRequest::FLOW_DEPARTMENTAL)
        ->and($pra->current_sequence)->toBe(2);
});

// --- Tests 2 and 3: what NEW PRAs get ---------------------------------------

it('creates a legacy PRA while the toggle is off', function () {
    tgSetToggle(false);

    $pra = tgCreatePra($this->creator, $this->buyer);

    expect($pra->flow_version)->toBe(PaymentRequest::FLOW_LEGACY)
        ->and($pra->current_sequence)->toBeNull()
        ->and($pra->isDepartmentalFlow())->toBeFalse();
});

it('creates a departmental PRA while the toggle is on', function () {
    tgSetToggle(true);

    $pra = tgCreatePra($this->creator, $this->buyer);

    expect($pra->flow_version)->toBe(PaymentRequest::FLOW_DEPARTMENTAL)
        ->and($pra->current_sequence)->toBe(2)
        ->and($pra->status)->toBe(PaymentRequest::STATUS_PENDING_CHECK);
});

it('restores the checker and approver pickers on the create screen when off', function () {
    // The @else branch built in Chunk A is driven by the same flag, so turning
    // the chain off has to hand the creator back the controls it removed.
    tgSetToggle(false);

    $blade = file_get_contents(resource_path('views/supply-chain/payment-requests/show.blade.php'));
    $legacyBranch = str($blade)->after('@if($createOnDepartmentalFlow ?? false)')->after('@else')->toString();

    expect(PaymentRequestSettings::departmentalFlowEnabled())->toBeFalse()
        ->and($legacyBranch)->toContain('name="checker_id"')
        ->and($legacyBranch)->toContain('name="approver_ids[]"');
});

it('falls back to the constant when the setting has never been written', function () {
    AppSetting::where('key', PaymentRequestSettings::KEY_DEPARTMENTAL_FLOW)->delete();

    expect(PaymentRequestSettings::departmentalFlowEnabled())
        ->toBe(PaymentRequestController::CREATE_ON_DEPARTMENTAL_FLOW);
});

// --- Test 4: nothing downstream reads the toggle -----------------------------

it('keeps an in-progress PRA visible and actionable in the queue with the toggle off', function () {
    tgSetToggle(true);
    $pra = tgCreatePra($this->creator, $this->buyer);
    tgSetToggle(false);

    // Still listed for the actor whose step it is...
    $this->actingAs($this->merchantTeam)
        ->get(route('pra_departmental.index'))
        ->assertOk()
        ->assertSee($pra->request_no);

    // ...still openable...
    $this->actingAs($this->merchantTeam)
        ->get(route('pra_departmental.show', $pra))
        ->assertOk();

    // ...and still actionable.
    $this->actingAs($this->merchantTeam)
        ->post(route('pra_departmental.approve', $pra), ['comment' => 'ok'])
        ->assertRedirect(route('pra_departmental.index'));

    expect($pra->fresh()->current_sequence)->toBe(3);
});

it('keeps the sidebar Approvals entry on the permission, not the toggle', function () {
    tgSetToggle(false);

    $this->actingAs($this->merchantTeam);
    $sidebar = view('layouts.sidebar')->render();

    // The entry is gated on pra.departmental.act, which the toggle does not touch.
    expect($sidebar)->toContain(route('pra_departmental.index'));
});

it('still notifies the next step actors with the toggle off', function () {
    tgSetToggle(true);
    $pra = tgCreatePra($this->creator, $this->buyer);
    tgSetToggle(false);

    \App\Models\AppNotification::query()->delete();

    $this->actingAs($this->merchantTeam)
        ->post(route('pra_departmental.approve', $pra))->assertRedirect();

    expect(\App\Models\AppNotification::where('type', 'pra_stage_request')->pluck('user_id')->all())
        ->toBe([$this->merchantHead->id]);
});

it('still prints the departmental document with the toggle off', function () {
    tgSetToggle(true);
    $pra = tgCreatePra($this->creator, $this->buyer);
    tgSetToggle(false);

    $blocks = PaymentRequestSettings::signatureBlocksFor($pra->fresh()->load('approvals.approver'), false);

    expect($blocks)->toHaveCount(4);
});

// --- Test 5: no migration, no existing record touched ------------------------

it('rewrites no existing PRA when the toggle is flipped', function () {
    tgSetToggle(true);
    $departmental = tgCreatePra($this->creator, $this->buyer);

    tgSetToggle(false);
    $legacy = tgCreatePra($this->creator, $this->buyer);

    // Flip both ways again; the two records must not move.
    tgSetToggle(true);
    tgSetToggle(false);
    tgSetToggle(true);

    expect($departmental->fresh()->flow_version)->toBe(PaymentRequest::FLOW_DEPARTMENTAL)
        ->and($legacy->fresh()->flow_version)->toBe(PaymentRequest::FLOW_LEGACY);
});

it('stores the toggle as a plain app setting with no schema of its own', function () {
    tgSetToggle(false);

    expect(AppSetting::get(PaymentRequestSettings::KEY_DEPARTMENTAL_FLOW))->toBe('0');

    tgSetToggle(true);

    expect(AppSetting::get(PaymentRequestSettings::KEY_DEPARTMENTAL_FLOW))->toBe('1');
});

// --- The admin screen --------------------------------------------------------

it('lets a super admin flip the toggle and refuses everybody else', function () {
    $admin = User::factory()->create();
    $admin->assignRole(tgRole('admin'));

    $this->actingAs($admin)->get(route('admin.payment-settings.edit'))
        ->assertOk()->assertSee('Approval Workflow for New PRAs');

    $this->actingAs($admin)->post(route('admin.payment-settings.update'), [
        'pra_working_days' => 7,
        'pra_departmental_flow_enabled' => '0',
    ])->assertRedirect();

    expect(PaymentRequestSettings::departmentalFlowEnabled())->toBeFalse();

    $this->actingAs($admin)->post(route('admin.payment-settings.update'), [
        'pra_working_days' => 7,
        'pra_departmental_flow_enabled' => '1',
    ])->assertRedirect();

    expect(PaymentRequestSettings::departmentalFlowEnabled())->toBeTrue();

    $this->actingAs($this->accountsHead)->get(route('admin.payment-settings.edit'))->assertForbidden();
});

it('warns the admin when turning it off would leave nobody to approve', function () {
    $admin = User::factory()->create();
    $admin->assignRole(tgRole('admin'));

    $this->actingAs($admin)->get(route('admin.payment-settings.edit'))
        ->assertOk()
        ->assertSee('No approvers are configured');
});

it('drops the empty-pool warning once approvers exist', function () {
    $admin = User::factory()->create();
    $admin->assignRole(tgRole('admin'));
    PraApprover::create(['user_id' => tgUser('management', name: 'Pooled')->id, 'is_active' => true, 'can_check' => true]);

    $this->actingAs($admin)->get(route('admin.payment-settings.edit'))
        ->assertOk()
        ->assertDontSee('No approvers are configured');
});
