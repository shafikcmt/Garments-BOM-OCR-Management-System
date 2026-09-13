<?php

use App\Models\PurchaseRequisitionItem;
use App\Models\StockItem;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * New Purchase Requisition — the order of the fields on an item line, and the
 * Type default.
 *
 * Every row on this screen — the first one, an "Add Another Item" row, a row
 * that bounced back from validation, a saved line being edited — is cloned from
 * ONE <template>. So the template is what these assert: get it right there and
 * every row agrees by construction.
 *
 * In-memory sqlite. No real record touched.
 */

function requisitionFormUser(): User
{
    Role::findOrCreate('store', 'web');

    return User::factory()->create()->assignRole('store');
}

/** The item-line template's markup, isolated from the rest of the page. */
function requisitionLineTemplate(string $html): string
{
    $start = strpos($html, 'id="requisitionLineTemplate"');
    $end = strpos($html, '</template>', $start ?: 0);

    expect($start)->not->toBeFalse();

    return substr($html, $start, $end - $start);
}

it('lays the item line out in the order the requester works through it', function () {
    StockItem::create(['name' => 'Sewing Thread 40/2', 'uom' => 'Cone', 'is_active' => true]);

    $template = requisitionLineTemplate(
        $this->actingAs(requisitionFormUser())
            ->get(route('store.stock.requisitions.create'))
            ->assertOk()
            ->getContent()
    );

    // Each field is one labelled block, so the labels ARE the field order.
    $labels = [];
    preg_match_all('/<label class="form-label">([^<]+)/', $template, $matches);

    foreach ($matches[1] as $label) {
        $labels[] = trim(html_entity_decode(strip_tags($label)));
    }

    expect($labels)->toBe([
        'Item Name',
        'Uom',
        'Specification',
        'Category',
        'Type',
        'User Dept.',
        'Qty Req.',
        'Rate Appx.',
        'Remarks',
    ]);
});

it('defaults a new line to Type New without losing the other options', function () {
    $template = requisitionLineTemplate(
        $this->actingAs(requisitionFormUser())
            ->get(route('store.stock.requisitions.create'))
            ->assertOk()
            ->getContent()
    );

    // New is pre-selected...
    expect($template)->toContain('<option value="new" selected>New</option>');

    // ...and it is the ONLY pre-selected option, so the row posts "new" and
    // nothing else is silently chosen for the requester.
    expect(substr_count($template, 'selected'))->toBe(1);

    // Replace and the blank are still offered, so the default can be changed.
    // Matched loosely on whitespace: @selected renders nothing but a space on
    // the options it does not pick.
    expect($template)->toMatch('/<option value="replace"\s*>Replace<\/option>/')
        ->and($template)->toContain('<option value="">—</option>');

    // The name attribute is untouched — the server still reads items[N][type].
    expect($template)->toContain('name="items[__INDEX__][type]"');

    // The default is a real Type the model accepts, not a loose string.
    expect(array_keys(PurchaseRequisitionItem::typeLabels()))
        ->toContain(PurchaseRequisitionItem::TYPE_NEW);
});

it('lets a saved line keep a blank Type instead of taking the new default', function () {
    // The regression the default could have caused: restoring a line whose type
    // was deliberately left blank must not show — and then re-save — "New".
    // addLine() assigns Type even when the stored value is empty, which is what
    // this pins.
    $template = requisitionLineTemplate(
        $this->actingAs(requisitionFormUser())
            ->get(route('store.stock.requisitions.create'))
            ->assertOk()
            ->getContent()
    );

    expect($template)->toContain('name="items[__INDEX__][type]"');

    $script = $this->actingAs(requisitionFormUser())
        ->get(route('store.stock.requisitions.create'))
        ->getContent();

    expect($script)->toContain('row.querySelector(\'[name$="[type]"]\').value = values.type || \'\';')
        ->and($script)->not->toContain('if (values.type) {');
});
