<?php

use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionAction;
use App\Models\PurchaseRequisitionItem;
use App\Models\PurchaseRequisitionQtyChange;
use App\Models\StockItem;
use App\Models\User;
use App\Services\MonthlyRequisitionReportService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Step 3d — the approver the sheet waits for changes quantities at their
 * stage.
 *
 *   - Up or down, 0 for "not approved"; one reason per save, always required.
 *   - Only quantities; To Be Procured and Amount recalculated from the FROZEN
 *     snapshot and rate. Approving is a separate click.
 *   - The show page and the History keep the original request readable.
 *   - A 0 line prints as 0 on the sheet's own documents and is left out of
 *     the Monthly report.
 *
 * In-memory sqlite. No real record touched.
 */
beforeEach(fn () => $this->approvers = requisitionApprovers());

function aqUser(string $role, array $permissions = [], array $attributes = []): User
{
    $user = User::factory()->create($attributes)->assignRole(Role::findOrCreate($role, 'web'));

    foreach (array_merge(['store.requisition.view'], $permissions) as $p) {
        $user->givePermissionTo(Permission::findOrCreate($p, 'web'));
    }

    return $user;
}

/**
 * A final sheet raised by a Store clerk: Rope 10 @ 120 and Pen 5 @ 8. Rope's
 * frozen stock snapshot is set to 4, so a recalculation that re-read stock
 * instead would show.
 *
 * @return array{0: User, 1: PurchaseRequisition, 2: PurchaseRequisitionItem, 3: PurchaseRequisitionItem}
 */
function aqSheet(): array
{
    $clerk = aqUser('store', ['store.requisition.create']);
    $rope = StockItem::firstOrCreate(['name' => 'Advestor Rope'], ['uom' => 'Coil', 'is_active' => true]);
    $pen = StockItem::firstOrCreate(['name' => 'Bal Pen'], ['uom' => 'Pcs', 'is_active' => true]);

    test()->actingAs($clerk)->post(route('store.stock.requisitions.store'), [
        'action' => 'submit', 'mode' => PurchaseRequisition::MODE_MULTI, 'requisition_date' => '2026-09-22',
        'items' => [
            ['stock_item_id' => $rope->id, 'qty_requested' => 10, 'rate_appx' => 120],
            ['stock_item_id' => $pen->id, 'qty_requested' => 5, 'rate_appx' => 8],
        ],
    ])->assertRedirect();

    $sheet = PurchaseRequisition::latest('id')->firstOrFail();
    $ropeLine = $sheet->items()->where('stock_item_id', $rope->id)->sole();
    $penLine = $sheet->items()->where('stock_item_id', $pen->id)->sole();
    $ropeLine->update(['stock_in_hand_snapshot' => 4, 'to_be_procured_qty' => 6, 'amount' => 720]);

    return [$clerk, $sheet, $ropeLine, $penLine];
}

function aqChange(User $user, PurchaseRequisition $sheet, array $qty, ?string $reason = 'Budget cut for this month')
{
    return test()->actingAs($user)->post(route('store.stock.requisitions.stage-quantities', $sheet), array_filter([
        'qty' => $qty,
        'comment' => $reason,
    ], fn ($v) => $v !== null));
}

