<?php

use App\Models\GeneralStockSupplier;
use App\Models\StockIssue;
use App\Models\StockItem;
use App\Models\StockPurchase;
use App\Models\User;
use App\Services\GeneralStockReportService;
use Spatie\Permission\Models\Permission;

/**
 * Correcting a line of a recorded receiving.
 *
 * Mirrors StockIssueEditTest, because the two screens now share a rule set. The
 * two things that are specific to Receiving carry the weight:
 *
 *   - GRN No, Challan No and Challan Date are the DELIVERY'S IDENTITY. They are
 *     StockPurchase::groupKeyExpr(), so changing one on a single line would lift
 *     it out of its receiving. They are not editable and not accepted.
 *   - The balance rule runs in reverse. An issue can be raised too high; a
 *     receipt can be cut too low, and if the goods have gone the item lands
 *     below zero.
 *
 * Balance is derived, never stored — the report sums this table and
 * stock_issues at read time — so these read it back through that same service.
 *
 * In-memory sqlite. No real record touched.
 */
function purchaseItem(string $name = 'Sewing Needle', string $uom = 'Pkt'): StockItem
{
    return StockItem::create([
        'name' => $name, 'uom' => $uom, 'category' => 'Needle', 'opening_qty' => 0,
    ]);
}

function purchaseEditor(array $permissions = ['store.receiving.view', 'store.receiving.edit']): User
{
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $user = User::factory()->create(['status' => 1]);
    $user->givePermissionTo($permissions);

    return $user;
}

function purchaseBalance(StockItem $item): float
{
    return (float) app(GeneralStockReportService::class)
        ->rows(now()->startOfMonth(), ['item_ids' => [$item->id], 'only_active' => false])
        ->first()['stock_as_on'];
}

/** One line of a delivery. Header defaults match what store() would write. */
function recordedPurchase(StockItem $item, float $qty = 100, array $overrides = []): StockPurchase
{
    return StockPurchase::create(array_merge([
        'stock_item_id' => $item->id,
        'rv_no' => 'GRN-001',
        'challan_no' => 'CH-77',
        'purchase_date' => now()->startOfMonth()->toDateString(),
        'rcv_date' => now()->startOfMonth()->toDateString(),
        'qty' => $qty,
        'unit_price' => 5,
    ], $overrides));
}

/** The fields the form always posts, so a test can vary just the one it means. */
function purchasePayload(StockPurchase $line, array $overrides = []): array
{
    return array_merge([
        'rcv_date' => $line->rcv_date->toDateString(),
        'qty' => rtrim(rtrim(number_format((float) $line->qty, 4, '.', ''), '0'), '.'),
    ], $overrides);
}

// --- Permission: gated exactly as Delete is ---------------------------------

it('refuses an update from a user without the receiving edit right', function () {
    $item = purchaseItem();
    $line = recordedPurchase($item, 100);

    $viewer = purchaseEditor(['store.receiving.view']);

    $this->actingAs($viewer)
        ->put(route('store.stock.purchases.update', $line), purchasePayload($line, ['qty' => 50]))
        ->assertForbidden();

    expect((float) $line->fresh()->qty)->toBe(100.0);
});

it('accepts the receiving section permission and the flat one alike', function () {
    $item = purchaseItem();

    foreach (['store.receiving.edit', 'store.edit'] as $permission) {
        $line = recordedPurchase($item, 100);
        $user = purchaseEditor(['store.receiving.view', $permission]);

        $this->actingAs($user)
            ->put(route('store.stock.purchases.update', $line), purchasePayload($line, ['qty' => 80]))
            ->assertRedirect()
            ->assertSessionHas('success');

        expect((float) $line->fresh()->qty)->toBe(80.0);
    }
});

