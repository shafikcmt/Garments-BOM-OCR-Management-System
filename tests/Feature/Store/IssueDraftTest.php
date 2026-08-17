<?php

use App\Models\StockIssue;
use App\Models\StockItem;
use App\Models\StockPurchase;
use App\Models\StoreFormDraft;
use App\Models\User;
use App\Services\GeneralStockReportService;
use Spatie\Permission\Models\Permission;

/**
 * Saving a half-finished Record Issue form.
 *
 * The guarantee that matters most is the one that is easiest to lose: A DRAFT
 * MOVES NO STOCK. It is a row in its own table with no path to stock_issues, so
 * the tests here check the balance as well as the record count.
 *
 * The other two:
 *   - Saving is lenient. A draft exists because somebody was interrupted, so
 *     refusing to save an incomplete one would defeat it.
 *   - Submitting for real is NOT lenient. The full validation runs, including
 *     the stock check, exactly as it does for a form nobody drafted.
 *
 * In-memory sqlite. No real record touched.
 */
function draftItem(string $name = 'Sewing Needle'): StockItem
{
    return StockItem::create(['name' => $name, 'uom' => 'Pkt', 'category' => 'Needle', 'opening_qty' => 0]);
}

function draftStock(StockItem $item, float $qty): StockPurchase
{
    return StockPurchase::create([
        'stock_item_id' => $item->id,
        'purchase_date' => now()->startOfMonth()->toDateString(),
        'rcv_date' => now()->startOfMonth()->toDateString(),
        'qty' => $qty, 'unit_price' => 1,
    ]);
}

function drafter(array $permissions = ['store.issues.view', 'store.issues.create']): User
{
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $user = User::factory()->create(['status' => 1]);
    $user->givePermissionTo($permissions);

    return $user;
}

function issueBalanceOf(StockItem $item): float
{
    return (float) app(GeneralStockReportService::class)
        ->rows(now()->startOfMonth(), ['item_ids' => [$item->id], 'only_active' => false])
        ->first()['stock_as_on'];
}

// --- A draft is not an issue -------------------------------------------------

