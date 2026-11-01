<?php

use App\Models\IndentSection;
use App\Models\PurchaseRequisition;
use App\Models\RequisitionSignatureBlock;
use App\Models\StockItem;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Two kinds of requisition (owner, 2026-11-02):
 *
 *   Monthly     Prepared → Head → Received by Store → merged into the month's
 *               Final Requisition → Store Head → Finance → Operation Head.
 *   Individual  Prepared → Head → Received by Store → Finance & Commercial
 *               Head → Operation Head. Never merged.
 *
 * HR Audit is in neither chain.
 *
 * In-memory sqlite. No real record touched.
 */
beforeEach(function () {
    $this->approvers = requisitionApprovers();
});

function indUser(string $role, array $permissions = [], array $attributes = []): User
{
    $user = User::factory()->create($attributes)->assignRole(Role::findOrCreate($role, 'web'));

    foreach (array_merge(['store.requisition.view'], $permissions) as $p) {
        $user->givePermissionTo(Permission::findOrCreate($p, 'web'));
    }

    return $user;
}

/** @return array{0: User, 1: User} head and raiser of a production section */
function indRaiser(): array
{
    $head = indUser('production', [], ['name' => 'Cutting Head']);
    $section = IndentSection::create(['name' => 'Cutting', 'department_key' => 'production', 'head_user_id' => $head->id, 'is_active' => true]);

    return [$head, indUser('production', ['store.requisition.create'], ['name' => 'Cutting Raiser', 'requisition_section_id' => $section->id])];
}

function indRaise(User $user, string $type): PurchaseRequisition
{
    $item = StockItem::firstOrCreate(['name' => 'Machine Oil'], ['uom' => 'Ltr', 'is_active' => true]);

    test()->actingAs($user)->post(route('store.stock.requisitions.store'), [
        'action' => 'submit', 'mode' => PurchaseRequisition::MODE_MULTI, 'requisition_date' => '2026-10-05',
        'requisition_type' => $type,
        'items' => [['stock_item_id' => $item->id, 'qty_requested' => 5]],
    ])->assertSessionHasNoErrors()->assertRedirect();

    return PurchaseRequisition::latest('id')->firstOrFail();
}

function indApprove(User $user, PurchaseRequisition $sheet)
{
    return test()->actingAs($user)->post(route('store.stock.requisitions.stage-approve', $sheet));
}

it('has the Individual boxes: Prepared, Head, Store, Finance, Operation', function () {
    expect(RequisitionSignatureBlock::query()->forLevel('individual')->pluck('title')->all())
        ->toBe(['Requesting Department', 'Department Head', 'Store', 'Finance & Commercial Head', 'Operation Head'])
        ->and(RequisitionSignatureBlock::query()->forLevel('individual')->get()->last()->stamp_source)->toBe('approved');
});

it('takes an individual sheet through head, Received by Store, Finance and Operation — and never merges it', function () {
    [$head, $raiser] = indRaiser();
    $sheet = indRaise($raiser, PurchaseRequisition::TYPE_INDIVIDUAL);

    expect($sheet->isIndividual())->toBeTrue()
        ->and($sheet->stages()->orderBy('sequence')->pluck('assignee_type')->all())
        ->toBe(['requester', 'department_head', 'store_team', 'user', 'user']);

    indApprove($head, $sheet)->assertSessionHasNoErrors();

    // The Store's step is Received, by any Store user — and the sheet carries on.
    indApprove(indUser('store', [], ['name' => 'Store Clerk']), $sheet)->assertSessionHasNoErrors();
    $sheet->refresh();
    expect($sheet->status)->toBe(PurchaseRequisition::STATUS_STORE_REVIEWED)
        ->and($sheet->isReadyToMerge())->toBeFalse()
        ->and($sheet->progressLabel())->toBe('With Finance & Commercial Head')
        ->and(PurchaseRequisition::readyToMerge()->count())->toBe(0);

    indApprove($this->approvers['finance'], $sheet)->assertSessionHasNoErrors();
    expect($sheet->fresh()->status)->not->toBe(PurchaseRequisition::STATUS_APPROVED);

    indApprove($this->approvers['operation'], $sheet)->assertSessionHasNoErrors();
    $sheet->refresh();
    expect($sheet->status)->toBe(PurchaseRequisition::STATUS_APPROVED)
        ->and($sheet->merged_into_id)->toBeNull()
        ->and($sheet->progressLabel())->toBe('Approved');
});