it('hides the Edit button from a user who cannot use it', function () {
    $item = purchaseItem();
    recordedPurchase($item, 100);

    $this->actingAs(purchaseEditor(['store.receiving.view']))
        ->get(route('store.stock.purchases.index'))
        ->assertOk()
        ->assertDontSee('editPurchase', false);

    $this->actingAs(purchaseEditor())
        ->get(route('store.stock.purchases.index'))
        ->assertOk()
        ->assertSee('editPurchase', false)
        ->assertSee('>Edit', false)
        // The locked fields read as locked, not as broken inputs.
        ->assertSee('gx-lock-tag', false)
        ->assertSee('Locked');
});

// --- Saying what the per-line actions actually reach -------------------------

it('labels the per-line action Remove, not Delete', function () {
    // "Delete" beside a GRN number reads as removing the whole delivery. This
    // takes one item OUT OF one, and the label has to say so. Issue History
    // keeps "Delete" — an issue is a record in its own right.
    $item = purchaseItem();
    recordedPurchase($item, 100);

    $this->actingAs(purchaseEditor(['store.receiving.view', 'store.edit', 'store.delete']))
        ->get(route('store.stock.purchases.index'))
        ->assertOk()
        ->assertSee('>Remove', false)
        ->assertDontSee('>Delete', false);
});

it('promises to keep the other items only when there are other items', function () {
    $needle = purchaseItem();
    $thread = purchaseItem('Sewing Thread', 'Cone');

    recordedPurchase($needle, 100);
    recordedPurchase($thread, 50);

    $html = $this->actingAs(purchaseEditor(['store.receiving.view', 'store.edit', 'store.delete']))
        ->get(route('store.stock.purchases.index'))
        ->assertOk()
        ->getContent();

    // Two lines on the GRN, so removing one leaves one — singular.
    expect($html)->toContain('The other item on this receiving is kept.')
        ->and($html)->toContain('GRN-001');

    // And the scope caption says which fields the delivery shares.
    expect($html)->toContain('All 2 items')
        ->and($html)->toContain('RCV Date and Supplier are shared by every item on this GRN.');
});

