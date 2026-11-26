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

it('splits the Requested queue and the main list into Monthly and Individual tabs', function () {
    [$head, $raiser] = indRaiser();
    $monthly = indRaise($raiser, PurchaseRequisition::TYPE_MONTHLY);
    $individual = indRaise($raiser, PurchaseRequisition::TYPE_INDIVIDUAL);
    // The Requested queue holds only what the head has sent on to the Store.
    indApprove($head, $monthly);
    indApprove($head, $individual);
    $clerk = indUser('store', ['store.requisition.create']);

    foreach (['store.stock.requisitions.requested', 'store.stock.requisitions.index'] as $route) {
        // All months: the Requested queue opens on the current month.
        $this->actingAs($clerk)->get(route($route, ['month' => '']))
            ->assertOk()->assertSee($monthly->requisition_no)->assertSee($individual->requisition_no)->assertSee('Individual');

        $this->actingAs($clerk)->get(route($route, ['type' => 'individual', 'month' => '']))
            ->assertOk()->assertSee($individual->requisition_no)->assertDontSee($monthly->requisition_no);

        $this->actingAs($clerk)->get(route($route, ['type' => 'monthly', 'month' => '']))
            ->assertOk()->assertSee($monthly->requisition_no)->assertDontSee($individual->requisition_no);
    }
});

it('does not add Safety Stock to To Be Procured on an Individual requisition', function () {
    [$head, $raiser] = indRaiser();
    StockItem::create(['name' => 'Machine Oil', 'uom' => 'Ltr', 'is_active' => true, 'safety_stock_qty' => 5]);

    $monthly = indRaise($raiser, PurchaseRequisition::TYPE_MONTHLY)->items()->firstOrFail();
    $individual = indRaise($raiser, PurchaseRequisition::TYPE_INDIVIDUAL)->items()->firstOrFail();

    expect((float) $monthly->safety_stock_snapshot)->toBe(5.0)
        ->and((float) $monthly->to_be_procured_qty)->toBe(10.0)       // 5 requested + 5 safety
        ->and((float) $individual->safety_stock_snapshot)->toBe(5.0)  // still shown
        ->and((float) $individual->to_be_procured_qty)->toBe(5.0);    // but not added
});

it('offers a Print button that loads the same PDF inline', function () {
    [$head, $raiser] = indRaiser();
    $sheet = indRaise($raiser, PurchaseRequisition::TYPE_INDIVIDUAL);

    $this->actingAs($raiser)->get(route('store.stock.requisitions.show', $sheet))
        ->assertOk()->assertSee('data-print-pdf', false)->assertSee('inline=1', false);

    $response = $this->actingAs($raiser)->get(route('store.stock.requisitions.pdf', [$sheet, 'inline' => 1]))->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('inline');
});

it('lists Raised By (the account) first, then Requested By (the head)', function () {
    [$head, $raiser] = indRaiser();
    $sheet = indRaise($raiser, PurchaseRequisition::TYPE_INDIVIDUAL);

    expect($sheet->requestedByName())->toBe('Cutting Head');

    $this->actingAs($raiser)->get(route('store.stock.requisitions.index'))
        ->assertOk()
        ->assertSeeInOrder(['Raised By', 'Requested By', $sheet->requisition_no, 'Cutting Raiser', 'Cutting Head']);
});

