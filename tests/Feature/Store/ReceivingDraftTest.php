<?php

use App\Models\GeneralStockSupplier;
use App\Models\StockIssue;
use App\Models\StockItem;
use App\Models\StockPurchase;
use App\Models\StoreFormDraft;
use App\Models\User;
use App\Services\GeneralStockReportService;
use Spatie\Permission\Models\Permission;

/**
 * Saving a half-finished Record Receiving form.
 *
 * Mirrors IssueDraftTest, because the two screens share ManagesFormDrafts. The
 * pieces specific to receiving carry the weight:
 *
 *   - A draft holds NO GRN. The number is taken on save by the allocator, so a
 *     draft cannot reserve one and must not be labelled with one — whoever
 *     records first takes it.
 *   - The balance check that refuses a receiving is assertReceiptCovers on
 *     edit; on create the rule is simply that nothing is written until the
 *     submission is valid. A rejected submission must not cost the draft.
 *
 * In-memory sqlite. No real record touched.
 */
function rcvDraftItem(string $name = 'Sewing Needle'): StockItem
{
    return StockItem::create(['name' => $name, 'uom' => 'Pkt', 'category' => 'Needle', 'opening_qty' => 0]);
}

function rcvDrafter(array $permissions = ['store.receiving.view', 'store.receiving.create']): User
{
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $user = User::factory()->create(['status' => 1]);
    $user->givePermissionTo($permissions);

    return $user;
}

function rcvBalanceOf(StockItem $item): float
{
    return (float) app(GeneralStockReportService::class)
        ->rows(now()->startOfMonth(), ['item_ids' => [$item->id], 'only_active' => false])
        ->first()['stock_as_on'];
}

// --- A draft is not a receiving ----------------------------------------------

