<?php

use App\Models\IndentSection;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionAction;
use App\Models\RequisitionSignatureBlock;
use App\Models\StockItem;
use App\Models\User;
use App\Services\MonthlyRequisitionReportService;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The requisition business flow, end to end:
 *
 *   Department sheet: Prepared → Section/Department Head → Received by Store
 *                     (any Store user). It stops there.
 *   Merge:            the Store merges ONE month's received sheets into its
 *                     final sheet (a draft it checks, may add to, submits).
 *   Final sheet:      Store Head → HR Audit → Finance & Commercial →
 *                     Operation Head.
 *
 * The same items come every month, so a final sheet holds one month, and the
 * monthly report counts each item's demand once in its own month.
 *
 * In-memory sqlite. No real record touched.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-07 10:00:00');
    $this->approvers = requisitionApprovers();
});

afterEach(fn () => Carbon::setTestNow());

function mgUser(string $role, array $permissions = [], array $attributes = []): User
{
    $user = User::factory()->create($attributes)->assignRole(Role::findOrCreate($role, 'web'));

    foreach (array_merge(['store.requisition.view'], $permissions) as $p) {
        $user->givePermissionTo(Permission::findOrCreate($p, 'web'));
    }

    return $user;
}

/** A section with its head, and a raiser in it. */
function mgSection(string $name): array
{
    $head = mgUser('production', [], ['name' => $name.' Head']);
    $section = IndentSection::create(['name' => $name, 'department_key' => 'production', 'head_user_id' => $head->id, 'is_active' => true]);
    $raiser = mgUser('production', ['store.requisition.create'], ['name' => $name.' Raiser', 'requisition_section_id' => $section->id]);

    return [$section, $head, $raiser];
}

function mgSubmit(User $user, array $lines, string $date = '2026-10-05'): PurchaseRequisition
{
    test()->actingAs($user)->post(route('store.stock.requisitions.store'), [
        'action' => 'submit', 'mode' => PurchaseRequisition::MODE_MULTI, 'requisition_date' => $date,
        'items' => collect($lines)->map(function ($qty, $name) {
            $item = StockItem::firstOrCreate(['name' => $name], ['uom' => 'Pcs', 'is_active' => true]);

            return ['stock_item_id' => $item->id, 'qty_requested' => $qty];
        })->values()->all(),
    ])->assertSessionHasNoErrors()->assertRedirect();

    return PurchaseRequisition::latest('id')->firstOrFail();
}

function mgApprove(User $user, PurchaseRequisition $sheet)
{
    return test()->actingAs($user)->post(route('store.stock.requisitions.stage-approve', $sheet));
}

/** A department sheet taken through head and Store. */
function mgReceived(string $section, array $lines, User $storeUser, string $date = '2026-10-05'): PurchaseRequisition
{
    [, $head, $raiser] = mgSection($section);
    $sheet = mgSubmit($raiser, $lines, $date);
    mgApprove($head, $sheet)->assertSessionHasNoErrors();
    mgApprove($storeUser, $sheet)->assertSessionHasNoErrors();

    return $sheet->fresh();
}

function mgMerge(User $user, array $sheets)
{
    return test()->actingAs($user)->post(route('store.stock.requisitions.merge'), [
        'ids' => collect($sheets)->pluck('id')->all(),
    ]);
}

// --- Department sheet: three stages, the last by any Store user ------------------

it('ends a department sheet at Received by Store, which any Store user gives', function () {
    $clerk = mgUser('store', [], ['name' => 'Store Clerk']);
    [, $head, $raiser] = mgSection('Cutting');
    $sheet = mgSubmit($raiser, ['Machine Oil' => 5]);

    expect($sheet->stages()->orderBy('sequence')->pluck('assignee_type')->all())
        ->toBe(['requester', 'department_head', RequisitionSignatureBlock::ASSIGNEE_STORE_TEAM]);

    // Not before the head has checked it.
    mgApprove($clerk, $sheet)->assertForbidden();
    mgApprove($head, $sheet)->assertSessionHasNoErrors();

    mgApprove($clerk, $sheet)->assertSessionHasNoErrors();
    $sheet->refresh();

    expect($sheet->status)->toBe(PurchaseRequisition::STATUS_STORE_REVIEWED)
        ->and($sheet->store_ack_by)->toBe($clerk->id)
        ->and($sheet->current_stage)->toBeNull()
        ->and($sheet->isReadyToMerge())->toBeTrue()
        ->and($sheet->actions()->first()->action)->toBe(PurchaseRequisitionAction::RECEIVED);
});

