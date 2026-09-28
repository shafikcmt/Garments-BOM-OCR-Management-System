<?php

use App\Models\IndentSection;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionAction;
use App\Models\PurchaseRequisitionItem;
use App\Models\StockItem;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Step 3a — requisition lines are updated in place.
 *
 *   - A line posted back with its id, still naming the same item, keeps
 *     that id; a new line is inserted; a line left out is deleted.
 *   - A row whose item was changed is a different line: new id.
 *   - Every figure is recalculated exactly as the old delete-and-recreate did.
 *   - The Store correction's history diff is unchanged.
 *   - The "Add to Item Master" link on a typed line survives an edit.
 *
 * In-memory sqlite. No real record touched.
 */
// Stage engine: every approval stage has a person, as Admin would set it.
beforeEach(fn () => $this->approvers = requisitionApprovers());

function inplaceUser(string $role, array $permissions = []): User
{
    $user = User::factory()->create()->assignRole(Role::findOrCreate($role, 'web'));

    foreach (array_merge(['store.requisition.view'], $permissions) as $p) {
        $user->givePermissionTo(Permission::findOrCreate($p, 'web'));
    }

    return $user;
}

function inplaceItem(string $name): StockItem
{
    return StockItem::firstOrCreate(['name' => $name], ['uom' => 'Pcs', 'is_active' => true]);
}

function inplacePost(User $user, array $lines, ?PurchaseRequisition $sheet = null, string $action = 'draft')
{
    $payload = [
        'action' => $action,
        'mode' => PurchaseRequisition::MODE_MULTI,
        'requisition_date' => '2026-09-22',
        'items' => $lines,
    ];

    $response = $sheet
        ? test()->actingAs($user)->put(route('store.stock.requisitions.update', $sheet), $payload)
        : test()->actingAs($user)->post(route('store.stock.requisitions.store'), $payload);

    $response->assertSessionHasNoErrors()->assertRedirect();

    return PurchaseRequisition::latest('id')->firstOrFail();
}

/** Each line's figures, without the columns an edit is allowed to change. */
function inplaceFigures(PurchaseRequisition $sheet): array
{
    return $sheet->fresh()->items->map(fn ($l) => collect($l->getAttributes())
        ->except(['id', 'created_at', 'updated_at'])->all())->values()->all();
}

it('keeps the ids of lines posted back, inserts new ones and deletes the missing', function () {
    $store = inplaceUser('store', ['store.requisition.create']);
    [$oil, $thread, $tape, $glue] = [inplaceItem('Oil'), inplaceItem('Thread'), inplaceItem('Tape'), inplaceItem('Glue')];

    $sheet = inplacePost($store, [
        ['stock_item_id' => $oil->id, 'qty_requested' => 5],
        ['stock_item_id' => $thread->id, 'qty_requested' => 2],
        ['stock_item_id' => $tape->id, 'qty_requested' => 1],
    ]);
    $ids = $sheet->items->pluck('id', 'stock_item_id');

    inplacePost($store, [
        ['id' => $ids[$oil->id], 'stock_item_id' => $oil->id, 'qty_requested' => 8],
        ['id' => $ids[$thread->id], 'stock_item_id' => $thread->id, 'qty_requested' => 2],
        ['stock_item_id' => $glue->id, 'qty_requested' => 4],
    ], $sheet);

    $after = $sheet->fresh()->items->keyBy('stock_item_id');

    expect($after[$oil->id]->id)->toBe($ids[$oil->id])
        ->and((float) $after[$oil->id]->qty_requested)->toBe(8.0)
        ->and($after[$thread->id]->id)->toBe($ids[$thread->id])
        ->and($after->has($tape->id))->toBeFalse()
        ->and(PurchaseRequisitionItem::find($ids[$tape->id]))->toBeNull()
        ->and($after[$glue->id]->id)->not->toBeIn($ids->all());
});

it('gives a new id to a row whose item was changed', function () {
    $store = inplaceUser('store', ['store.requisition.create']);
    [$oil, $thread] = [inplaceItem('Oil'), inplaceItem('Thread')];

    $sheet = inplacePost($store, [['stock_item_id' => $oil->id, 'qty_requested' => 5]]);
    $oldId = $sheet->items->first()->id;

    inplacePost($store, [['id' => $oldId, 'stock_item_id' => $thread->id, 'qty_requested' => 5]], $sheet);

    $line = $sheet->fresh()->items->sole();
    expect($line->stock_item_id)->toBe($thread->id)
        ->and($line->id)->not->toBe($oldId)
        ->and(PurchaseRequisitionItem::find($oldId))->toBeNull();
});