it('refuses to merge an individual sheet', function () {
    [$head, $raiser] = indRaiser();
    $sheet = indRaise($raiser, PurchaseRequisition::TYPE_INDIVIDUAL);
    indApprove($head, $sheet);
    indApprove(indUser('store'), $sheet);

    $clerk = indUser('store', ['store.requisition.create']);
    $this->actingAs($clerk)->post(route('store.stock.requisitions.merge'), ['ids' => [$sheet->id]])
        ->assertSessionHasErrors('ids');
});

it('keeps a monthly sheet exactly as before: Received, then waits to be merged', function () {
    [$head, $raiser] = indRaiser();
    $sheet = indRaise($raiser, PurchaseRequisition::TYPE_MONTHLY);

    expect($sheet->isIndividual())->toBeFalse()
        ->and($sheet->stages()->count())->toBe(3);

    indApprove($head, $sheet);
    indApprove(indUser('store'), $sheet);

    expect($sheet->fresh()->isReadyToMerge())->toBeTrue();
});

it('shows stock figures on an individual sheet to everyone who can open it', function () {
    [$head, $raiser] = indRaiser();
    $sheet = indRaise($raiser, PurchaseRequisition::TYPE_INDIVIDUAL);
    $sheet->items()->update(['stock_in_hand_snapshot' => 987, 'safety_stock_snapshot' => 12]);

    $this->actingAs($raiser)->get(route('store.stock.requisitions.show', $sheet))->assertOk()->assertSee('987');

    $monthly = indRaise($raiser, PurchaseRequisition::TYPE_MONTHLY);
    $monthly->items()->update(['stock_in_hand_snapshot' => 654]);
    $this->actingAs($raiser)->get(route('store.stock.requisitions.show', $monthly))->assertOk()->assertDontSee('654');
});

it('offers a department user both new-requisition buttons and opens the individual form', function () {
    [, $raiser] = indRaiser();

    $this->actingAs($raiser)->get(route('store.stock.requisitions.index'))
        ->assertOk()->assertSee('New Monthly Requisition')->assertSee('New Individual Requisition');

    $this->actingAs($raiser)->get(route('store.stock.requisitions.create', ['type' => 'individual']))
        ->assertOk()->assertSee('New Individual Requisition')->assertSee('value="individual"', false);
});

it('never makes a Store user\'s own Final Requisition individual', function () {
    $clerk = indUser('store', ['store.requisition.create']);
    $this->actingAs($clerk)->get(route('store.stock.requisitions.create', ['type' => 'individual']))
        ->assertOk()->assertDontSee('New Individual Requisition')->assertSee('New Purchase Requisition');

    $this->actingAs($clerk)->get(route('store.stock.requisitions.index'))->assertDontSee('New Individual Requisition');
});

it('shows the kind on every department sheet and filters the list by it', function () {
    [, $raiser] = indRaiser();
    $monthly = indRaise($raiser, PurchaseRequisition::TYPE_MONTHLY);
    $individual = indRaise($raiser, PurchaseRequisition::TYPE_INDIVIDUAL);

    expect($monthly->requisition_type)->toBe('monthly')->and($individual->requisition_type)->toBe('individual');

    $this->actingAs($raiser)->get(route('store.stock.requisitions.index'))
        ->assertOk()->assertSee($monthly->requisition_no)->assertSee($individual->requisition_no)
        ->assertSee('Monthly')->assertSee('Individual');

    $this->actingAs($raiser)->get(route('store.stock.requisitions.index', ['type' => 'individual']))
        ->assertOk()->assertSee($individual->requisition_no)->assertDontSee($monthly->requisition_no);
});

