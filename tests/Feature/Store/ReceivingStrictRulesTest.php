<?php

use App\Models\GeneralStockSupplier;
use App\Models\StockItem;
use App\Models\StockPurchase;
use App\Models\StoreFormDraft;
use App\Models\User;
use Spatie\Permission\Models\Permission;

/**
 * Record Receiving — the same approach as Record Issue (owner, 2026-09-29):
 *
 *   A. Never blocked silently: the form is novalidate, no hidden field is
 *      `required`, the page's own check (gxReceivingFormProblems) and the
 *      server say what is missing. Save Draft stays lenient.
 *   B. Required: Challan Date, RCV Date, Challan / Invoice No, Supplier; on
 *      each line Item, Qty > 0 and Unit Price >= 0 (0 allowed). The same item
 *      on one challan from one supplier only with a different remark — in
 *      the form and against receipts already recorded; an edited line is
 *      not its own twin.
 *
 * In-memory sqlite. No real record touched.
 */
function rsItem(string $name = 'Sewing Thread 40/2'): StockItem
{
    return StockItem::create(['name' => $name, 'uom' => 'Cone', 'category' => 'Thread', 'is_active' => true, 'opening_qty' => 0]);
}

function rsSupplier(string $name = 'Coats Bangladesh'): GeneralStockSupplier
{
    return GeneralStockSupplier::firstOrCreate(['name' => $name]);
}

function rsUser(): User
{
    $permissions = ['store.receiving.view', 'store.receiving.create', 'store.receiving.edit'];
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }

    return tap(User::factory()->create(['status' => 1]))->givePermissionTo($permissions);
}

/** A complete, valid Record Receiving post; $overrides replace header keys. */
function rsPayload(StockItem $item, array $lines, array $overrides = []): array
{
    return array_merge([
        'purchase_date' => now()->toDateString(),
        'rcv_date' => now()->toDateString(),
        'challan_no' => 'CH-48699',
        'general_stock_supplier_id' => rsSupplier()->id,
        'items' => array_map(fn ($line) => $line + ['stock_item_id' => $item->id, 'qty' => 5, 'unit_price' => 120], $lines),
    ], $overrides);
}

function rsPost(User $user, array $payload)
{
    return test()->actingAs($user)->from(route('store.stock.purchases.index'))->post(route('store.stock.purchases.store'), $payload);
}

const RS_REPEAT = 'This item is already received on challan CH-48699 from Coats Bangladesh with the same remark. For a further delivery, add a remark such as \'2nd delivery\'.';

// --- Required fields -------------------------------------------------------------

it('refuses a Record Receiving with a required field empty, with its message', function (string $field, ?string $message) {
    $payload = rsPayload(rsItem(), [['remarks' => 'first lot']]);

    if (str_starts_with($field, 'items.0.')) {
        $payload['items'][0][substr($field, 8)] = '';
    } else {
        $payload[$field] = '';
    }

    rsPost(rsUser(), $payload)->assertRedirect()->assertSessionHasErrors($message ? [$field => $message] : [$field]);
    expect(StockPurchase::count())->toBe(0);
})->with([
    'Challan Date' => ['purchase_date', null],
    'RCV Date' => ['rcv_date', null],
    'Challan / Invoice No' => ['challan_no', 'Enter the Challan / Invoice No.'],
    'Supplier' => ['general_stock_supplier_id', 'Select the supplier.'],
    'Item' => ['items.0.stock_item_id', null],
    'Qty' => ['items.0.qty', null],
    'Unit Price' => ['items.0.unit_price', 'Enter the unit price (0 if free of charge).'],
]);

it('accepts a unit price of 0, and refuses a qty of 0', function () {
    $item = rsItem();

    rsPost(rsUser(), rsPayload($item, [['unit_price' => 0, 'remarks' => 'free sample']]))->assertSessionHasNoErrors();
    rsPost(rsUser(), rsPayload($item, [['qty' => 0, 'remarks' => 'x']]))->assertSessionHasErrors('items.0.qty');

    expect(StockPurchase::count())->toBe(1)->and((float) StockPurchase::first()->unit_price)->toBe(0.0);
});

it('keeps what was typed and shows the messages at the top and under the fields', function () {
    $user = rsUser();
    rsPost($user, rsPayload(rsItem(), [['remarks' => 'first lot']], ['challan_no' => '', 'general_stock_supplier_id' => '']));

    $this->actingAs($user)->get(route('store.stock.purchases.index'))
        ->assertOk()
        ->assertSee('Please correct the following:')
        ->assertSee('Enter the Challan / Invoice No.')
        ->assertSee('Select the supplier.')
        ->assertSee('"remarks":"first lot"', false);
});

it('saves a draft with the required fields empty', function () {
    $this->actingAs(rsUser())->post(route('store.stock.purchases.drafts.save'), [
        'items' => [['stock_item_id' => rsItem()->id, 'qty' => 2]],
    ])->assertRedirect()->assertSessionHas('success');

    expect(StoreFormDraft::count())->toBe(1)->and(StockPurchase::count())->toBe(0);
});

// --- The repeat rule ---------------------------------------------------------------

it('saves the same item twice on one challan when the remarks differ', function () {
    rsPost(rsUser(), rsPayload(rsItem(), [['remarks' => 'first lot'], ['remarks' => '2nd delivery']]))->assertSessionHasNoErrors();

    expect(StockPurchase::count())->toBe(2);
});