it('lets the Store head receive too, but not Admin, Management or the department', function () {
    [, $head, $raiser] = mgSection('Cutting');
    $sheet = mgSubmit($raiser, ['Machine Oil' => 5]);
    mgApprove($head, $sheet);

    foreach ([mgUser('admin'), mgUser('management'), mgUser('production')] as $outsider) {
        mgApprove($outsider, $sheet)->assertStatus(in_array($outsider->getRoleNames()->first(), ['production'], true) ? 404 : 403);
    }

    mgApprove($this->approvers['store_head'], $sheet)->assertSessionHasNoErrors();
    expect($sheet->fresh()->status)->toBe(PurchaseRequisition::STATUS_STORE_REVIEWED);
});

// --- The merge ----------------------------------------------------------------

it('merges one month of received sheets into a final draft, one line per item', function () {
    $clerk = mgUser('store', ['store.requisition.create']);
    $cutting = mgReceived('Cutting', ['Machine Oil' => 5, 'Needle' => 100], $clerk);
    $sewing = mgReceived('Sewing', ['Machine Oil' => 3], $clerk);

    mgMerge($clerk, [$cutting, $sewing])->assertSessionHasNoErrors()->assertRedirect();

    $final = PurchaseRequisition::where('level', PurchaseRequisition::LEVEL_FINAL)->firstOrFail();
    $lines = $final->items()->with('stockItem')->get()->keyBy(fn ($l) => $l->stockItem->name);

    expect($final->status)->toBe(PurchaseRequisition::STATUS_DRAFT)
        ->and($final->created_by)->toBe($clerk->id)
        ->and($final->requisition_date->format('Y-m'))->toBe('2026-10')
        ->and($lines)->toHaveCount(2)
        ->and((float) $lines['Machine Oil']->qty_requested)->toBe(8.0)
        ->and($lines['Machine Oil']->user_dept)->toContain('Cutting')->toContain('Sewing')
        ->and((float) $lines['Needle']->qty_requested)->toBe(100.0)
        ->and($cutting->fresh()->merged_into_id)->toBe($final->id)
        ->and($sewing->fresh()->merged_into_id)->toBe($final->id)
        ->and($cutting->fresh()->progressLabel())->toBe('Merged into Final')
        ->and($cutting->fresh()->actions()->first()->action)->toBe(PurchaseRequisitionAction::MERGED);

    // Both sheets name each other on screen.
    $this->actingAs($clerk)->get(route('store.stock.requisitions.show', $cutting))
        ->assertOk()->assertSee('Merged into final requisition')->assertSee($final->requisition_no);
    $this->actingAs($clerk)->get(route('store.stock.requisitions.show', $final))
        ->assertOk()->assertSee('Merged from 2 department requisitions');
    $this->actingAs($clerk)->get(route('store.stock.requisitions.edit', $final))
        ->assertOk()->assertSee('Final requisition merged from');

    // Merged once only.
    mgMerge($clerk, [$cutting])->assertSessionHasErrors('ids');
});

it('merges one month at a time, and only sheets the Store has received', function () {
    $clerk = mgUser('store', ['store.requisition.create']);
    $october = mgReceived('Cutting', ['Machine Oil' => 5], $clerk, '2026-10-05');
    $september = mgReceived('Sewing', ['Machine Oil' => 3], $clerk, '2026-09-20');

    mgMerge($clerk, [$october, $september])->assertSessionHasErrors('ids');

    [, , $raiser] = mgSection('Finishing');
    $withHead = mgSubmit($raiser, ['Machine Oil' => 2]);
    mgMerge($clerk, [$withHead])->assertSessionHasErrors('ids');

    expect(PurchaseRequisition::where('level', PurchaseRequisition::LEVEL_FINAL)->count())->toBe(0);

    // A past month's final sheet is dated in that month.
    mgMerge($clerk, [$september])->assertSessionHasNoErrors();
    expect(PurchaseRequisition::where('level', PurchaseRequisition::LEVEL_FINAL)->first()->requisition_date->format('Y-m-d'))->toBe('2026-09-20');
});