it('splits the Category Summary into monthly and individual sheets', function () {
    $this->travelTo('2026-10-20');
    [$head, $raiser] = indRaiser();
    $monthly = indRaise($raiser, PurchaseRequisition::TYPE_MONTHLY);
    $individual = indRaise($raiser, PurchaseRequisition::TYPE_INDIVIDUAL);
    // Both are counted once their head has checked them.
    indApprove($head, $monthly);
    indApprove($head, $individual);
    $monthly->items()->update(['amount' => 100]);
    $individual->items()->update(['amount' => 40]);

    $kinds = app(\App\Services\CategorySummary::class)->build(\Illuminate\Support\Carbon::parse('2026-10-01'))['kinds'];
    expect($kinds['monthly']['requisitions'])->toBe(1)->and($kinds['monthly']['amount'])->toBe(100.0)
        ->and($kinds['individual']['requisitions'])->toBe(1)->and($kinds['individual']['amount'])->toBe(40.0);

    $admin = indUser('admin');
    $this->actingAs($admin)->get(route('store.stock.requisitions.category-summary', ['month' => '2026-10']))
        ->assertOk()->assertSee('Individual requisitions')->assertSee('Monthly requisitions');
    $this->actingAs($admin)->get(route('store.stock.requisitions.category-summary.excel', ['month' => '2026-10']))->assertOk();
    $this->actingAs($admin)->get(route('store.stock.requisitions.category-summary.pdf', ['month' => '2026-10']))->assertOk();
});

it('takes a requisition of over a hundred lines posted as one JSON field', function () {
    // PHP drops variables past max_input_vars (1000); the form packs the lines
    // so a Final-sized requisition (and the Submit button's value) always arrives.
    [, $raiser] = indRaiser();
    $lines = [];
    foreach (range(1, 150) as $n) {
        $item = StockItem::create(['name' => 'Item '.$n, 'uom' => 'Pcs', 'is_active' => true]);
        $lines[] = ['stock_item_id' => $item->id, 'qty_requested' => $n, 'remarks' => 'r'.$n];
    }

    $this->actingAs($raiser)->post(route('store.stock.requisitions.store'), [
        'action' => 'submit', 'mode' => PurchaseRequisition::MODE_MULTI, 'requisition_date' => '2026-10-05',
        'items_json' => json_encode($lines),
    ])->assertSessionHasNoErrors()->assertRedirect();

    $sheet = PurchaseRequisition::latest('id')->firstOrFail();
    expect($sheet->status)->toBe(PurchaseRequisition::STATUS_SUBMITTED)
        ->and($sheet->items()->count())->toBe(150)
        ->and((float) $sheet->items()->sum('qty_requested'))->toBe(11325.0);
});

it('lets the Store Head prepare a Final Requisition: their own Store Head step is approved at submit', function () {
    $plan = collect(app(\App\Services\RequisitionWorkflow::class)->plan('final', $this->approvers['store_head'], null, null));
    $storeHeadStage = $plan->first(fn ($s) => $s['assignee_type'] === 'store_head');

    expect($storeHeadStage['self_approved'])->toBeTrue()
        ->and($storeHeadStage['problem'])->toBeNull()
        // Finance and Operation still need someone else.
        ->and($plan->pluck('problem')->filter()->all())->toBe([]);

    // A different Store user preparing it: the Store Head still approves.
    $other = collect(app(\App\Services\RequisitionWorkflow::class)->plan('final', indUser('store'), null, null))
        ->first(fn ($s) => $s['assignee_type'] === 'store_head');
    expect($other['self_approved'])->toBeFalse();
});
