<?php

use App\Models\StockIssue;
use App\Models\StockItem;
use App\Models\StockPurchase;
use App\Models\User;
use Spatie\Permission\Models\Permission;

/**
 * Record Issue must never be stopped silently (2026-09-29): clicking it sent
 * no request and showed nothing. The item <select> on each line is hidden by
 * TomSelect and was `required`; a hidden required field that the browser
 * finds empty blocks the submit without any message it can show.
 *
 * So the form is `novalidate`, no hidden field is `required`, the script's
 * own check (gxIssueFormProblems) says what is missing at the top and on the
 * line, and the server validates everything. These tests pin the markup and
 * the server half; the script's check was run against the rendered page with
 * Node (see the commit notes).
 *
 * In-memory sqlite. No real record touched.
 */
function ifsItem(float $stock = 10): StockItem
{
    $item = StockItem::create(['name' => '100% Cut Panel Inspection Report', 'uom' => 'Pkt', 'category' => 'Stationery', 'opening_qty' => 0]);
    StockPurchase::create([
        'stock_item_id' => $item->id, 'purchase_date' => now()->startOfMonth()->toDateString(),
        'rcv_date' => now()->startOfMonth()->toDateString(), 'qty' => $stock, 'unit_price' => 1,
    ]);

    return $item;
}

function ifsUser(): User
{
    foreach (['store.issues.view', 'store.issues.create'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    return tap(User::factory()->create(['status' => 1]))->givePermissionTo(['store.issues.view', 'store.issues.create']);
}

it('renders the Record Issue form so the browser can never block it silently', function () {
    ifsItem();
    $html = $this->actingAs(ifsUser())->get(route('store.stock.issues.index'))->assertOk()->getContent();

    // The form leaves checking to the script and the server.
    expect($html)->toMatch('/<form[^>]*id="issueForm"[^>]*\snovalidate[\s>]/');

    // No field TomSelect hides is `required`: the line's item and category selects.
    preg_match('/<template id="issueLineTemplate">(.*?)<\/template>/s', $html, $template);
    preg_match_all('/<select[^>]*class="[^"]*js-line-(item|category)[^"]*"[^>]*>/', $template[1], $selects);
    expect($selects[0])->toHaveCount(2);
    foreach ($selects[0] as $tag) {
        expect($tag)->not->toMatch('/\srequired[\s>=]/');
    }

    // The script's own check, and Save Draft still skipping every check.
    expect($html)->toContain('window.gxIssueFormProblems = function (issueDate, lines, header)')
        ->toContain('Please complete the form before recording the issue.')
        ->toMatch('/formaction="[^"]*issues\/drafts"\s+formnovalidate/');
});

it('records the reported form once the header is filled: two lines of one item, qty 1 and 1', function () {
    $item = ifsItem();

    $this->actingAs(ifsUser())->from(route('store.stock.issues.index'))->post(route('store.stock.issues.store'), [
        'issue_date' => now()->toDateString(),
        'indent_section_id' => 'new:Cutting', 'indent_person_id' => 'new:Md Robeul', 'issue_approver_id' => 'new:Store Head', 'requisition_no' => 'REQ-77',
        'items' => [
            ['stock_item_id' => $item->id, 'qty' => '1', 'requisition_type' => 'New', 'remarks' => 'urgent'],
            ['stock_item_id' => $item->id, 'qty' => '1', 'requisition_type' => 'New', 'remarks' => 'ggg'],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(StockIssue::pluck('remarks')->all())->toBe(['urgent', 'ggg']);
});

it('answers a line posted without an item with a message at the top and on the line', function () {
    $item = ifsItem();
    $user = ifsUser();

    $this->actingAs($user)->from(route('store.stock.issues.index'))->post(route('store.stock.issues.store'), [
        'issue_date' => now()->toDateString(),
        'indent_section_id' => 'new:Cutting', 'indent_person_id' => 'new:Md Robeul', 'issue_approver_id' => 'new:Store Head', 'requisition_no' => 'REQ-77',
        'items' => [
            ['stock_item_id' => $item->id, 'qty' => '1', 'requisition_type' => 'New'],
            ['stock_item_id' => '', 'qty' => '1', 'requisition_type' => 'New'],
        ],
    ])->assertRedirect()->assertSessionHasErrors('items.1.stock_item_id');

    expect(StockIssue::count())->toBe(0);

    $this->actingAs($user)->get(route('store.stock.issues.index'))
        ->assertOk()
        ->assertSee('Please correct this:')
        ->assertSee('The item on line 2 field is required.');
});