it('warns that removing the last line deletes the whole delivery', function () {
    // There is no delivery record to keep: the group IS these rows, so the last
    // one takes the GRN and challan with it. The old wording promised the
    // opposite, which was simply untrue in this case.
    $item = purchaseItem();
    recordedPurchase($item, 100);

    $html = $this->actingAs(purchaseEditor(['store.receiving.view', 'store.edit', 'store.delete']))
        ->get(route('store.stock.purchases.index'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('is the only item on GRN GRN-001')
        ->and($html)->toContain('deletes the whole receiving, including its GRN and challan record')
        // The multi-line promise must not appear anywhere on a single-line GRN.
        ->and($html)->not->toContain('are kept.')
        ->and($html)->not->toContain('is kept.');

    // One line, so no shared-fields caption either — there is nothing to share.
    expect($html)->not->toContain('All 1 items');
});

it('still removes only the line it was asked to remove', function () {
    // The labels changed; the behaviour did not.
    $needle = purchaseItem();
    $thread = purchaseItem('Sewing Thread', 'Cone');

    $first = recordedPurchase($needle, 100);
    $second = recordedPurchase($thread, 50);

    $this->actingAs(purchaseEditor(['store.receiving.view', 'store.delete']))
        ->delete(route('store.stock.purchases.destroy', $first))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(StockPurchase::find($first->id))->toBeNull()
        ->and(StockPurchase::find($second->id))->not->toBeNull()
        ->and((float) $second->fresh()->qty)->toBe(50.0);
});

// --- The delivery's identity is locked --------------------------------------

it('ignores an attempt to change the GRN, challan or challan date', function () {
    $item = purchaseItem();
    $line = recordedPurchase($item, 100);

    $this->actingAs(purchaseEditor())
        ->put(route('store.stock.purchases.update', $line), purchasePayload($line, [
            // None of these is a field of the form. A hand-crafted request
            // naming them must be ignored, or the line would silently leave its
            // delivery — groupKeyExpr() is exactly these three columns.
            'rv_no' => 'GRN-999',
            'challan_no' => 'CH-TAMPERED',
            'purchase_date' => '2020-01-01',
            'qty' => 90,
        ]))
        ->assertRedirect()
        ->assertSessionHas('success');

    $fresh = $line->fresh();

    expect($fresh->rv_no)->toBe('GRN-001')
        ->and($fresh->challan_no)->toBe('CH-77')
        ->and($fresh->purchase_date->toDateString())->toBe(now()->startOfMonth()->toDateString())
        // The editable field did change, so the request was honoured otherwise.
        ->and((float) $fresh->qty)->toBe(90.0);
});

it('keeps a delivery s lines grouped together after an edit', function () {
    $needle = purchaseItem();
    $thread = purchaseItem('Sewing Thread', 'Cone');

    $first = recordedPurchase($needle, 100);
    $second = recordedPurchase($thread, 50);

    $this->actingAs(purchaseEditor())
        ->put(route('store.stock.purchases.update', $first), purchasePayload($first, ['qty' => 70]))
        ->assertSessionHas('success');

    // Same three identity columns on both, so they are still one receiving.
    $a = $first->fresh();
    $b = $second->fresh();

    expect($a->rv_no)->toBe($b->rv_no)
        ->and($a->challan_no)->toBe($b->challan_no)
        ->and($a->purchase_date->toDateString())->toBe($b->purchase_date->toDateString());
});

it('refuses an RCV date earlier than the locked challan date', function () {
    $item = purchaseItem();
    $line = recordedPurchase($item, 100, [
        'purchase_date' => '2026-08-10',
        'rcv_date' => '2026-08-12',
    ]);

    $this->actingAs(purchaseEditor())
        ->put(route('store.stock.purchases.update', $line), [
            'qty' => 100,
            'rcv_date' => '2026-08-01',
        ])
        ->assertSessionHasErrors('rcv_date');

    expect($line->fresh()->rcv_date->toDateString())->toBe('2026-08-12');
});

// --- The balance rule, in reverse -------------------------------------------

it('lets a receipt be corrected when the stock is still on the shelf', function () {
    $item = purchaseItem();
    $line = recordedPurchase($item, 100);

    expect(purchaseBalance($item))->toBe(100.0);

    $this->actingAs(purchaseEditor())
        ->put(route('store.stock.purchases.update', $line), purchasePayload($line, ['qty' => 60]))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect((float) $line->fresh()->qty)->toBe(60.0)
        ->and(purchaseBalance($item))->toBe(60.0);
});

it('discounts the receipt s own quantity, so raising it is never refused', function () {
    // The self-exclusion, from the receiving side. The balance already counts
    // this receipt's 100; the question is what the figure becomes once the old
    // quantity is undone and the new one applied.
    $item = purchaseItem();
    $line = recordedPurchase($item, 100);

    StockIssue::create([
        'stock_item_id' => $item->id,
        'issue_date' => now()->startOfMonth()->toDateString(),
        'qty' => 100,
    ]);

    expect(purchaseBalance($item))->toBe(0.0);

    $this->actingAs(purchaseEditor())
        ->put(route('store.stock.purchases.update', $line), purchasePayload($line, ['qty' => 150]))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(purchaseBalance($item))->toBe(50.0);
});

it('refuses cutting a receipt below what has already been issued', function () {
    // 100 received, 60 issued. Cutting the receipt to 10 would leave -50, which
    // no report can present honestly.
    $item = purchaseItem();
    $line = recordedPurchase($item, 100);

    StockIssue::create([
        'stock_item_id' => $item->id,
        'issue_date' => now()->startOfMonth()->toDateString(),
        'qty' => 60,
    ]);

    $response = $this->actingAs(purchaseEditor())
        ->from(route('store.stock.purchases.index'))
        ->put(route('store.stock.purchases.update', $line), purchasePayload($line, ['qty' => 10]))
        ->assertRedirect()
        ->assertSessionHasErrors('qty');

    // Nothing written, balance untouched.
    expect((float) $line->fresh()->qty)->toBe(100.0)
        ->and(purchaseBalance($item))->toBe(40.0);

    // The message names the figure the user needs, not just a refusal.
    expect(session('errors')->first('qty'))
        ->toContain('Sewing Needle')
        ->toContain('60');

    // 60 exactly — the lowest it can go — still goes through.
    $this->actingAs(purchaseEditor())
        ->put(route('store.stock.purchases.update', $line), purchasePayload($line, ['qty' => 60]))
        ->assertSessionHasNoErrors();

    expect(purchaseBalance($item))->toBe(0.0);
});

// --- Line scope vs delivery scope -------------------------------------------

it('changes qty, price and remarks on the edited line only', function () {
    $needle = purchaseItem();
    $thread = purchaseItem('Sewing Thread', 'Cone');

    $first = recordedPurchase($needle, 100);
    $second = recordedPurchase($thread, 50);

    $this->actingAs(purchaseEditor())
        ->put(route('store.stock.purchases.update', $first), purchasePayload($first, [
            'qty' => 70,
            'unit_price' => 9.5,
            'remarks' => 'Short delivery, corrected',
        ]))
        ->assertSessionHas('success');

    expect((float) $first->fresh()->qty)->toBe(70.0)
        ->and((float) $first->fresh()->unit_price)->toBe(9.5)
        ->and($first->fresh()->remarks)->toBe('Short delivery, corrected')
        // The sibling is untouched.
        ->and((float) $second->fresh()->qty)->toBe(50.0)
        ->and((float) $second->fresh()->unit_price)->toBe(5.0)
        ->and($second->fresh()->remarks)->toBeNull();
});

it('writes RCV date and supplier to every line of the delivery', function () {
    // Both describe the delivery, not the line: they were entered once and
    // copied down. Letting one line disagree would leave the grouped history
    // row showing a MAX() that matches no line.
    $needle = purchaseItem();
    $thread = purchaseItem('Sewing Thread', 'Cone');

    $first = recordedPurchase($needle, 100);
    $second = recordedPurchase($thread, 50);

    $supplier = GeneralStockSupplier::create(['name' => 'Ideal Trading', 'is_active' => true]);

    $this->actingAs(purchaseEditor())
        ->put(route('store.stock.purchases.update', $first), purchasePayload($first, [
            'rcv_date' => now()->startOfMonth()->addDays(3)->toDateString(),
            'general_stock_supplier_id' => $supplier->id,
        ]))
        ->assertSessionHas('success');

    $expected = now()->startOfMonth()->addDays(3)->toDateString();

    foreach ([$first, $second] as $line) {
        $fresh = $line->fresh();

        expect($fresh->rcv_date->toDateString())->toBe($expected)
            ->and($fresh->general_stock_supplier_id)->toBe($supplier->id)
            // The name is copied, not joined, so the challan still reads
            // correctly if the supplier is renamed later.
            ->and($fresh->supplier_name)->toBe('Ideal Trading');
    }
});

it('does not reach into a different delivery', function () {
    $item = purchaseItem();

    $mine = recordedPurchase($item, 100);
    $other = recordedPurchase($item, 40, ['rv_no' => 'GRN-002', 'challan_no' => 'CH-88']);

    $supplier = GeneralStockSupplier::create(['name' => 'Ideal Trading', 'is_active' => true]);

    $this->actingAs(purchaseEditor())
        ->put(route('store.stock.purchases.update', $mine), purchasePayload($mine, [
            'general_stock_supplier_id' => $supplier->id,
        ]))
        ->assertSessionHas('success');

    expect($mine->fresh()->supplier_name)->toBe('Ideal Trading')
        ->and($other->fresh()->supplier_name)->toBeNull()
        ->and((float) $other->fresh()->qty)->toBe(40.0);
});

it('refuses an unknown supplier', function () {
    $item = purchaseItem();
    $line = recordedPurchase($item, 100);

    $this->actingAs(purchaseEditor())
        ->put(route('store.stock.purchases.update', $line), purchasePayload($line, [
            'general_stock_supplier_id' => 9999,
        ]))
        ->assertSessionHasErrors('general_stock_supplier_id');
});
