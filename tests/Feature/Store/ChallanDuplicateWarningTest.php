<?php

use App\Models\GeneralStockSupplier;
use App\Models\StockItem;
use App\Models\StockPurchase;
use App\Models\User;
use Spatie\Permission\Models\Permission;

/**
 * Warning that a challan number has been recorded before.
 *
 * The gap it fills: every Record Receiving submission takes a fresh GRN, by
 * design, because the same challan can genuinely arrive in two batches. So two
 * identical entries become two deliveries and two additions to stock, and
 * nothing anywhere complains — which is exactly how AUG26-0015 and AUG26-0016
 * happened.
 *
 * It WARNS and never blocks, so these assert what it reports rather than what
 * it prevents. Read-only throughout: the endpoint writes nothing.
 *
 * In-memory sqlite. No real record touched.
 */
function challanItem(string $name = 'Sewing Needle'): StockItem
{
    return StockItem::create(['name' => $name, 'uom' => 'Pkt', 'category' => 'Needle', 'opening_qty' => 0]);
}

function challanUser(array $permissions = ['store.receiving.view', 'store.receiving.create']): User
{
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $user = User::factory()->create(['status' => 1]);
    $user->givePermissionTo($permissions);

    return $user;
}

function recordChallan(StockItem $item, string $challan, array $overrides = []): StockPurchase
{
    return StockPurchase::create(array_merge([
        'stock_item_id' => $item->id,
        'rv_no' => 'AUG26-0015',
        'challan_no' => $challan,
        'purchase_date' => '2026-08-13',
        'rcv_date' => '2026-08-15',
        'qty' => 3,
        'unit_price' => 750,
    ], $overrides));
}

it('reports a delivery already recorded under the same challan', function () {
    $item = challanItem();
    recordChallan($item, '48699');

    $response = $this->actingAs(challanUser())
        ->getJson(route('store.stock.purchases.challan-status', ['challan_no' => '48699']))
        ->assertOk();

    $matches = $response->json('matches');

    expect($matches)->toHaveCount(1)
        ->and($matches[0]['rv_no'])->toBe('AUG26-0015')
        ->and($matches[0]['purchase_date'])->toBe('13-Aug-2026')
        ->and($matches[0]['lines'])->toBe(1)
        // Cast, because JSON writes a whole float as 3 rather than 3.0.
        ->and((float) $matches[0]['qty'])->toBe(3.0)
        ->and($matches[0]['items'])->toContain('Sewing Needle');
});

it('says nothing about a challan number never used', function () {
    challanItem();

    $this->actingAs(challanUser())
        ->getJson(route('store.stock.purchases.challan-status', ['challan_no' => 'NEW-999']))
        ->assertOk()
        ->assertJson(['matches' => []]);
});

it('says nothing when the challan box is empty', function () {
    // Typing and deleting must not report every delivery in the table.
    challanItem();
    recordChallan(challanItem('Other'), '48699');

    $this->actingAs(challanUser())
        ->getJson(route('store.stock.purchases.challan-status', ['challan_no' => '']))
        ->assertOk()
        ->assertJson(['matches' => []]);
});

it('matches a challan number whatever its letter case', function () {
    // The check has to survive PostgreSQL, where a plain LIKE is
    // case-sensitive and "ch158" would silently find nothing on live while
    // working perfectly here.
    $item = challanItem();
    recordChallan($item, 'CH158');

    $matches = $this->actingAs(challanUser())
        ->getJson(route('store.stock.purchases.challan-status', ['challan_no' => 'ch158']))
        ->assertOk()
        ->json('matches');

    expect($matches)->toHaveCount(1)
        ->and($matches[0]['rv_no'])->toBe('AUG26-0015');
});

