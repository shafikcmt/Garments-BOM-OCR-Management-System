<?php

use App\Models\IndentSection;
use App\Models\PurchaseRequisition;
use App\Models\StockItem;
use App\Models\User;
use App\Services\RequisitionWorkflow;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The stage tracker on the requisition show page (step 3e): every stage in
 * order, who did it and when, the stage the sheet waits at, and returns and
 * cancellation read plainly. Built from the stage plan and the history —
 * RequisitionWorkflow::tracker().
 *
 * In-memory sqlite. No real record touched.
 */
beforeEach(fn () => $this->approvers = requisitionApprovers());

function rstUser(string $role, array $attributes = [], array $permissions = ['store.requisition.view']): User
{
    $user = User::factory()->create($attributes)->assignRole(Role::findOrCreate($role, 'web'));

    foreach ($permissions as $p) {
        $user->givePermissionTo(Permission::findOrCreate($p, 'web'));
    }

    return $user;
}

/** A section head and a raiser in it, plus a department sheet already submitted. */
function rstSheet(): array
{
    $head = rstUser('production', ['name' => 'Cutting Head']);
    $section = IndentSection::create(['name' => 'Cutting', 'department_key' => 'production', 'head_user_id' => $head->id, 'is_active' => true]);
    $raiser = rstUser('production', ['name' => 'Cutting Raiser', 'requisition_section_id' => $section->id], ['store.requisition.view', 'store.requisition.create']);
    $stock = StockItem::firstOrCreate(['name' => 'Machine Oil'], ['uom' => 'Pcs', 'is_active' => true]);

    test()->actingAs($raiser)->post(route('store.stock.requisitions.store'), [
        'action' => 'submit', 'mode' => PurchaseRequisition::MODE_MULTI, 'requisition_date' => '2026-10-05',
        'items' => [['stock_item_id' => $stock->id, 'qty_requested' => 5]],
    ])->assertSessionHasNoErrors();

    return [PurchaseRequisition::latest('id')->firstOrFail(), $head, $raiser];
}

function rstRows(PurchaseRequisition $sheet): array
{
    $tracker = app(RequisitionWorkflow::class)->tracker($sheet->fresh()->load('actions'));

    return collect($tracker[0]['rows'])->map(fn ($r) => $r['sequence'].':'.$r['state'])->all();
}

it('shows every stage in order, the one it waits at, and who did what', function () {
    [$sheet, $head, $raiser] = rstSheet();

    expect(rstRows($sheet))->toBe(['1:done', '2:current', '3:waiting']);

    $this->actingAs($head)->post(route('store.stock.requisitions.stage-approve', $sheet), ['comment' => 'Checked'])->assertSessionHasNoErrors();

    $rows = app(RequisitionWorkflow::class)->tracker($sheet->fresh()->load('actions'))[0]['rows'];
    expect(collect($rows)->map(fn ($r) => $r['sequence'].':'.$r['state'])->all())->toBe(['1:done', '2:done', '3:current'])
        ->and($rows[0]['who'])->toBe('Cutting Raiser')
        ->and($rows[1]['who'])->toBe('Cutting Head')
        ->and($rows[1]['comment'])->toBe('Checked')
        ->and($rows[1]['at'])->not->toBeNull()
        ->and($rows[2]['who'])->toBe(RequisitionWorkflow::STORE_TEAM_NAME);

    $store = rstUser('store');
    $this->actingAs($store)->post(route('store.stock.requisitions.stage-approve', $sheet))->assertSessionHasNoErrors();
    expect(rstRows($sheet))->toBe(['1:done', '2:done', '3:done']);
});

it('renders the tracker on the show page for the raiser and for the approver', function () {
    [$sheet, $head, $raiser] = rstSheet();

    foreach ([$raiser, $head] as $viewer) {
        $this->actingAs($viewer)->get(route('store.stock.requisitions.show', $sheet))
            ->assertOk()
            ->assertSee('Approval Progress')
            ->assertSee('Waiting here')
            ->assertSee('Store team');
    }
});