it('refuses the merge to anyone but the Store side', function () {
    $clerk = mgUser('store', ['store.requisition.create']);
    $sheet = mgReceived('Cutting', ['Machine Oil' => 5], $clerk);

    mgMerge(mgUser('production', ['store.requisition.create']), [$sheet])->assertForbidden();
    expect($sheet->fresh()->merged_into_id)->toBeNull();
});

it('frees the merged sheets when the final draft is deleted or the final sheet cancelled', function () {
    $clerk = mgUser('store', ['store.requisition.create']);
    $sheet = mgReceived('Cutting', ['Machine Oil' => 5], $clerk);

    mgMerge($clerk, [$sheet]);
    $final = PurchaseRequisition::where('level', PurchaseRequisition::LEVEL_FINAL)->firstOrFail();
    $this->actingAs($clerk)->delete(route('store.stock.requisitions.destroy', $final))->assertRedirect();

    expect($sheet->fresh()->merged_into_id)->toBeNull()
        ->and($sheet->fresh()->isReadyToMerge())->toBeTrue()
        ->and($sheet->fresh()->actions()->first()->action)->toBe(PurchaseRequisitionAction::MERGE_RELEASED);

    mgMerge($clerk, [$sheet])->assertSessionHasNoErrors();
    $final = PurchaseRequisition::where('level', PurchaseRequisition::LEVEL_FINAL)->firstOrFail();
    $this->actingAs(mgUser('admin'))->post(route('store.stock.requisitions.cancel', $final), ['comment' => 'Wrong month'])->assertRedirect();

    expect($sheet->fresh()->merged_into_id)->toBeNull();
});

// --- The final sheet's approvals --------------------------------------------------

it('sends the submitted final sheet through Store Head, HR Audit, Finance and Operation Head', function () {
    $clerk = mgUser('store', ['store.requisition.create']);
    $sheet = mgReceived('Cutting', ['Machine Oil' => 5], $clerk);
    mgMerge($clerk, [$sheet]);
    $final = PurchaseRequisition::where('level', PurchaseRequisition::LEVEL_FINAL)->firstOrFail();

    // The Store checks and submits — here adding one of its own items.
    $item = StockItem::firstOrCreate(['name' => 'Machine Oil'], ['uom' => 'Pcs', 'is_active' => true]);
    $extra = StockItem::firstOrCreate(['name' => 'Floor Cleaner'], ['uom' => 'Ltr', 'is_active' => true]);
    $line = $final->items()->first();
    $this->actingAs($clerk)->put(route('store.stock.requisitions.update', $final), [
        'action' => 'submit', 'mode' => PurchaseRequisition::MODE_MULTI, 'requisition_date' => $final->requisition_date->toDateString(),
        'items' => [
            ['id' => $line->id, 'stock_item_id' => $item->id, 'qty_requested' => 5, 'user_dept' => $line->user_dept],
            ['stock_item_id' => $extra->id, 'qty_requested' => 2, 'user_dept' => 'Store'],
        ],
    ])->assertSessionHasNoErrors();

    $final->refresh();
    expect($final->status)->toBe(PurchaseRequisition::STATUS_SUBMITTED)
        ->and($final->items()->count())->toBe(2)
        ->and($final->stages()->orderBy('sequence')->pluck('assignee_name')->all())->toBe([
            $clerk->name, 'Store Head Approver', 'HR Audit Approver', 'Finance Approver', 'Operation Approver',
        ]);

    // The Store user who merged and submitted is "Prepared by"; the list
    // names the stage the sheet waits at.
    expect($final->progressLabel())->toBe('With Store Head');
    mgApprove($this->approvers['store_head'], $final)->assertSessionHasNoErrors();
    expect($final->fresh()->progressLabel())->toBe('With HR Head');

    // Only the Operation Head's approval makes it Approved.
    foreach (['hr', 'finance'] as $who) {
        mgApprove($this->approvers[$who], $final)->assertSessionHasNoErrors();
    }
    expect($final->fresh()->status)->not->toBe(PurchaseRequisition::STATUS_APPROVED)
        ->and($final->fresh()->progressLabel())->toBe('With Operation Head');
    mgApprove($this->approvers['operation'], $final)->assertSessionHasNoErrors();

    expect($final->fresh()->status)->toBe(PurchaseRequisition::STATUS_APPROVED)
        ->and($sheet->fresh()->merged_into_id)->toBe($final->id);
});

