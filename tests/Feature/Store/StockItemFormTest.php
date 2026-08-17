<?php

use App\Models\StockItem;
use App\Models\User;
use Spatie\Permission\Models\Permission;

/**
 * The Add / Edit Item form after the re-order fields were folded away.
 *
 * Two things are worth holding still here:
 *
 *   - Safety Stock, Re-order Level and Lead are still ACCEPTED. They moved into
 *     a collapsed panel, not out of the form — the Stock Report branches on them
 *     and labels a pinned value as pinned, and 100 items already carry one.
 *   - Counted On no longer refuses the form. It is prefilled with today, and a
 *     quantity submitted without one is dated today rather than rejected, which
 *     is what the bulk import has always done.
 *
 * In-memory sqlite. No real record touched.
 */
/**
 * Creating an item needs store.items.create; editing one needs the correction
 * right (store.edit / store.items.edit). Both are granted here so one helper
 * covers the whole form.
 */
function itemFormUser(array $permissions = ['store.items.view', 'store.items.create', 'store.edit']): User
{
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $user = User::factory()->create(['status' => 1]);
    $user->givePermissionTo($permissions);

    return $user;
}

function itemPayload(array $overrides = []): array
{
    return array_merge(['name' => 'Sewing Needle', 'uom' => 'Pkt'], $overrides);
}

// --- Counted On -------------------------------------------------------------

it('accepts an opening stock with no counted date, and dates it today', function () {
    // This used to be a validation error (required_with:opening_qty), which
    // made the ordinary case — counting the shelf now — an error message.
    $this->actingAs(itemFormUser())
        ->post(route('store.stock.items.store'), itemPayload(['opening_qty' => 25]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $item = StockItem::first();

    expect((float) $item->opening_qty)->toBe(25.0)
        ->and($item->opening_as_on->toDateString())->toBe(now()->toDateString());
});

it('keeps a counted date the user actually typed', function () {
    // Defaulting must not overwrite a real answer: someone entering last week's
    // count has to be able to say so, or the report places it in the wrong month.
    $this->actingAs(itemFormUser())
        ->post(route('store.stock.items.store'), itemPayload([
            'opening_qty' => 25,
            'opening_as_on' => '2026-08-01',
        ]))
        ->assertSessionHasNoErrors();

    expect(StockItem::first()->opening_as_on->toDateString())->toBe('2026-08-01');
});

it('leaves the counted date alone when there is no opening stock', function () {
    // Nothing was counted, so there is no count date to invent.
    $this->actingAs(itemFormUser())
        ->post(route('store.stock.items.store'), itemPayload())
        ->assertSessionHasNoErrors();

    expect(StockItem::first()->opening_as_on)->toBeNull();
});

it('prefills today on the Add form and the stored date on Edit', function () {
    $item = StockItem::create([
        'name' => 'Existing Item', 'uom' => 'Pkt',
        'opening_qty' => 5, 'opening_as_on' => '2026-08-01',
    ]);

    $html = $this->actingAs(itemFormUser())
        ->get(route('store.stock.items.index'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('value="'.now()->toDateString().'"')
        ->and($html)->toContain('value="2026-08-01"');

    expect($item->fresh()->opening_as_on->toDateString())->toBe('2026-08-01');
});

// --- The folded-away overrides ----------------------------------------------

it('still accepts a pinned safety stock, re-order level and lead time', function () {
    // Folded away, not removed: the report reads these and marks them pinned.
    $this->actingAs(itemFormUser())
        ->post(route('store.stock.items.store'), itemPayload([
            'safety_stock_qty' => 10,
            'reorder_level' => 20,
            'lead_time_days' => 5,
        ]))
        ->assertSessionHasNoErrors();

    $item = StockItem::first();

    expect((float) $item->safety_stock_qty)->toBe(10.0)
        ->and((float) $item->reorder_level)->toBe(20.0)
        ->and($item->lead_time_days)->toBe(5);
});

it('leaves the overrides null when the panel is left alone', function () {
    // Null is what makes the Stock Report calculate them, so blank must not
    // become 0 — a pinned zero would mean "never re-order this".
    $this->actingAs(itemFormUser())
        ->post(route('store.stock.items.store'), itemPayload())
        ->assertSessionHasNoErrors();

    $item = StockItem::first();

    expect($item->safety_stock_qty)->toBeNull()
        ->and($item->reorder_level)->toBeNull()
        ->and($item->lead_time_days)->toBeNull();
});

it('renders the overrides inside a collapsed advanced panel', function () {
    StockItem::create(['name' => 'Plain Item', 'uom' => 'Pkt']);

    $html = $this->actingAs(itemFormUser())
        ->get(route('store.stock.items.index'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Advanced — override the calculated values')
        ->and($html)->toContain('gx-advanced-toggle')
        // The fields are still on the form, just inside the panel.
        ->and($html)->toContain('name="safety_stock_qty"')
        ->and($html)->toContain('name="reorder_level"')
        ->and($html)->toContain('name="lead_time_days"');
});

it('flags an item that already carries a pinned value', function () {
    StockItem::create(['name' => 'Pinned Item', 'uom' => 'Pkt', 'safety_stock_qty' => 10]);

    $html = $this->actingAs(itemFormUser())
        ->get(route('store.stock.items.index'))
        ->assertOk()
        ->getContent();

    // Says the override is in use without the user opening the panel.
    expect($html)->toContain('Pinned');
});

// --- Unit Price -------------------------------------------------------------

it('stores an optional unit price', function () {
    $this->actingAs(itemFormUser())
        ->post(route('store.stock.items.store'), itemPayload(['unit_price' => 12.5]))
        ->assertSessionHasNoErrors();

    expect((float) StockItem::first()->unit_price)->toBe(12.5);
});

it('accepts an item with no unit price at all', function () {
    // Optional, like every other number here: an item is often set up before
    // its price is settled.
    $this->actingAs(itemFormUser())
        ->post(route('store.stock.items.store'), itemPayload())
        ->assertSessionHasNoErrors();

    expect(StockItem::first()->unit_price)->toBeNull();
});

it('refuses a negative unit price', function () {
    $this->actingAs(itemFormUser())
        ->post(route('store.stock.items.store'), itemPayload(['unit_price' => -1]))
        ->assertSessionHasErrors('unit_price');

    expect(StockItem::count())->toBe(0);
});

it('updates the unit price on an existing item', function () {
    $item = StockItem::create(['name' => 'Existing', 'uom' => 'Pkt', 'unit_price' => 5]);

    $this->actingAs(itemFormUser())
        ->put(route('store.stock.items.update', $item), itemPayload([
            'name' => 'Existing',
            'unit_price' => 7.25,
        ]))
        ->assertSessionHasNoErrors();

    expect((float) $item->fresh()->unit_price)->toBe(7.25);
});
