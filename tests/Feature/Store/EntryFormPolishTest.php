<?php

use App\Models\GeneralStockSupplier;
use App\Models\StockItem;
use App\Models\User;
use Spatie\Permission\Models\Permission;

/**
 * Record Issue and Record Receiving share one small polish (2026-09-29),
 * scoped to .gx-entry-form in _stock-ui so no other screen moves: one-line
 * labels so a header row lines up, one required mark, one control height, a
 * tighter line grid and a smaller Remove (icon + text).
 *
 * In-memory sqlite. No real record touched.
 */
function polishUser(): User
{
    $permissions = ['store.issues.view', 'store.issues.create', 'store.receiving.view', 'store.receiving.create'];
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }

    return tap(User::factory()->create(['status' => 1]))->givePermissionTo($permissions);
}

it('gives both entry forms the same polish', function (string $route, string $formId) {
    StockItem::create(['name' => 'Sewing Thread 40/2', 'uom' => 'Cone', 'category' => 'Thread', 'is_active' => true, 'opening_qty' => 0]);
    GeneralStockSupplier::firstOrCreate(['name' => 'Coats Bangladesh']);

    $html = $this->actingAs(polishUser())->get(route($route))->assertOk()->getContent();

    expect($html)->toMatch('/<form[^>]*id="'.$formId.'"[^>]*class="gx-entry-form"[^>]*novalidate/')
        // The shared rules reach the page.
        ->toContain('.gx-entry-form .row > [class*="col"] > .form-label')
        ->toContain('.gx-entry-form .gx-line-table > tbody > tr > td')
        ->toContain('td.gx-line-action :is(a, button).btn')
        // Remove: small, neutral outline, icon + text, red only on hover.
        ->toMatch('/class="btn btn-sm btn-outline-secondary gx-line-remove js-line-remove">\s*<i class="bi bi-trash me-1" aria-hidden="true"><\/i>Remove/')
        ->toContain('.gx-entry-form .gx-line-remove:hover:not(:disabled)')
        // Field tags stay on one line; the helper beside the buttons is one short line.
        ->toContain('.gx-entry-form .invalid-feedback')
        ->toMatch('/<p class="gx-stock-help gx-entry-help mb-0">[^<]{10,80}<\/p>/')
        // The page's own check tags fields briefly too.
        ->toContain("short: 'Required'")
        ->toContain("short: 'Same remark already used'");
})->with([
    'Record Issue' => ['store.stock.issues.index', 'issueForm'],
    'Record Receiving' => ['store.stock.purchases.index', 'purchaseForm'],
]);

it('shortens a server message for under the field', function (string $message, string $short) {
    expect(\App\Support\FieldMessage::short($message))->toBe($short);
})->with([
    ['Select or type the Indent Section.', 'Required'],
    ['Enter the Requisition Number.', 'Required'],
    ['The item on line 2 field is required.', 'Required'],
    ['Enter the unit price (0 if free of charge).', 'Required'],
    ['The issued qty on line 1 field must be at least 0.0001.', 'More than 0'],
    ['The unit price on line 1 field must be at least 0.', '0 or more'],
    ['This item is already on requisition REQ-1 with the same remark. Use a different remark to issue it again.', 'Same remark already used'],
    ['This item is already received on challan CH-1 from X with the same remark. For a further delivery, add a remark such as \'2nd delivery\'.', 'Same remark already used'],
    ['Sewing Thread — only 3 Pkt in stock, cannot issue 5.', 'Not enough stock'],
    ['The RCV date cannot be earlier than the challan date.', 'Before challan date'],
]);

it('shows the short tag under the field and the full sentence at the top after a refusal', function () {
    $item = StockItem::create(['name' => 'Sewing Thread 40/2', 'uom' => 'Cone', 'category' => 'Thread', 'is_active' => true, 'opening_qty' => 0]);
    \App\Models\StockPurchase::create(['stock_item_id' => $item->id, 'purchase_date' => now()->startOfMonth()->toDateString(),
        'rcv_date' => now()->startOfMonth()->toDateString(), 'qty' => 50, 'unit_price' => 1]);
    $user = polishUser();

    $this->actingAs($user)->from(route('store.stock.issues.index'))->post(route('store.stock.issues.store'), [
        'issue_date' => now()->toDateString(), 'indent_section_id' => '', 'indent_person_id' => 'new:Md Robeul',
        'issue_approver_id' => 'new:Store Head', 'requisition_no' => 'REQ-1',
        'items' => [['stock_item_id' => $item->id, 'qty' => 1, 'requisition_type' => 'New', 'remarks' => 'a']],
    ])->assertSessionHasErrors('indent_section_id');

    $this->actingAs($user)->get(route('store.stock.issues.index'))
        ->assertOk()
        // The sentence in the summary at the top...
        ->assertSee('Select or type the Indent Section.')
        // ...and only the tag under the field.
        ->assertSee('<div class="invalid-feedback d-block">Required</div>', false);
});
