<?php

use App\Models\StockIssue;
use App\Models\StockItem;
use App\Models\StockPurchase;
use App\Models\User;
use Spatie\Permission\Models\Permission;

/**
 * One requisition, the same item on several lines — e.g. issued for two
 * buyers, with a remark each. There is no duplicate rule (none existed, and
 * none is added — owner, 2026-09-29): every line saves as its own issue.
 * What IS checked is stock, on the item's TOTAL across the lines, and the
 * message says so: "… is on 2 lines (6 + 5 = 11); only 3 Pkt in stock." — on
 * each affected line and once at the top of the form.
 *
 * In-memory sqlite. No real record touched.
 */
function silItem(float $stock): StockItem
{
    $item = StockItem::create(['name' => '100% Cut Panel Inspection Report', 'uom' => 'Pkt', 'category' => 'Stationery', 'opening_qty' => 0]);

    StockPurchase::create([
        'stock_item_id' => $item->id,
        'purchase_date' => now()->startOfMonth()->toDateString(),
        'rcv_date' => now()->startOfMonth()->toDateString(),
        'qty' => $stock, 'unit_price' => 1,
    ]);

    return $item;
}

function silIssuer(): User
{
    foreach (['store.issues.view', 'store.issues.create'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    return tap(User::factory()->create(['status' => 1]))->givePermissionTo(['store.issues.view', 'store.issues.create']);
}

function silPost(User $user, StockItem $item, array $lines)
{
    return test()->actingAs($user)->from(route('store.stock.issues.index'))->post(route('store.stock.issues.store'), [
        'issue_date' => now()->toDateString(),
        'requisition_no' => 'HAPL/CUTTING/2026/September/04',
        'items' => array_map(fn ($line) => ['stock_item_id' => $item->id, 'qty' => $line[0], 'remarks' => $line[1] ?? null], $lines),
    ]);
}

it('saves the same item twice on one requisition number when the remarks differ', function () {
    $item = silItem(10);

    silPost(silIssuer(), $item, [[5, 'issue for buyer A'], [2, 'issue for buyer B']])
        ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');

    $issues = StockIssue::orderBy('id')->get();
    expect($issues)->toHaveCount(2)
        ->and($issues->pluck('remarks')->all())->toBe(['issue for buyer A', 'issue for buyer B'])
        ->and($issues->pluck('requisition_no')->unique()->all())->toBe(['HAPL/CUTTING/2026/September/04'])
        ->and((float) $issues->sum('qty'))->toBe(7.0);
});

it('still saves the same item on two lines with empty remarks, as before', function () {
    $item = silItem(10);

    silPost(silIssuer(), $item, [[3, null], [4, '']])->assertSessionHasNoErrors();

    expect(StockIssue::count())->toBe(2);
});

it('refuses two lines whose total is above stock, naming the lines and the total', function () {
    $item = silItem(3);
    $user = silIssuer();

    $message = '100% Cut Panel Inspection Report is on 2 lines (6 + 5 = 11); only 3 Pkt in stock.';

    silPost($user, $item, [[6, 'issue for buyer A'], [5, 'issue for buyer B']])
        ->assertRedirect()
        // On each affected line…
        ->assertSessionHasErrors(['items.0.qty' => $message, 'items.1.qty' => $message])
        // …and once for the summary at the top of the form.
        ->assertSessionHas('issue_stock_errors', [$message]);

    expect(StockIssue::count())->toBe(0);

    // The summary is drawn above the lines on the redirected form.
    $this->actingAs($user)->get(route('store.stock.issues.index'))
        ->assertOk()->assertSee('Not enough stock to issue.')->assertSee($message);
});

it('saves two lines whose total is exactly the stock', function () {
    $item = silItem(11);

    silPost(silIssuer(), $item, [[6, 'issue for buyer A'], [5, 'issue for buyer B']])->assertSessionHasNoErrors();

    expect((float) StockIssue::sum('qty'))->toBe(11.0);
});

it('keeps the one-line message for an item on a single line', function () {
    $item = silItem(3);

    silPost(silIssuer(), $item, [[5, null]])
        ->assertSessionHasErrors(['items.0.qty' => '100% Cut Panel Inspection Report — only 3 Pkt in stock, cannot issue 5.']);
});