it('saves a half-finished form without recording anything or moving stock', function () {
    $item = draftItem();
    draftStock($item, 100);

    expect(issueBalanceOf($item))->toBe(100.0);

    $this->actingAs(drafter())
        ->post(route('store.stock.issues.drafts.save'), [
            // No issue date, no requisition number — exactly the half-typed
            // state a draft exists for.
            'items' => [
                ['stock_item_id' => $item->id, 'qty' => 5],
                ['stock_item_id' => $item->id, 'qty' => ''],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(StoreFormDraft::count())->toBe(1)
        // Nothing recorded, nothing moved.
        ->and(StockIssue::count())->toBe(0)
        ->and(issueBalanceOf($item))->toBe(100.0);
});

it('saves a draft that would fail every rule a real submission has', function () {
    $item = draftItem();
    draftStock($item, 1);

    // 500 against 1 in stock, no date, no items key shape the validator wants.
    $this->actingAs(drafter())
        ->post(route('store.stock.issues.drafts.save'), [
            'items' => [['stock_item_id' => $item->id, 'qty' => 500]],
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    expect(StoreFormDraft::count())->toBe(1)
        ->and(StockIssue::count())->toBe(0)
        ->and(issueBalanceOf($item))->toBe(1.0);
});

it('drops item lines that were never filled in', function () {
    $item = draftItem();

    $this->actingAs(drafter())
        ->post(route('store.stock.issues.drafts.save'), [
            'items' => [
                ['stock_item_id' => $item->id, 'qty' => 5],
                ['stock_item_id' => '', 'qty' => '', 'remarks' => ''],
            ],
        ]);

    // The blank row the form always shows at the bottom is not worth keeping.
    expect(StoreFormDraft::first()->payload['items'])->toHaveCount(1);
});

// --- Resume ------------------------------------------------------------------

it('reopens a draft into the real form, fully populated and editable', function () {
    $needle = draftItem();
    $thread = draftItem('Sewing Thread');
    draftStock($needle, 100);
    draftStock($thread, 100);

    $user = drafter();

    $this->actingAs($user)->post(route('store.stock.issues.drafts.save'), [
        'issue_date' => '2026-08-01',
        'requisition_no' => 'REQ-DRAFT',
        'items' => [
            ['stock_item_id' => $needle->id, 'qty' => 5, 'remarks' => 'first'],
            ['stock_item_id' => $thread->id, 'qty' => 3, 'remarks' => 'second'],
        ],
    ]);

    $draft = StoreFormDraft::first();

    $this->actingAs($user)
        ->post(route('store.stock.issues.drafts.resume', $draft))
        ->assertRedirect(route('store.stock.issues.index'));

    // The payload is put where old() reads from, which is the same machinery a
    // rejected submission uses — so the ordinary form fills itself in, item
    // lines and all, and stays editable.
    expect(old('requisition_no'))->toBe('REQ-DRAFT')
        ->and(old('issue_date'))->toBe('2026-08-01')
        ->and(old('items'))->toHaveCount(2)
        ->and(old('items')[1]['remarks'])->toBe('second')
        // ...and the form knows which draft it came from.
        ->and(old('draft_id'))->toBe($draft->id);
});

it('updates the draft it was resumed from instead of making a second one', function () {
    $item = draftItem();
    $user = drafter();

    $this->actingAs($user)->post(route('store.stock.issues.drafts.save'), [
        'requisition_no' => 'REQ-1',
        'items' => [['stock_item_id' => $item->id, 'qty' => 5]],
    ]);

    $draft = StoreFormDraft::first();

    $this->actingAs($user)->post(route('store.stock.issues.drafts.save'), [
        'draft_id' => $draft->id,
        'requisition_no' => 'REQ-1-EDITED',
        'items' => [['stock_item_id' => $item->id, 'qty' => 9]],
    ]);

    expect(StoreFormDraft::count())->toBe(1)
        ->and(StoreFormDraft::first()->payload['requisition_no'])->toBe('REQ-1-EDITED');
});

it('lets one user keep several drafts at once', function () {
    $item = draftItem();
    $user = drafter();

    foreach (['REQ-1', 'REQ-2', 'REQ-3'] as $no) {
        $this->actingAs($user)->post(route('store.stock.issues.drafts.save'), [
            'requisition_no' => $no,
            'items' => [['stock_item_id' => $item->id, 'qty' => 1]],
        ]);
    }

    // Saving a second must never quietly replace the first.
    expect(StoreFormDraft::count())->toBe(3);
});

// --- Submitting for real -----------------------------------------------------

it('records the issue and clears the draft when it is finally submitted', function () {
    $item = draftItem();
    draftStock($item, 100);

    $user = drafter();

    $this->actingAs($user)->post(route('store.stock.issues.drafts.save'), [
        'items' => [['stock_item_id' => $item->id, 'qty' => 5]],
    ]);

    $draft = StoreFormDraft::first();

    $this->actingAs($user)->post(route('store.stock.issues.store'), [
        'draft_id' => $draft->id,
        'issue_date' => now()->startOfMonth()->toDateString(),
        'items' => [['stock_item_id' => $item->id, 'qty' => 5]],
    ])->assertRedirect()->assertSessionHas('success');

    expect(StockIssue::count())->toBe(1)
        ->and(issueBalanceOf($item))->toBe(95.0)
        // Served its purpose, so it is gone from the list.
        ->and(StoreFormDraft::count())->toBe(0);
});

it('keeps the draft when the real submission is rejected', function () {
    // The full rules run on submit — including the stock check — and a refused
    // submission must not also cost the user their saved work.
    $item = draftItem();
    draftStock($item, 10);

    $user = drafter();

    $this->actingAs($user)->post(route('store.stock.issues.drafts.save'), [
        'items' => [['stock_item_id' => $item->id, 'qty' => 50]],
    ]);

    $draft = StoreFormDraft::first();

    $this->actingAs($user)->post(route('store.stock.issues.store'), [
        'draft_id' => $draft->id,
        'issue_date' => now()->startOfMonth()->toDateString(),
        'items' => [['stock_item_id' => $item->id, 'qty' => 50]],
    ])->assertSessionHasErrors();

    expect(StockIssue::count())->toBe(0)
        ->and(issueBalanceOf($item))->toBe(10.0)
        ->and(StoreFormDraft::count())->toBe(1);
});

// --- Whose draft it is -------------------------------------------------------

it('hides one user s drafts from another, and refuses to open them', function () {
    $item = draftItem();
    $mine = drafter();
    $theirs = drafter();

    $this->actingAs($mine)->post(route('store.stock.issues.drafts.save'), [
        'requisition_no' => 'MINE',
        'items' => [['stock_item_id' => $item->id, 'qty' => 1]],
    ]);

    $draft = StoreFormDraft::first();

    // Not listed for the other user...
    $this->actingAs($theirs)->get(route('store.stock.issues.index'))
        ->assertOk()
        ->assertDontSee('MINE');

    // ...and not reachable by guessing the id either.
    $this->actingAs($theirs)->post(route('store.stock.issues.drafts.resume', $draft))->assertForbidden();
    $this->actingAs($theirs)->delete(route('store.stock.issues.drafts.destroy', $draft))->assertForbidden();

    expect(StoreFormDraft::count())->toBe(1);
});

it('needs the create right to save, resume or delete a draft', function () {
    $item = draftItem();
    $viewer = drafter(['store.issues.view']);

    $this->actingAs($viewer)
        ->post(route('store.stock.issues.drafts.save'), [
            'items' => [['stock_item_id' => $item->id, 'qty' => 1]],
        ])
        ->assertForbidden();

    expect(StoreFormDraft::count())->toBe(0);
});

// --- Delete ------------------------------------------------------------------

it('deletes a draft without touching anything recorded', function () {
    $item = draftItem();
    draftStock($item, 100);

    $user = drafter();

    // A real issue that must survive the draft being thrown away.
    StockIssue::create([
        'stock_item_id' => $item->id,
        'issue_date' => now()->startOfMonth()->toDateString(),
        'qty' => 4,
    ]);

    $this->actingAs($user)->post(route('store.stock.issues.drafts.save'), [
        'items' => [['stock_item_id' => $item->id, 'qty' => 5]],
    ]);

    $this->actingAs($user)
        ->delete(route('store.stock.issues.drafts.destroy', StoreFormDraft::first()))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(StoreFormDraft::count())->toBe(0)
        ->and(StockIssue::count())->toBe(1)
        ->and(issueBalanceOf($item))->toBe(96.0);
});

it('shows the drafts card only when the user has one', function () {
    $item = draftItem();
    $user = drafter();

    $this->actingAs($user)->get(route('store.stock.issues.index'))
        ->assertOk()
        ->assertDontSee('Saved Drafts');

    $this->actingAs($user)->post(route('store.stock.issues.drafts.save'), [
        'requisition_no' => 'REQ-SEEN',
        'items' => [['stock_item_id' => $item->id, 'qty' => 1]],
    ]);

    $this->actingAs($user)->get(route('store.stock.issues.index'))
        ->assertOk()
        ->assertSee('Saved Drafts')
        // Labelled by what was typed into it.
        ->assertSee('REQ-SEEN')
        ->assertSee('Resume');
});