it('reuses the saved line of the same item when no id is posted, and ignores a foreign id', function () {
    $store = inplaceUser('store', ['store.requisition.create']);
    $oil = inplaceItem('Oil');

    $other = inplacePost($store, [['stock_item_id' => $oil->id, 'qty_requested' => 1]]);
    $sheet = inplacePost($store, [['stock_item_id' => $oil->id, 'qty_requested' => 5]]);
    $id = $sheet->items->first()->id;
    $foreignId = $other->items->first()->id;

    inplacePost($store, [['id' => $foreignId, 'stock_item_id' => $oil->id, 'qty_requested' => 6]], $sheet);

    expect($sheet->fresh()->items->sole()->id)->toBe($id)
        ->and((float) $other->fresh()->items->sole()->qty_requested)->toBe(1.0);
});

it('stores exactly the figures the old delete-and-recreate stored', function () {
    $store = inplaceUser('store', ['store.requisition.create']);
    [$oil, $thread] = [inplaceItem('Oil'), inplaceItem('Thread')];
    $lines = [
        ['stock_item_id' => $oil->id, 'qty_requested' => 5, 'rate_appx' => 12.5, 'specification' => 'Brand A', 'remarks' => 'urgent', 'user_dept' => 'Cutting'],
        ['custom_item_name' => 'Hand Sanitiser 5L', 'qty_requested' => 3, 'rate_appx' => 900],
        // Folded into the first line, as before.
        ['stock_item_id' => $oil->id, 'qty_requested' => 2, 'user_dept' => 'Finishing'],
    ];

    $fresh = inplacePost($store, $lines);          // a brand-new sheet: every line inserted
    $edited = inplacePost($store, [['stock_item_id' => $thread->id, 'qty_requested' => 1]]);
    $savedIds = $edited->items->pluck('id');
    inplacePost($store, $lines, $edited);           // the same lines, saved over an existing sheet

    $strip = fn (array $rows) => array_map(fn ($r) => collect($r)->except('purchase_requisition_id')->all(), $rows);

    expect($strip(inplaceFigures($edited)))->toBe($strip(inplaceFigures($fresh)))
        ->and($edited->fresh()->items->pluck('id')->intersect($savedIds))->toBeEmpty();
});

it('keeps line ids and the history diff through a Store correction', function () {
    $section = IndentSection::create(['name' => 'Cutting', 'department_key' => 'production', 'is_active' => true]);
    $raiser = inplaceUser('production', ['store.requisition.create']);
    $raiser->update(['requisition_section_id' => $section->id]);
    $store = inplaceUser('store', ['store.requisition.edit']);
    $oil = inplaceItem('Oil');

    $sheet = inplacePost($raiser, [['stock_item_id' => $oil->id, 'qty_requested' => 5]], null, 'submit');
    $id = $sheet->items->sole()->id;
    // The Store corrects only old-flow sheets.
    $sheet->forceFill(['workflow_version' => null, 'current_stage' => null])->save();

    inplacePost($store, [['id' => $id, 'stock_item_id' => $oil->id, 'qty_requested' => 9]], $sheet, 'submit');

    expect($sheet->fresh()->items->sole()->id)->toBe($id);

    $diff = PurchaseRequisitionAction::where('purchase_requisition_id', $sheet->id)->where('action', 'corrected')->sole();
    expect($diff->meta['lines'])->toEqual([['item' => 'Oil', 'change' => 'changed', 'old_qty' => 5, 'new_qty' => 9]]);
});

it('keeps the Add to Item Master link working after an edit', function () {
    $store = inplaceUser('store', ['store.requisition.create', 'store.items.create']);

    $sheet = inplacePost($store, [['custom_item_name' => 'Hand Sanitiser 5L', 'qty_requested' => 3]]);
    $id = $sheet->items->sole()->id;

    inplacePost($store, [['id' => $id, 'custom_item_name' => 'Hand Sanitiser 5L', 'qty_requested' => 4]], $sheet);

    expect($sheet->fresh()->items->sole()->id)->toBe($id);
    $this->actingAs($store)->get(route('store.stock.items.index', ['requisition_item' => $id]))
        ->assertOk()->assertSee('Hand Sanitiser 5L');
});

it('renders the saved line id on the edit form', function () {
    $store = inplaceUser('store', ['store.requisition.create']);
    $sheet = inplacePost($store, [['stock_item_id' => inplaceItem('Oil')->id, 'qty_requested' => 5]]);

    $this->actingAs($store)->get(route('store.stock.requisitions.edit', $sheet))
        ->assertOk()
        ->assertSee('name="items[__INDEX__][id]"', false)
        ->assertSee('"id":'.$sheet->items->sole()->id, false);
});