it('updates Stock in Hand and To Be Procured when the Store receives the sheet', function () {
    [$head, $raiser] = indRaiser();
    $item = StockItem::create(['name' => 'Machine Oil', 'uom' => 'Ltr', 'is_active' => true]);
    \App\Models\StockPurchase::create(['stock_item_id' => $item->id, 'purchase_date' => now()->startOfMonth()->toDateString(),
        'rcv_date' => now()->startOfMonth()->toDateString(), 'qty' => 9, 'unit_price' => 100]);

    // Raised today with 9 on the shelf: 5 asked, nothing to buy.
    test()->actingAs($raiser)->post(route('store.stock.requisitions.store'), [
        'action' => 'submit', 'mode' => PurchaseRequisition::MODE_MULTI, 'requisition_date' => now()->toDateString(),
        'requisition_type' => 'individual',
        'items' => [['stock_item_id' => $item->id, 'qty_requested' => 5]],
    ])->assertSessionHasNoErrors();
    $sheet = PurchaseRequisition::latest('id')->firstOrFail();
    $line = $sheet->items()->firstOrFail();
    expect((float) $line->stock_in_hand_snapshot)->toBe(9.0)->and((float) $line->to_be_procured_qty)->toBe(0.0);

    // The stock is issued before the Store takes the sheet.
    \Illuminate\Support\Facades\DB::table('stock_issues')->insert([
        'stock_item_id' => $item->id, 'is_stock_item' => true, 'issue_date' => now()->toDateString(), 'qty' => 9,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    indApprove($head, $sheet)->assertSessionHasNoErrors();
    expect((float) $line->fresh()->stock_in_hand_snapshot)->toBe(9.0); // the head's step changes nothing

    indApprove(indUser('store', [], ['name' => 'Store Clerk']), $sheet)->assertSessionHasNoErrors();
    $line->refresh();
    expect((float) $line->stock_in_hand_snapshot)->toBe(0.0)
        ->and((float) $line->to_be_procured_qty)->toBe(5.0)
        ->and((float) $line->amount)->toBe(500.0);
});

it('merges individual requisitions of one section at the Store step into the oldest', function () {
    [$head, $raiser] = indRaiser();
    $oil = StockItem::create(['name' => 'Machine Oil', 'uom' => 'Ltr', 'is_active' => true]);
    $tape = StockItem::create(['name' => 'Tape', 'uom' => 'Roll', 'is_active' => true]);

    $raise = function (array $lines) use ($raiser) {
        test()->actingAs($raiser)->post(route('store.stock.requisitions.store'), [
            'action' => 'submit', 'mode' => PurchaseRequisition::MODE_MULTI, 'requisition_date' => '2026-10-05',
            'requisition_type' => 'individual', 'items' => $lines,
        ])->assertSessionHasNoErrors();

        return PurchaseRequisition::latest('id')->firstOrFail();
    };
    $first = $raise([['stock_item_id' => $oil->id, 'qty_requested' => 5]]);
    $second = $raise([['stock_item_id' => $oil->id, 'qty_requested' => 3], ['stock_item_id' => $tape->id, 'qty_requested' => 2]]);
    indApprove($head, $first);
    indApprove($head, $second);

    // A Store clerk sees no merge, and is refused if they try.
    $clerk = indUser('store', [], ['name' => 'Store Clerk']);
    $this->actingAs($clerk)->get(route('store.stock.requisitions.my-approvals'))
        ->assertOk()->assertDontSee('mergeIndividualForm', false);
    $this->actingAs($clerk)->post(route('store.stock.requisitions.merge-individual'), ['ids' => [$first->id, $second->id]])
        ->assertSessionHasErrors(['ids' => 'Only the Store Head or Store Admin can merge individual requisitions.']);

    // The Store Head (Admin > Departments) does.
    $store = $this->approvers['store_head']->assignRole(Role::findOrCreate('store', 'web'));
    $this->actingAs($store)->get(route('store.stock.requisitions.my-approvals'))
        ->assertOk()->assertSee('mergeIndividualForm', false)->assertSee('Merge Selected');

    $this->actingAs($store)->post(route('store.stock.requisitions.merge-individual'), ['ids' => [$first->id, $second->id]])
        ->assertSessionHasNoErrors()->assertRedirect(route('store.stock.requisitions.show', $first));

    $first->refresh();
    $second->refresh();
    expect($first->items()->orderBy('id')->get()->mapWithKeys(fn ($l) => [$l->stockItem->name => (float) $l->qty_requested])->all())
        ->toBe(['Machine Oil' => 8.0, 'Tape' => 2.0])
        ->and($second->merged_into_id)->toBe($first->id)
        ->and($second->progressLabel())->toBe('Merged')
        ->and($first->mergedSources->pluck('id')->all())->toBe([$second->id]);

    // Only the kept sheet still waits at the Store; it carries on as before.
    $waiting = app(\App\Services\RequisitionWorkflow::class)->waitingFor($store)->pluck('id')->all();
    expect($waiting)->toBe([$first->id]);
    indApprove($store, $first)->assertSessionHasNoErrors();
    expect($first->fresh()->progressLabel())->toBe('With Finance & Commercial Head');
});

it('merges individual requisitions of different sections, each line keeping its section', function () {
    [$head, $raiser] = indRaiser();
    $otherHead = indUser('production', [], ['name' => 'Sewing Head']);
    $sewing = IndentSection::create(['name' => 'Sewing', 'department_key' => 'production', 'head_user_id' => $otherHead->id, 'is_active' => true]);
    $sewer = indUser('production', ['store.requisition.create'], ['name' => 'Sewing Raiser', 'requisition_section_id' => $sewing->id]);

    $a = indRaise($raiser, PurchaseRequisition::TYPE_INDIVIDUAL);
    $b = indRaise($sewer, PurchaseRequisition::TYPE_INDIVIDUAL);
    indApprove($head, $a);
    indApprove($otherHead, $b);

    $store = $this->approvers['store_head']->assignRole(Role::findOrCreate('store', 'web'));
    $this->actingAs($store)->post(route('store.stock.requisitions.merge-individual'), ['ids' => [$a->id, $b->id]])
        ->assertSessionHasNoErrors();
    expect($b->fresh()->merged_into_id)->toBe($a->id)
        // One item asked by both sections: one line, both sections named.
        ->and($a->fresh()->items()->pluck('user_dept')->implode(' '))->toContain('Cutting')->toContain('Sewing');
});

it('lets a Store Admin (Department Admin on the Store) merge individual requisitions too', function () {
    [$head, $raiser] = indRaiser();
    $a = indRaise($raiser, PurchaseRequisition::TYPE_INDIVIDUAL);
    $b = indRaise($raiser, PurchaseRequisition::TYPE_INDIVIDUAL);
    indApprove($head, $a);
    indApprove($head, $b);

    $storeAdmin = indUser('store', [], ['name' => 'Store Admin', 'is_department_admin' => true]);
    expect(PurchaseRequisition::isStoreHead($storeAdmin))->toBeFalse()
        ->and(PurchaseRequisition::canMergeIndividual($storeAdmin))->toBeTrue();

    $this->actingAs($storeAdmin)->get(route('store.stock.requisitions.my-approvals'))
        ->assertOk()->assertSee('Merge Selected');
    $this->actingAs($storeAdmin)->post(route('store.stock.requisitions.merge-individual'), ['ids' => [$a->id, $b->id]])
        ->assertSessionHasNoErrors();

    expect($b->fresh()->merged_into_id)->toBe($a->id);
});

it('lists individual sheets under Ready to Merge on Requested, and merges them there', function () {
    [$head, $raiser] = indRaiser();
    $make = function () use ($raiser) {
        test()->actingAs($raiser)->post(route('store.stock.requisitions.store'), [
            'action' => 'submit', 'mode' => PurchaseRequisition::MODE_MULTI, 'requisition_date' => now()->toDateString(),
            'requisition_type' => 'individual',
            'items' => [['stock_item_id' => StockItem::firstOrCreate(['name' => 'Machine Oil'], ['uom' => 'Ltr', 'is_active' => true])->id, 'qty_requested' => 2]],
        ])->assertSessionHasNoErrors();

        return PurchaseRequisition::latest('id')->firstOrFail();
    };
    $a = $make();
    $b = $make();
    indApprove($head, $a);
    indApprove($head, $b);

    $storeHead = $this->approvers['store_head']->assignRole(Role::findOrCreate('store', 'web'));
    $this->actingAs($storeHead)->get(route('store.stock.requisitions.requested', ['ready' => 1]))
        ->assertOk()
        ->assertSee($a->requisition_no)->assertSee($b->requisition_no)
        ->assertSee('mergeIndividualForm', false)->assertSee('Merge Individual');

    $this->actingAs($storeHead)->post(route('store.stock.requisitions.merge-individual'), ['ids' => [$a->id, $b->id]])
        ->assertSessionHasNoErrors();
    expect($b->fresh()->merged_into_id)->toBe($a->id);
});

it('opens Requested on the current month, and All months on request', function () {
    [$head, $raiser] = indRaiser();
    $old = indRaise($raiser, PurchaseRequisition::TYPE_MONTHLY);   // dated 2026-10-05
    indApprove($head, $old);
    $store = indUser('store');

    $this->actingAs($store)->get(route('store.stock.requisitions.requested'))
        // (Read from the list rows: the flash from the head's approval names the sheet too.)
        ->assertOk()->assertDontSee('text-slate-900">'.$old->requisition_no, false)
        ->assertSee('value="'.now('Asia/Dhaka')->format('Y-m').'" selected', false);

    $this->actingAs($store)->get(route('store.stock.requisitions.requested', ['month' => '']))
        ->assertOk()->assertSee('text-slate-900">'.$old->requisition_no, false);
});

it('counts on the Requested badge only sheets waiting at the Store step', function () {
    [$head, $raiser] = indRaiser();
    $sheet = indRaise($raiser, PurchaseRequisition::TYPE_INDIVIDUAL);

    // Still with the department head: not the Store's yet.
    expect(PurchaseRequisition::awaitingStore()->count())->toBe(0);

    indApprove($head, $sheet)->assertSessionHasNoErrors();
    expect(PurchaseRequisition::awaitingStore()->count())->toBe(1);

    $store = indUser('store', ['store.requisition.create'], ['name' => 'Store Clerk']);
    $this->actingAs($store)->get(route('store.stock.requisitions.requested', ['month' => '']))
        ->assertOk()->assertSee('bg-primary-subtle text-primary ms-1">1<', false);

    indApprove($store, $sheet)->assertSessionHasNoErrors();
    expect(PurchaseRequisition::awaitingStore()->count())->toBe(0);
});

it('does not count a Final Requisition with a later approver on the Requested badge', function () {
    [$head, $raiser] = indRaiser();
    $sheet = indRaise($raiser, PurchaseRequisition::TYPE_INDIVIDUAL);
    indApprove($head, $sheet)->assertSessionHasNoErrors();

    // The same submitted sheet, but as a Final Requisition: not a request to the Store.
    $sheet->forceFill(['level' => PurchaseRequisition::LEVEL_FINAL])->save();

    expect(PurchaseRequisition::awaitingStore()->count())->toBe(0);
});

it('keeps Stock in Hand live on a sheet until the Store receives it', function () {
    [$head, $raiser] = indRaiser();
    $item = StockItem::create(['name' => 'Gel Pen', 'uom' => 'Piece', 'is_active' => true]);
    $day = now()->startOfMonth()->toDateString();
    \App\Models\StockPurchase::create(['stock_item_id' => $item->id, 'purchase_date' => $day, 'rcv_date' => $day, 'qty' => 34, 'unit_price' => 10]);

    test()->actingAs($raiser)->post(route('store.stock.requisitions.store'), [
        'action' => 'submit', 'mode' => PurchaseRequisition::MODE_MULTI, 'requisition_date' => now()->toDateString(),
        'requisition_type' => 'individual',
        'items' => [['stock_item_id' => $item->id, 'qty_requested' => 120]],
    ])->assertSessionHasNoErrors();
    $sheet = PurchaseRequisition::latest('id')->firstOrFail();
    $line = $sheet->items()->firstOrFail();
    expect((float) $line->stock_in_hand_snapshot)->toBe(34.0)->and((float) $line->to_be_procured_qty)->toBe(86.0);

    // Issued while the sheet waits for its head: the sheet follows the shelf.
    $issue = \App\Models\StockIssue::create(['stock_item_id' => $item->id, 'issue_date' => now()->toDateString(), 'qty' => 8]);
    $line->refresh();
    expect((float) $line->stock_in_hand_snapshot)->toBe(26.0)
        ->and((float) $line->to_be_procured_qty)->toBe(94.0)
        ->and((float) $line->amount)->toBe(94.0 * (float) $line->rate_appx);

    // Received in, then the issue corrected: still live before the Store step.
    \App\Models\StockPurchase::create(['stock_item_id' => $item->id, 'purchase_date' => $day, 'rcv_date' => $day, 'qty' => 10, 'unit_price' => 10]);
    $issue->delete();
    expect((float) $line->fresh()->stock_in_hand_snapshot)->toBe(44.0);

    indApprove($head, $sheet)->assertSessionHasNoErrors();
    indApprove(indUser('store', [], ['name' => 'Store Clerk']), $sheet)->assertSessionHasNoErrors();
    expect($sheet->fresh()->status)->toBe(PurchaseRequisition::STATUS_STORE_REVIEWED);

    // After Received by Store the figures stay as the Store took them.
    \App\Models\StockIssue::create(['stock_item_id' => $item->id, 'issue_date' => now()->toDateString(), 'qty' => 40]);
    expect((float) $line->fresh()->stock_in_hand_snapshot)->toBe(44.0);

    $this->actingAs($raiser)->get(route('store.stock.requisitions.show', $sheet))->assertOk()
        ->assertSee('frozen when this requisition was raised');
});

it('applies a removed signature box to sheets already on their way, keeping what is signed', function () {
    [$head, $raiser] = indRaiser();
    $sheet = indRaise($raiser, PurchaseRequisition::TYPE_INDIVIDUAL);
    indApprove($head, $sheet)->assertSessionHasNoErrors();
    indApprove(indUser('store', [], ['name' => 'Store Clerk']), $sheet)->assertSessionHasNoErrors();
    expect($sheet->fresh()->progressLabel())->toBe('With Finance & Commercial Head');

    // Admin removes the Finance & Commercial Head box from the Individual level.
    RequisitionSignatureBlock::query()->forLevel('individual')->where('title', 'Finance & Commercial Head')->delete();
    RequisitionSignatureBlock::query()->forLevel('individual')->get()->values()
        ->each(fn ($b, $i) => $b->forceFill(['sequence' => $i + 1])->save());

    expect(app(\App\Services\RequisitionWorkflow::class)->syncOpenSheets(['individual']))->toBe(1);

    $sheet->refresh();
    $boxes = app(\App\Services\RequisitionSignatureResolver::class)->forRequisition($sheet);
    expect(collect($boxes)->pluck('title')->all())
        ->toBe(['', 'Department Head', 'Store', 'Operation Head'])
        ->and($sheet->progressLabel())->toBe('With Operation Head')
        // Signed boxes keep their names.
        ->and($boxes[1]['name'])->toBe('Cutting Head')
        ->and($boxes[2]['name'])->toBe('Store Clerk');

    // Saving again changes nothing, and the Operation Head approves it.
    expect(app(\App\Services\RequisitionWorkflow::class)->syncOpenSheets(['individual']))->toBe(0);
    indApprove($this->approvers['operation'], $sheet)->assertSessionHasNoErrors();
    expect($sheet->fresh()->status)->toBe(PurchaseRequisition::STATUS_APPROVED);
});

it('lets the head the sheet waits for add new items, and nobody else', function () {
    [$head, $raiser] = indRaiser();
    $sheet = indRaise($raiser, PurchaseRequisition::TYPE_INDIVIDUAL);
    $pen = StockItem::create(['name' => 'Gel Pen', 'uom' => 'Piece', 'is_active' => true]);
    $day = now()->startOfMonth()->toDateString();
    \App\Models\StockPurchase::create(['stock_item_id' => $pen->id, 'purchase_date' => $day, 'rcv_date' => $day, 'qty' => 10, 'unit_price' => 20]);
    $url = route('store.stock.requisitions.stage-add-items', $sheet);
    $oil = StockItem::where('name', 'Machine Oil')->firstOrFail();

    // The head sees the card; the raiser does not.
    $this->actingAs($head)->get(route('store.stock.requisitions.show', $sheet))->assertOk()->assertSee('id="addItems"', false);
    $this->actingAs($raiser)->get(route('store.stock.requisitions.show', $sheet))->assertOk()->assertDontSee('id="addItems"', false);
    $this->actingAs($raiser)->post($url, ['new_items' => [['stock_item_id' => $pen->id, 'qty_requested' => 4]], 'add_comment' => 'x'])->assertForbidden();

    // A reason is required, and an item already on the sheet is refused.
    $this->actingAs($head)->post($url, ['new_items' => [['stock_item_id' => $pen->id, 'qty_requested' => 4]]])->assertSessionHasErrors('add_comment');
    $this->actingAs($head)->post($url, ['new_items' => [['stock_item_id' => $oil->id, 'qty_requested' => 4]], 'add_comment' => 'x'])->assertSessionHasErrors('new_items');
    expect($sheet->items()->count())->toBe(1);

    $this->actingAs($head)->post($url, [
        'new_items' => [['stock_item_id' => $pen->id, 'qty_requested' => 25, 'rate_appx' => 22, 'remarks' => 'forgotten']],
        'add_comment' => 'Staff missed the pen',
    ])->assertSessionHasNoErrors()->assertSessionHas('success');

    $sheet->refresh();
    $line = $sheet->items()->where('stock_item_id', $pen->id)->firstOrFail();
    expect($sheet->items()->count())->toBe(2)
        ->and((float) $line->qty_requested)->toBe(25.0)
        ->and((float) $line->stock_in_hand_snapshot)->toBe(10.0)
        ->and((float) $line->to_be_procured_qty)->toBe(15.0)
        ->and((float) $line->amount)->toBe(330.0)
        ->and($line->sort_order)->toBeGreaterThan($sheet->items()->where('stock_item_id', $oil->id)->value('sort_order'))
        // Still at the head's step: adding items is not approving.
        ->and($sheet->current_stage)->toBe(2)
        ->and($sheet->actions()->where('action', 'items_added')->firstOrFail()->comment)->toBe('Staff missed the pen');

    // Once the head has approved, they can no longer add; the Store never could.
    indApprove($head, $sheet)->assertSessionHasNoErrors();
    $this->actingAs($head)->post($url, ['new_items' => [['stock_item_id' => $pen->id, 'qty_requested' => 1]], 'add_comment' => 'x'])->assertForbidden();
    $store = indUser('store', [], ['name' => 'Store Clerk']);
    $this->actingAs($store)->post($url, ['new_items' => [['stock_item_id' => $pen->id, 'qty_requested' => 1]], 'add_comment' => 'x'])->assertForbidden();
});