it('saves a half-finished form without receiving anything or moving stock', function () {
    $item = rcvDraftItem();

    expect(rcvBalanceOf($item))->toBe(0.0);

    $this->actingAs(rcvDrafter())
        ->post(route('store.stock.purchases.drafts.save'), [
            // No challan date, no rcv date — the half-typed state a draft is for.
            'challan_no' => 'CH-900',
            'items' => [
                ['stock_item_id' => $item->id, 'qty' => 10, 'unit_price' => 5],
                ['stock_item_id' => '', 'qty' => '', 'unit_price' => ''],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(StoreFormDraft::count())->toBe(1)
        // Nothing received, nothing moved.
        ->and(StockPurchase::count())->toBe(0)
        ->and(rcvBalanceOf($item))->toBe(0.0);

    // The blank line the form always shows is not worth keeping.
    expect(StoreFormDraft::first()->payload['items'])->toHaveCount(1);
});

it('takes no GRN number for a draft', function () {
    // The allocator is only called when a receiving is really recorded. A draft
    // that consumed a number would leave a permanent gap in the series for a
    // delivery that never happened.
    $item = rcvDraftItem();

    $this->actingAs(rcvDrafter())
        ->post(route('store.stock.purchases.drafts.save'), [
            'challan_no' => 'CH-900',
            'items' => [['stock_item_id' => $item->id, 'qty' => 10]],
        ]);

    expect(DB::table('stock_rv_sequences')->count())->toBe(0)
        ->and(StoreFormDraft::first()->payload)->not->toHaveKey('rv_no');
});

it('names a draft by its challan, never by a GRN it does not own', function () {
    $item = rcvDraftItem();
    $supplier = GeneralStockSupplier::create(['name' => 'Ideal Trading', 'is_active' => true]);

    $this->actingAs(rcvDrafter())
        ->post(route('store.stock.purchases.drafts.save'), [
            'challan_no' => 'CH-900',
            'purchase_date' => '2026-08-01',
            'general_stock_supplier_id' => $supplier->id,
            'items' => [['stock_item_id' => $item->id, 'qty' => 10]],
        ]);

    $label = StoreFormDraft::first()->label;

    expect($label)->toContain('Challan CH-900')
        ->toContain('2026-08-01')
        ->toContain('Ideal Trading')
        ->toContain('1 item')
        // The GRN preview belongs to whoever saves first, not to this draft.
        ->not->toContain('AUG');
});

// --- Resume ------------------------------------------------------------------

it('reopens a draft into the real form, fully populated and editable', function () {
    $needle = rcvDraftItem();
    $thread = rcvDraftItem('Sewing Thread');
    $supplier = GeneralStockSupplier::create(['name' => 'Ideal Trading', 'is_active' => true]);

    $user = rcvDrafter();

    $this->actingAs($user)->post(route('store.stock.purchases.drafts.save'), [
        'purchase_date' => '2026-08-01',
        'rcv_date' => '2026-08-03',
        'challan_no' => 'CH-900',
        'general_stock_supplier_id' => $supplier->id,
        'items' => [
            ['stock_item_id' => $needle->id, 'qty' => 10, 'unit_price' => 5, 'remarks' => 'first'],
            ['stock_item_id' => $thread->id, 'qty' => 4, 'unit_price' => 2, 'remarks' => 'second'],
        ],
    ]);

    $draft = StoreFormDraft::first();

    $this->actingAs($user)
        ->post(route('store.stock.purchases.drafts.resume', $draft))
        ->assertRedirect(route('store.stock.purchases.index'));

    // Put where old() reads from, which is the machinery the form already uses
    // to come back filled in — so it reopens as the real, editable form.
    expect(old('challan_no'))->toBe('CH-900')
        ->and(old('purchase_date'))->toBe('2026-08-01')
        ->and(old('rcv_date'))->toBe('2026-08-03')
        ->and(old('general_stock_supplier_id'))->toBe($supplier->id)
        ->and(old('items'))->toHaveCount(2)
        ->and(old('items')[1]['remarks'])->toBe('second')
        ->and(old('draft_id'))->toBe($draft->id);
});

it('updates the draft it was resumed from instead of making a second one', function () {
    $item = rcvDraftItem();
    $user = rcvDrafter();

    $this->actingAs($user)->post(route('store.stock.purchases.drafts.save'), [
        'challan_no' => 'CH-1',
        'items' => [['stock_item_id' => $item->id, 'qty' => 5]],
    ]);

    $draft = StoreFormDraft::first();

    $this->actingAs($user)->post(route('store.stock.purchases.drafts.save'), [
        'draft_id' => $draft->id,
        'challan_no' => 'CH-1-EDITED',
        'items' => [['stock_item_id' => $item->id, 'qty' => 9]],
    ]);

    expect(StoreFormDraft::count())->toBe(1)
        ->and(StoreFormDraft::first()->payload['challan_no'])->toBe('CH-1-EDITED');
});

// --- Submitting for real -----------------------------------------------------

it('records the receiving and clears the draft when it is finally submitted', function () {
    $item = rcvDraftItem();
    $user = rcvDrafter();

    $this->actingAs($user)->post(route('store.stock.purchases.drafts.save'), [
        'challan_no' => 'CH-900',
        'items' => [['stock_item_id' => $item->id, 'qty' => 10, 'unit_price' => 5]],
    ]);

    $draft = StoreFormDraft::first();

    $this->actingAs($user)->post(route('store.stock.purchases.store'), [
        'draft_id' => $draft->id,
        'purchase_date' => now()->startOfMonth()->toDateString(),
        'rcv_date' => now()->startOfMonth()->toDateString(),
        'challan_no' => 'CH-900',
        'items' => [['stock_item_id' => $item->id, 'qty' => 10, 'unit_price' => 5]],
    ])->assertRedirect()->assertSessionHas('success');

    expect(StockPurchase::count())->toBe(1)
        ->and(rcvBalanceOf($item))->toBe(10.0)
        // Now it takes a GRN, and the draft is gone.
        ->and(StockPurchase::first()->rv_no)->not->toBeNull()
        ->and(StoreFormDraft::count())->toBe(0);
});

it('keeps the draft when the real submission is rejected', function () {
    // Losing the saved work at the moment the form is refused is the worst
    // possible time to lose it.
    $item = rcvDraftItem();
    $user = rcvDrafter();

    $this->actingAs($user)->post(route('store.stock.purchases.drafts.save'), [
        'challan_no' => 'CH-900',
        'items' => [['stock_item_id' => $item->id, 'qty' => 10]],
    ]);

    $draft = StoreFormDraft::first();

    // RCV date before the challan date — refused, as it is for any submission.
    $this->actingAs($user)->post(route('store.stock.purchases.store'), [
        'draft_id' => $draft->id,
        'purchase_date' => '2026-08-10',
        'rcv_date' => '2026-08-01',
        'items' => [['stock_item_id' => $item->id, 'qty' => 10]],
    ])->assertSessionHasErrors('rcv_date');

    expect(StockPurchase::count())->toBe(0)
        ->and(rcvBalanceOf($item))->toBe(0.0)
        ->and(StoreFormDraft::count())->toBe(1);
});

// --- Whose draft it is -------------------------------------------------------

it('hides one user s drafts from another, and refuses to open them', function () {
    $item = rcvDraftItem();
    $mine = rcvDrafter();
    $theirs = rcvDrafter();

    $this->actingAs($mine)->post(route('store.stock.purchases.drafts.save'), [
        'challan_no' => 'CH-MINE',
        'items' => [['stock_item_id' => $item->id, 'qty' => 1]],
    ]);

    $draft = StoreFormDraft::first();

    $this->actingAs($theirs)->get(route('store.stock.purchases.index'))
        ->assertOk()
        ->assertDontSee('CH-MINE');

    $this->actingAs($theirs)->post(route('store.stock.purchases.drafts.resume', $draft))->assertForbidden();
    $this->actingAs($theirs)->delete(route('store.stock.purchases.drafts.destroy', $draft))->assertForbidden();

    expect(StoreFormDraft::count())->toBe(1);
});

it('refuses to replay a receiving draft into the issue form', function () {
    // The form check, not just the owner check: replayed into the wrong screen,
    // the payload's fields would land in whatever happened to share a name.
    $item = rcvDraftItem();
    $user = rcvDrafter(['store.receiving.view', 'store.receiving.create', 'store.issues.view', 'store.issues.create']);

    $this->actingAs($user)->post(route('store.stock.purchases.drafts.save'), [
        'challan_no' => 'CH-900',
        'items' => [['stock_item_id' => $item->id, 'qty' => 1]],
    ]);

    $draft = StoreFormDraft::first();

    $this->actingAs($user)
        ->post(route('store.stock.issues.drafts.resume', $draft))
        ->assertForbidden();
});

it('needs the create right to save a draft', function () {
    $item = rcvDraftItem();

    $this->actingAs(rcvDrafter(['store.receiving.view']))
        ->post(route('store.stock.purchases.drafts.save'), [
            'items' => [['stock_item_id' => $item->id, 'qty' => 1]],
        ])
        ->assertForbidden();

    expect(StoreFormDraft::count())->toBe(0);
});

// --- Delete ------------------------------------------------------------------

it('deletes a draft without touching anything recorded', function () {
    $item = rcvDraftItem();
    $user = rcvDrafter();

    // A real receiving, and an issue against it, that must both survive.
    StockPurchase::create([
        'stock_item_id' => $item->id,
        'purchase_date' => now()->startOfMonth()->toDateString(),
        'rcv_date' => now()->startOfMonth()->toDateString(),
        'qty' => 50, 'unit_price' => 1,
    ]);
    StockIssue::create([
        'stock_item_id' => $item->id,
        'issue_date' => now()->startOfMonth()->toDateString(),
        'qty' => 8,
    ]);

    $this->actingAs($user)->post(route('store.stock.purchases.drafts.save'), [
        'challan_no' => 'CH-900',
        'items' => [['stock_item_id' => $item->id, 'qty' => 999]],
    ]);

    $this->actingAs($user)
        ->delete(route('store.stock.purchases.drafts.destroy', StoreFormDraft::first()))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(StoreFormDraft::count())->toBe(0)
        ->and(StockPurchase::count())->toBe(1)
        ->and(rcvBalanceOf($item))->toBe(42.0);
});

it('shows the drafts card only when the user has one', function () {
    $item = rcvDraftItem();
    $user = rcvDrafter();

    $this->actingAs($user)->get(route('store.stock.purchases.index'))
        ->assertOk()
        ->assertDontSee('Saved Drafts');

    $this->actingAs($user)->post(route('store.stock.purchases.drafts.save'), [
        'challan_no' => 'CH-SEEN',
        'items' => [['stock_item_id' => $item->id, 'qty' => 1]],
    ]);

    $this->actingAs($user)->get(route('store.stock.purchases.index'))
        ->assertOk()
        ->assertSee('Saved Drafts')
        ->assertSee('CH-SEEN')
        ->assertSee('Resume');
});