it('counts one delivery, not one line, for a multi-item challan', function () {
    // The operator is being told about receivings. A three-item challan is one
    // of them, and three warnings for it would read as three deliveries.
    $needle = challanItem();
    $thread = challanItem('Sewing Thread');
    $tape = challanItem('Masking Tape');

    foreach ([$needle, $thread, $tape] as $item) {
        recordChallan($item, '48699', ['qty' => 5]);
    }

    $matches = $this->actingAs(challanUser())
        ->getJson(route('store.stock.purchases.challan-status', ['challan_no' => '48699']))
        ->json('matches');

    expect($matches)->toHaveCount(1)
        ->and($matches[0]['lines'])->toBe(3)
        ->and((float) $matches[0]['qty'])->toBe(15.0);
});

it('reports both GRNs when one challan really was entered twice', function () {
    // The AUG26-0015 / AUG26-0016 case, which is the reason this exists.
    $item = challanItem();
    recordChallan($item, '48699', ['rv_no' => 'AUG26-0015']);
    recordChallan($item, '48699', ['rv_no' => 'AUG26-0016']);

    $matches = $this->actingAs(challanUser())
        ->getJson(route('store.stock.purchases.challan-status', ['challan_no' => '48699']))
        ->json('matches');

    expect($matches)->toHaveCount(2)
        ->and(collect($matches)->pluck('rv_no')->all())->toContain('AUG26-0015', 'AUG26-0016');
});

it('ignores the same number from a different supplier', function () {
    // Two suppliers numbering their own challans is a coincidence, not a
    // repeat, and warning about it would train people to dismiss this.
    $item = challanItem();
    $mine = GeneralStockSupplier::create(['name' => 'A.I Sewing Center', 'is_active' => true]);
    $other = GeneralStockSupplier::create(['name' => 'Ideal Trading', 'is_active' => true]);

    recordChallan($item, '48699', ['general_stock_supplier_id' => $other->id, 'supplier_name' => 'Ideal Trading']);

    $matches = $this->actingAs(challanUser())
        ->getJson(route('store.stock.purchases.challan-status', [
            'challan_no' => '48699',
            'general_stock_supplier_id' => $mine->id,
        ]))
        ->json('matches');

    expect($matches)->toBeEmpty();

    // ...and reports it once the right supplier is named.
    $sameSupplier = $this->actingAs(challanUser())
        ->getJson(route('store.stock.purchases.challan-status', [
            'challan_no' => '48699',
            'general_stock_supplier_id' => $other->id,
        ]))
        ->json('matches');

    expect($sameSupplier)->toHaveCount(1);
});

it('records a further delivery against the same challan when it carries its own remark', function () {
    // A repeated challan is ordinary in a partial receipt. Since 2026-09-29 the
    // same item again on that challan from that supplier says so in its
    // remark (ReceivingStrictRulesTest has the refusal).
    $item = challanItem();
    $supplier = \App\Models\GeneralStockSupplier::firstOrCreate(['name' => 'Test Supplier']);
    recordChallan($item, '48699', ['general_stock_supplier_id' => $supplier->id]);

    $this->actingAs(challanUser())->post(route('store.stock.purchases.store'), [
        'purchase_date' => '2026-08-13',
        'rcv_date' => '2026-08-15',
        'challan_no' => '48699',
        'general_stock_supplier_id' => $supplier->id,
        'items' => [['stock_item_id' => $item->id, 'qty' => 3, 'unit_price' => 750, 'remarks' => '2nd delivery']],
    ])->assertRedirect()->assertSessionHas('success');

    expect(StockPurchase::where('challan_no', '48699')->count())->toBe(2);
});

it('carries the warning field and its lookup url on the form', function () {
    challanItem();

    $this->actingAs(challanUser())
        ->get(route('store.stock.purchases.index'))
        ->assertOk()
        ->assertSee('challanDuplicateNotice', false)
        ->assertSee('data-challan-status-url', false)
        ->assertSee('This challan number is already recorded');
});

it('needs the receiving view right to look a challan up', function () {
    challanItem();

    $outsider = User::factory()->create(['status' => 1]);

    $this->actingAs($outsider)
        ->getJson(route('store.stock.purchases.challan-status', ['challan_no' => '48699']))
        ->assertForbidden();
});