it('lets the approver lower one line and raise another, recalculated from the frozen snapshot', function () {
    [, $sheet, $rope, $pen] = aqSheet();
    $storeHead = $this->approvers['store_head'];

    aqChange($storeHead, $sheet, [$rope->id => 6, $pen->id => 8])
        ->assertRedirect()->assertSessionHas('success');

    $rope->refresh();
    $pen->refresh();

    // Rope: 6 − frozen stock 4 = 2 to buy, × 120.
    expect((float) $rope->qty_requested)->toBe(6.0)
        ->and((float) $rope->stock_in_hand_snapshot)->toBe(4.0)
        ->and((float) $rope->to_be_procured_qty)->toBe(2.0)
        ->and((float) $rope->amount)->toBe(240.0)
        ->and((float) $pen->qty_requested)->toBe(8.0);

    // One log row per line, one reason for the save, the stage named.
    $changes = PurchaseRequisitionQtyChange::where('purchase_requisition_id', $sheet->id)->orderBy('id')->get();
    expect($changes)->toHaveCount(2)
        ->and((float) $changes[0]->old_qty)->toBe(10.0)
        ->and((float) $changes[0]->new_qty)->toBe(6.0)
        ->and($changes->pluck('reason')->unique()->all())->toBe(['Budget cut for this month'])
        ->and($changes[0]->user_id)->toBe($storeHead->id)
        ->and($changes[0]->stage_sequence)->toBe(2);

    $history = PurchaseRequisitionAction::where('purchase_requisition_id', $sheet->id)->where('action', 'qty_changed')->sole();
    expect($history->comment)->toBe('Budget cut for this month')
        ->and($history->meta['lines'])->toHaveCount(2)
        ->and($history->meta['lines'][0])->toMatchArray(['item' => 'Advestor Rope', 'old_qty' => 10.0, 'new_qty' => 6.0]);

    // Still waiting at the same stage: approving is its own click.
    expect($sheet->fresh()->current_stage)->toBe(2);
});

it('shows the original request on the show page and in the History', function () {
    [$clerk, $sheet, $rope, $pen] = aqSheet();
    aqChange($this->approvers['store_head'], $sheet, [$rope->id => 6, $pen->id => 5]);

    $page = fn (User $user) => $this->actingAs($user)->get(route('store.stock.requisitions.show', $sheet))->assertOk();

    // The raiser sees the new figure, the original under it, and why.
    $page($clerk)
        ->assertSee('Requested 10 · changed at', false)
        ->assertSee('Budget cut for this month')
        ->assertSee('Quantity Changed')
        ->assertSee('10 → 6')
        ->assertDontSee('Change Quantities');

    // The approver the sheet waits for gets the form, with the original request.
    $page($this->approvers['store_head'])->assertSee('Change Quantities')->assertSee('Save Qty Changes')
        ->assertSee('name="qty['.$rope->id.']"', false);
});

it('needs a reason, a change, a non-negative number, and at least one line above 0', function () {
    [, $sheet, $rope, $pen] = aqSheet();
    $storeHead = $this->approvers['store_head'];

    aqChange($storeHead, $sheet, [$rope->id => 6, $pen->id => 5], null)->assertSessionHasErrors('comment');
    aqChange($storeHead, $sheet, [$rope->id => 10, $pen->id => 5])->assertSessionHasErrors('qty');
    aqChange($storeHead, $sheet, [$rope->id => -1, $pen->id => 5])->assertSessionHasErrors('qty.'.$rope->id);
    aqChange($storeHead, $sheet, [$rope->id => 0, $pen->id => 0])->assertSessionHasErrors('qty');
    // A line from another sheet does nothing.
    aqChange($storeHead, $sheet, [$rope->id => 6, 999999 => 1])->assertSessionHasErrors('qty');

    expect((float) $rope->fresh()->qty_requested)->toBe(10.0)
        ->and(PurchaseRequisitionQtyChange::count())->toBe(0);
});

it('lets only the person the sheet waits for change quantities', function () {
    [$clerk, $sheet, $rope] = aqSheet();

    // The raiser, a later stage out of turn, Admin, Management, another Store user.
    foreach ([$clerk, $this->approvers['hr'], aqUser('admin'), aqUser('management'), aqUser('store', ['store.requisition.edit'])] as $other) {
        aqChange($other, $sheet, [$rope->id => 1])->assertForbidden();
    }

    expect((float) $rope->fresh()->qty_requested)->toBe(10.0);

    // Once the Store Head approves, it is HR's turn — and no longer theirs.
    $this->actingAs($this->approvers['store_head'])->post(route('store.stock.requisitions.stage-approve', $sheet))->assertRedirect();
    aqChange($this->approvers['store_head'], $sheet, [$rope->id => 1])->assertForbidden();
    aqChange($this->approvers['hr'], $sheet, [$rope->id => 12])->assertRedirect()->assertSessionHas('success');
    expect((float) $rope->fresh()->qty_requested)->toBe(12.0);
});

