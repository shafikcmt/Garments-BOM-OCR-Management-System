<?php

use App\Models\PurchaseRequisition;
use App\Models\StockItem;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Purchase Requisition entry form (create and edit) must never be stopped
 * silently — the same fix as Record Issue and Record Receiving (2026-10-05).
 * Each line's item <select> is hidden by TomSelect and was `required`; a
 * hidden required field the browser finds empty blocks the submit without
 * any message it can show.
 *
 * So the form is `novalidate`, no hidden field is `required` (aria-required
 * stays), the page's own check (gxRequisitionFormProblems) says what is
 * missing at the top and on the line, Save as Draft is never stopped by the
 * page, and the server validates everything. These tests pin the markup and
 * the server half; the script's check was run with Node (commit notes).
 *
 * In-memory sqlite. No real record touched.
 */
// Stage engine: every approval stage has a person, as Admin would set it.
beforeEach(fn () => $this->approvers = requisitionApprovers());

function rfsUser(): User
{
    $user = User::factory()->create()->assignRole(Role::findOrCreate('store', 'web'));

    foreach (['store.requisition.view', 'store.requisition.create'] as $p) {
        $user->givePermissionTo(Permission::findOrCreate($p, 'web'));
    }

    return $user;
}

function rfsItem(string $name = 'Sewing Thread 40/2'): StockItem
{
    return StockItem::firstOrCreate(['name' => $name], ['uom' => 'Cone', 'is_active' => true]);
}

function rfsPayload(array $lines, string $action = 'submit'): array
{
    return [
        'action' => $action,
        'mode' => PurchaseRequisition::MODE_MULTI,
        'requisition_date' => '2026-10-05',
        'remarks' => 'For November',
        'items' => $lines,
    ];
}

/** The page markup the browser would block on, for one rendered form. */
function rfsAssertNeverBlocksSilently(string $html): void
{
    // The form leaves checking to the script and the server.
    expect($html)->toMatch('/<form[^>]*id="requisitionForm"[^>]*\snovalidate[\s>]/');

    // The line's item select (hidden by TomSelect) is never `required`, but
    // still tells assistive technology that it is.
    $start = strpos($html, 'id="requisitionLineTemplate"');
    $template = substr($html, $start, strpos($html, '</template>', $start) - $start);
    preg_match_all('/<select[^>]*class="[^"]*js-line-item[^"]*"[^>]*>/', $template, $selects);
    expect($selects[0])->toHaveCount(1);
    expect($selects[0][0])->not->toMatch('/\srequired[\s>=]/')->toContain('aria-required="true"');

    // Nothing in the line template is `required`.
    expect($template)->not->toMatch('/\srequired[\s>=]/');

    // The page's own check, its summary, the sync, and Save as Draft skipping it.
    expect($html)->toContain('window.gxRequisitionFormProblems = function (requisitionDate, lines)')
        ->toContain('id="requisitionFormBlock"')
        ->toContain('Please complete the form before submitting the requisition.')
        ->toContain('syncItemSelects();')
        ->toMatch('/name="action" value="draft"[^>]*\sformnovalidate[\s>]/');
}

it('renders the New Requisition form so the browser can never block it silently', function () {
    rfsItem();

    rfsAssertNeverBlocksSilently(
        $this->actingAs(rfsUser())->get(route('store.stock.requisitions.create'))->assertOk()->getContent()
    );
});

it('renders the Edit Requisition form so the browser can never block it silently', function () {
    $user = rfsUser();
    $item = rfsItem();

    $this->actingAs($user)->post(route('store.stock.requisitions.store'), rfsPayload([
        ['stock_item_id' => $item->id, 'qty_requested' => '5', 'type' => 'new'],
    ], 'draft'))->assertSessionHasNoErrors();

    $sheet = PurchaseRequisition::latest('id')->firstOrFail();

    rfsAssertNeverBlocksSilently(
        $this->actingAs($user)->get(route('store.stock.requisitions.edit', $sheet))->assertOk()->getContent()
    );
});

it('submits a filled form: a master line and a typed line', function () {
    $item = rfsItem();

    $this->actingAs(rfsUser())->post(route('store.stock.requisitions.store'), rfsPayload([
        ['stock_item_id' => $item->id, 'qty_requested' => '5', 'type' => 'new', 'remarks' => 'urgent'],
        ['stock_item_id' => '', 'custom_item_name' => 'Laser Pointer', 'qty_requested' => '1', 'type' => 'new'],
    ]))->assertRedirect()->assertSessionHasNoErrors();

    $sheet = PurchaseRequisition::latest('id')->firstOrFail();
    expect($sheet->status)->not->toBe(PurchaseRequisition::STATUS_DRAFT)
        ->and($sheet->items)->toHaveCount(2);
});