it('refuses the same remark again, whatever the case and spacing, and both empty', function () {
    $item = rsItem();

    rsPost(rsUser(), rsPayload($item, [['remarks' => 'First lot'], ['remarks' => '  first   LOT ']]))
        ->assertSessionHasErrors(['items.1.remarks' => RS_REPEAT]);
    rsPost(rsUser(), rsPayload($item, [['remarks' => ''], ['remarks' => null]]))
        ->assertSessionHasErrors('items.1.remarks');

    expect(StockPurchase::count())->toBe(0);
});

it('refuses a line repeating a receipt already recorded on that challan from that supplier', function () {
    $item = rsItem();
    $user = rsUser();
    rsPost($user, rsPayload($item, [['remarks' => 'first lot']]))->assertSessionHasNoErrors();

    // Same item, challan (other case/spacing) and supplier, same remark: refused.
    rsPost($user, rsPayload($item, [['remarks' => ' FIRST LOT ']], ['challan_no' => ' ch-48699 ']))
        ->assertSessionHasErrors('items.0.remarks');

    // A further delivery that says so, another supplier, another challan: saved.
    rsPost($user, rsPayload($item, [['remarks' => '2nd delivery']]))->assertSessionHasNoErrors();
    rsPost($user, rsPayload($item, [['remarks' => 'first lot']], ['general_stock_supplier_id' => rsSupplier('Other Supplier')->id]))->assertSessionHasNoErrors();
    rsPost($user, rsPayload($item, [['remarks' => 'first lot']], ['challan_no' => 'CH-50000']))->assertSessionHasNoErrors();

    expect(StockPurchase::count())->toBe(4);
});

// --- Edit ----------------------------------------------------------------------------

it('does not block an edit by the line itself, and refuses one that repeats another receipt', function () {
    $item = rsItem();
    $user = rsUser();
    rsPost($user, rsPayload($item, [['remarks' => 'first lot'], ['remarks' => '2nd delivery']]))->assertSessionHasNoErrors();
    [$first, $second] = StockPurchase::orderBy('id')->get()->all();

    $edit = fn (StockPurchase $line, array $overrides) => array_merge([
        'rcv_date' => $line->rcv_date->toDateString(), 'qty' => 5, 'unit_price' => 120,
        'general_stock_supplier_id' => rsSupplier()->id, 'remarks' => $line->remarks,
    ], $overrides);

    $this->actingAs($user)->put(route('store.stock.purchases.update', $first), $edit($first, ['qty' => 6]))
        ->assertSessionHasNoErrors()->assertSessionHas('success');
    expect((float) $first->fresh()->qty)->toBe(6.0);

    $this->actingAs($user)->put(route('store.stock.purchases.update', $second), $edit($second, ['remarks' => 'First Lot']))
        ->assertSessionHasErrors(['remarks' => RS_REPEAT]);
    expect($second->fresh()->remarks)->toBe('2nd delivery');
});

it('makes an old line with an empty supplier or price fill it when it is edited', function (string $field) {
    $item = rsItem();
    $old = StockPurchase::create([
        'stock_item_id' => $item->id, 'rv_no' => 'GRN-OLD', 'purchase_date' => now()->toDateString(),
        'rcv_date' => now()->toDateString(), 'qty' => 5,
    ]);

    $this->actingAs(rsUser())->put(route('store.stock.purchases.update', $old), array_merge([
        'rcv_date' => $old->rcv_date->toDateString(), 'qty' => 5, 'unit_price' => 10, 'general_stock_supplier_id' => rsSupplier()->id,
    ], [$field => '']))->assertSessionHasErrors($field);

    expect($old->fresh()->general_stock_supplier_id)->toBeNull()->and($old->fresh()->unit_price)->toBeNull();
})->with(['general_stock_supplier_id', 'unit_price']);

// --- The page ------------------------------------------------------------------------

it('renders the form so the browser can never block it silently, with the check and the markers', function () {
    rsItem();
    rsSupplier();
    $html = $this->actingAs(rsUser())->get(route('store.stock.purchases.index'))->assertOk()->getContent();

    expect($html)->toMatch('/<form[^>]*id="purchaseForm"[^>]*\snovalidate[\s>]/');

    preg_match('/<template id="purchaseLineTemplate">(.*?)<\/template>/s', $html, $template);
    preg_match('/<select[^>]*js-line-item[^>]*>/', $template[1], $select);
    expect($select[0])->not->toMatch('/\srequired[\s>=]/');

    expect($html)->toContain('for="challanNo">Challan / Invoice No <span class="text-danger">*</span>')
        ->toContain('for="supplierSelect">Supplier Name <span class="text-danger">*</span>')
        ->toContain('<th>Unit Price <span class="text-danger">*</span></th>')
        ->toContain('window.gxReceivingFormProblems = function (header, lines)')
        ->toContain('Please complete the form before recording the receiving.')
        ->toContain("For a further delivery, add a remark such as \\'2nd delivery\\'.")
        ->toMatch('/formaction="[^"]*purchases\/drafts"\s+formnovalidate/')
        ->toMatch('/<select id="purchaseFilterMonth" name="month" class="form-select">\s*<option value="">All months<\/option>/');
});