it('reads a return to the raiser and a return to the previous approver', function () {
    [$sheet, $head, $raiser] = rstSheet();

    $this->actingAs($head)->post(route('store.stock.requisitions.stage-return', $sheet), ['comment' => 'Fix the qty'])->assertSessionHasNoErrors();

    $rows = app(RequisitionWorkflow::class)->tracker($sheet->fresh()->load('actions'))[0]['rows'];
    expect($rows[0]['state'])->toBe('returned')
        ->and($rows[1]['return']['by'])->toBe('Cutting Head')
        ->and($rows[1]['return']['reason'])->toBe('Fix the qty');

    $this->actingAs($raiser)->get(route('store.stock.requisitions.show', $sheet))
        ->assertOk()->assertSee('Returned by Cutting Head')->assertSee('Fix the qty');

    // Resubmitted: the first round is folded away, the new one is live.
    $line = $sheet->fresh()->items()->first();
    $this->actingAs($raiser)->put(route('store.stock.requisitions.update', $sheet), [
        'action' => 'submit', 'mode' => PurchaseRequisition::MODE_MULTI, 'requisition_date' => '2026-10-05',
        'items' => [['id' => $line->id, 'stock_item_id' => $line->stock_item_id, 'qty_requested' => 4]],
    ])->assertSessionHasNoErrors();

    $tracker = app(RequisitionWorkflow::class)->tracker($sheet->fresh()->load('actions'));
    expect($tracker)->toHaveCount(2)
        ->and($tracker[0]['active'])->toBeTrue()
        ->and(collect($tracker[0]['rows'])->pluck('state')->all())->toBe(['done', 'current', 'waiting'])
        ->and($tracker[1]['active'])->toBeFalse()
        ->and($tracker[1]['rows'][1]['state'])->toBe('returned');

    $this->actingAs($raiser)->get(route('store.stock.requisitions.show', $sheet))->assertOk()->assertSee('Round 1');
});

it('reads a cancelled sheet as closed', function () {
    [$sheet, $head, $raiser] = rstSheet();

    $this->actingAs(rstUser('admin'))->post(route('store.stock.requisitions.cancel', $sheet), ['comment' => 'Not needed'])->assertRedirect();

    expect(rstRows($sheet))->toBe(['1:done', '2:closed', '3:closed']);

    $this->actingAs($raiser)->get(route('store.stock.requisitions.show', $sheet))->assertOk()->assertSee('Approval Progress');
});

it('shows no tracker for a sheet that never used the stage engine', function () {
    [$sheet, , $raiser] = rstSheet();
    $sheet->forceFill(['workflow_version' => null])->save();

    expect(app(RequisitionWorkflow::class)->tracker($sheet->fresh()->load('actions')))->toBe([]);

    $this->actingAs($raiser)->get(route('store.stock.requisitions.show', $sheet))->assertOk()->assertDontSee('Approval Progress');
});

it('shows the Store stage of a department sheet as Received in the history, whichever way it was recorded', function () {
    [$sheet, $head] = rstSheet();
    $this->actingAs($head)->post(route('store.stock.requisitions.stage-approve', $sheet))->assertSessionHasNoErrors();
    $this->actingAs(rstUser('store'))->post(route('store.stock.requisitions.stage-approve', $sheet))->assertSessionHasNoErrors();

    // A sheet handled before the rule: the same step stored as 'stage_approved'.
    $sheet->actions()->where('action', 'received')->update(['action' => 'stage_approved']);

    $this->actingAs(rstUser('admin'))->get(route('store.stock.requisitions.show', $sheet))
        ->assertOk()
        ->assertSee('Received by Store')
        ->assertDontSee('Stage Approved');

    // Display only: the stored action is untouched.
    expect($sheet->actions()->where('action', 'stage_approved')->count())->toBe(1);
});