it('answers a line posted without an item with one message, at the top and on the line, keeping what was typed', function () {
    $item = rfsItem();
    $user = rfsUser();

    $this->actingAs($user)->from(route('store.stock.requisitions.create'))->post(route('store.stock.requisitions.store'), rfsPayload([
        ['stock_item_id' => $item->id, 'qty_requested' => '5', 'type' => 'new', 'remarks' => 'urgent'],
        ['stock_item_id' => '', 'custom_item_name' => '', 'qty_requested' => '3', 'type' => 'new', 'remarks' => 'for cutting'],
    ]))->assertRedirect(route('store.stock.requisitions.create'))
        ->assertSessionHasErrors('items.1.stock_item_id')
        // One rule refuses an empty line, so the summary says it once.
        ->assertSessionDoesntHaveErrors('items.1.custom_item_name');

    expect(PurchaseRequisition::count())->toBe(0);

    $html = $this->actingAs($user)->get(route('store.stock.requisitions.create'))->assertOk()->getContent();

    // The red summary at the top, in plain words.
    expect($html)->toContain('Please correct this:')
        ->toContain('Select the item on line 2, or tick &quot;Not in Item Master&quot; and type its name.');

    // Everything typed comes back, and line 2 carries its short tag.
    expect($html)->toContain('"remarks":"urgent"')
        ->toContain('"remarks":"for cutting"')
        ->toContain('"qty_requested":"3"')
        ->toContain('For November')
        ->toContain('"errors":{"item":"Required"}');
});

it('answers a qty of 0 with "more than 0" on the line', function () {
    $item = rfsItem();
    $user = rfsUser();

    $this->actingAs($user)->from(route('store.stock.requisitions.create'))->post(route('store.stock.requisitions.store'), rfsPayload([
        ['stock_item_id' => $item->id, 'qty_requested' => '0', 'type' => 'new'],
    ]))->assertSessionHasErrors(['items.0.qty_requested' => 'The qty requested on line 1 must be more than 0.']);

    expect($this->actingAs($user)->get(route('store.stock.requisitions.create'))->getContent())
        ->toContain('"errors":{"qty":"More than 0"}');
});

it('saves a draft as before; the server still checks a draft line', function () {
    $item = rfsItem();
    $user = rfsUser();

    $this->actingAs($user)->post(route('store.stock.requisitions.store'), rfsPayload([
        ['stock_item_id' => $item->id, 'qty_requested' => '2', 'type' => 'new'],
    ], 'draft'))->assertRedirect()->assertSessionHasNoErrors();

    expect(PurchaseRequisition::latest('id')->first()->status)->toBe(PurchaseRequisition::STATUS_DRAFT);

    // A draft line with no item is still refused by the server, typed data kept.
    $this->actingAs($user)->from(route('store.stock.requisitions.create'))->post(route('store.stock.requisitions.store'), rfsPayload([
        ['stock_item_id' => '', 'qty_requested' => '2', 'remarks' => 'keep me'],
    ], 'draft'))->assertSessionHasErrors('items.0.stock_item_id');

    expect(PurchaseRequisition::count())->toBe(1)
        ->and($this->actingAs($user)->get(route('store.stock.requisitions.create'))->getContent())->toContain('"remarks":"keep me"');
});

it('keeps the line errors on an edited requisition refused by the server', function () {
    $user = rfsUser();
    $item = rfsItem();

    $this->actingAs($user)->post(route('store.stock.requisitions.store'), rfsPayload([
        ['stock_item_id' => $item->id, 'qty_requested' => '5', 'type' => 'new'],
    ], 'draft'))->assertSessionHasNoErrors();

    $sheet = PurchaseRequisition::latest('id')->firstOrFail();

    $this->actingAs($user)->from(route('store.stock.requisitions.edit', $sheet))
        ->put(route('store.stock.requisitions.update', $sheet), rfsPayload([
            ['id' => $sheet->items->first()->id, 'stock_item_id' => $item->id, 'qty_requested' => '', 'type' => 'new', 'remarks' => 'edited'],
        ]))->assertRedirect(route('store.stock.requisitions.edit', $sheet))
        ->assertSessionHasErrors('items.0.qty_requested');

    expect($sheet->fresh()->status)->toBe(PurchaseRequisition::STATUS_DRAFT);

    expect($this->actingAs($user)->get(route('store.stock.requisitions.edit', $sheet))->getContent())
        ->toContain('"remarks":"edited"')
        ->toContain('"errors":{"qty":"Required"}');
});

it('silently drops fully blank lines on Submit and on Draft, and keeps partly filled ones to be checked', function () {
    $item = rfsItem();
    $user = rfsUser();
    $blank = ['stock_item_id' => '', 'custom_item_name' => '', 'qty_requested' => '', 'remarks' => '', 'type' => 'new'];

    foreach (['submit', 'draft'] as $action) {
        $this->actingAs($user)->post(route('store.stock.requisitions.store'), rfsPayload([
            $blank,
            ['stock_item_id' => $item->id, 'qty_requested' => '5', 'type' => 'new'],
            $blank,
        ], $action))->assertRedirect()->assertSessionHasNoErrors();

        expect(PurchaseRequisition::latest('id')->firstOrFail()->items)->toHaveCount(1);
    }

    // A partly filled line (qty only) is not blank: still refused, named by its position among kept lines.
    $this->actingAs($user)->from(route('store.stock.requisitions.create'))->post(route('store.stock.requisitions.store'), rfsPayload([
        $blank,
        ['stock_item_id' => '', 'qty_requested' => '3', 'type' => 'new'],
    ]))->assertSessionHasErrors('items.0.stock_item_id');

    expect(PurchaseRequisition::count())->toBe(2);
});

it('answers "Add at least one item" when every line is blank', function () {
    $blank = ['stock_item_id' => '', 'custom_item_name' => '', 'qty_requested' => '', 'remarks' => '', 'type' => 'new'];

    $this->actingAs(rfsUser())->from(route('store.stock.requisitions.create'))->post(route('store.stock.requisitions.store'), rfsPayload([$blank, $blank]))
        ->assertSessionHasErrors(['items' => 'Add at least one item to the requisition.']);

    expect(PurchaseRequisition::count())->toBe(0);
});