// --- Counting: once, in its own month ---------------------------------------------

it('counts merged demand once — on the department sheet until the final is submitted, then on the final', function () {
    $clerk = mgUser('store', ['store.requisition.create']);
    $sheet = mgReceived('Cutting', ['Machine Oil' => 5], $clerk);
    $september = mgReceived('Sewing', ['Machine Oil' => 4], $clerk, '2026-09-15');

    $qty = function (string $month) {
        return (float) app(MonthlyRequisitionReportService::class)->rows(Carbon::parse($month.'-01'))->sum('qty_requested');
    };
    $counted = fn (PurchaseRequisition $r) => PurchaseRequisition::query()->countedInTotals()->whereKey($r->id)->exists();

    expect($qty('2026-10'))->toBe(5.0)->and($qty('2026-09'))->toBe(4.0);

    mgMerge($clerk, [$sheet]);
    $final = PurchaseRequisition::where('level', PurchaseRequisition::LEVEL_FINAL)->firstOrFail();

    // A draft final is not demand yet; the department sheet still is.
    expect($counted($sheet->fresh()))->toBeTrue()->and($counted($final))->toBeFalse()
        ->and($qty('2026-10'))->toBe(5.0);

    $line = $final->items()->first();
    $this->actingAs($clerk)->put(route('store.stock.requisitions.update', $final), [
        'action' => 'submit', 'mode' => PurchaseRequisition::MODE_MULTI, 'requisition_date' => $final->requisition_date->toDateString(),
        'items' => [['id' => $line->id, 'stock_item_id' => $line->stock_item_id, 'qty_requested' => 5]],
    ])->assertSessionHasNoErrors();

    expect($counted($sheet->fresh()))->toBeFalse()->and($counted($final->fresh()))->toBeTrue()
        ->and($qty('2026-10'))->toBe(5.0)
        // September's same item stays September's.
        ->and($qty('2026-09'))->toBe(4.0);
});

// --- Stock figures stay with the Store ---------------------------------------------

it('hides stock figures from the approvers and the department on the final sheet', function () {
    $clerk = mgUser('store', ['store.requisition.create']);
    $sheet = mgReceived('Cutting', ['Machine Oil' => 5], $clerk);
    mgMerge($clerk, [$sheet]);
    $final = PurchaseRequisition::where('level', PurchaseRequisition::LEVEL_FINAL)->firstOrFail();
    $line = $final->items()->first();
    $this->actingAs($clerk)->put(route('store.stock.requisitions.update', $final), [
        'action' => 'submit', 'mode' => PurchaseRequisition::MODE_MULTI, 'requisition_date' => $final->requisition_date->toDateString(),
        'items' => [['id' => $line->id, 'stock_item_id' => $line->stock_item_id, 'qty_requested' => 5]],
    ]);

    $this->actingAs($this->approvers['hr'])->get(route('store.stock.requisitions.show', $final))
        ->assertOk()->assertViewHas('showStockFigures', false);
    $this->actingAs($clerk)->get(route('store.stock.requisitions.show', $final))
        ->assertOk()->assertViewHas('showStockFigures', true);

    $item = StockItem::where('name', 'Machine Oil')->firstOrFail();
    $json = $this->actingAs($this->approvers['hr'])->getJson(route('store.stock.requisitions.item-snapshot', $item))->json();
    expect($json)->not->toHaveKey('stock_in_hand')->not->toHaveKey('safety_stock');
});

// --- Screens --------------------------------------------------------------------

it('offers the merge on the Requested screen, and says what the monthly report does not count yet', function () {
    $clerk = mgUser('store', ['store.requisition.create']);
    mgReceived('Cutting', ['Machine Oil' => 5], $clerk);
    [, , $raiser] = mgSection('Sewing');
    mgSubmit($raiser, ['Needle' => 10]);

    $this->actingAs($clerk)->get(route('store.stock.requisitions.requested'))
        ->assertOk()->assertSee('Merge into Final Requisition')->assertViewHas('readyCount', 1);

    $this->actingAs($clerk)->get(route('store.stock.requisitions.report', ['month' => '2026-10']))
        ->assertOk()->assertSee('Not counted yet in October 2026')->assertSee('1 requisition with the section / department head');
});