it('leaves an old-flow sheet to the Store correction', function () {
    [, $sheet, $rope] = aqSheet();
    $sheet->forceFill(['workflow_version' => null, 'current_stage' => null])->save();

    // A Store user, who can open it: refused because it is on the old flow.
    aqChange(aqUser('store', ['store.requisition.edit']), $sheet, [$rope->id => 1])->assertStatus(422);
    expect((float) $rope->fresh()->qty_requested)->toBe(10.0);
});

it('keeps a 0 line on the sheet and its documents, and leaves it out of the Monthly report', function () {
    [, $sheet, $rope, $pen] = aqSheet();
    aqChange($this->approvers['store_head'], $sheet, [$rope->id => 0, $pen->id => 5], 'Not needed this month');

    $rope->refresh();
    expect((float) $rope->qty_requested)->toBe(0.0)
        ->and((float) $rope->to_be_procured_qty)->toBe(0.0)
        ->and((float) $rope->amount)->toBe(0.0);

    $this->actingAs($this->approvers['store_head'])->get(route('store.stock.requisitions.show', $sheet))
        ->assertOk()->assertSee('Not approved');

    // The sheet's own PDF/Excel rows still carry the line, at 0.
    $rows = app(MonthlyRequisitionReportService::class)->requisitionRows($sheet->fresh());
    expect($rows)->toHaveCount(2)
        ->and($rows->firstWhere('item_name', 'Advestor Rope')['qty_requested'])->toBe(0.0);
    $this->actingAs($this->approvers['store_head'])->get(route('store.stock.requisitions.excel', $sheet))->assertOk();
    $this->actingAs($this->approvers['store_head'])->get(route('store.stock.requisitions.pdf', $sheet))->assertOk();

    // The month leaves it out.
    $report = app(MonthlyRequisitionReportService::class);
    $month = $report->rows($report->resolveMonth('2026-09'));
    expect($month->pluck('item_name')->all())->toBe(['Bal Pen']);
});

it('keeps a change through a Return to the previous stage, and starts afresh on resubmit', function () {
    [$clerk, $sheet, $rope, $pen] = aqSheet();
    $storeHead = $this->approvers['store_head'];
    $workflow = app(\App\Services\RequisitionWorkflow::class);

    aqChange($storeHead, $sheet, [$rope->id => 6, $pen->id => 5]);
    $this->actingAs($storeHead)->post(route('store.stock.requisitions.stage-approve', $sheet))->assertRedirect();

    // HR returns it to the Store Head: the changed figure stands, the
    // original is still shown.
    $this->actingAs($this->approvers['hr'])->post(route('store.stock.requisitions.stage-return', $sheet), ['comment' => 'Recheck'])->assertRedirect();
    expect((float) $rope->fresh()->qty_requested)->toBe(6.0)
        ->and($workflow->originalQuantities($sheet->fresh())[$rope->id]['qty'])->toBe(10.0);

    // Back to the raiser, who resubmits 7: a new round, and 7 is the request.
    $this->actingAs($storeHead)->post(route('store.stock.requisitions.stage-return', $sheet), ['comment' => 'Your call'])->assertRedirect();
    $this->actingAs($clerk)->put(route('store.stock.requisitions.update', $sheet), [
        'action' => 'submit', 'mode' => PurchaseRequisition::MODE_MULTI, 'requisition_date' => '2026-09-22',
        'items' => [
            ['id' => $rope->id, 'stock_item_id' => $rope->stock_item_id, 'qty_requested' => 7, 'rate_appx' => 120],
            ['id' => $pen->id, 'stock_item_id' => $pen->stock_item_id, 'qty_requested' => 5, 'rate_appx' => 8],
        ],
    ])->assertRedirect();

    expect($workflow->originalQuantities($sheet->fresh()))->toBe([])
        ->and((float) $rope->fresh()->qty_requested)->toBe(7.0)
        // The earlier change stays on record.
        ->and(PurchaseRequisitionQtyChange::where('purchase_requisition_id', $sheet->id)->count())->toBe(1);
});
