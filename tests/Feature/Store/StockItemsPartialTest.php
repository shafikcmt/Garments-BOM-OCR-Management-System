<?php

use App\Models\StockItem;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The Item Master's live search swaps a server-rendered fragment in place of
 * the list. The contract that makes that safe: ?partial=1 returns the list
 * only — no layout, no filter form — while the same action without the flag
 * keeps returning the whole page. If the partial starts carrying the page
 * chrome, every keystroke nests another copy of the list inside the last.
 *
 * The per-item Edit modals are part of the fragment on purpose: they are keyed
 * by item id, so a row swapped in without its modal would have an Edit button
 * pointing at nothing.
 */
function itemsPageUser(): User
{
    Role::findOrCreate('store', 'web')->givePermissionTo(
        Permission::findOrCreate('store.workspace.view', 'web')
    );

    return User::factory()->create()->assignRole('store');
}

function itemsPartialItem(string $name, array $extra = []): StockItem
{
    return StockItem::create(array_merge([
        'name' => $name,
        'uom' => 'PCS',
        'category' => 'Needle',
        'is_active' => true,
        'opening_qty' => 100,
    ], $extra));
}

it('returns only the list fragment when asked for a partial', function () {
    itemsPartialItem('Cotton Thread White');

    $response = $this->actingAs(itemsPageUser())
        ->get(route('store.stock.items.index', ['partial' => 1]))
        ->assertOk();

    $response->assertSee('data-list-meta', false);

    // None of the page around it.
    $response->assertDontSee('data-list-filters', false);
    $response->assertDontSee('<body', false);
});

it('still returns the full page without the partial flag', function () {
    itemsPartialItem('Cotton Thread White');

    $this->actingAs(itemsPageUser())
        ->get(route('store.stock.items.index'))
        ->assertOk()
        ->assertSee('data-list-filters', false)
        ->assertSee('data-list-meta', false);
});

it('applies the search filter to the partial', function () {
    itemsPartialItem('Cotton Thread White');
    itemsPartialItem('Carton Box Large');

    // A mid-word substring, which is the case live search exists for.
    $this->actingAs(itemsPageUser())
        ->get(route('store.stock.items.index', ['partial' => 1, 'search' => 'otton']))
        ->assertOk()
        ->assertSee('Cotton Thread White')
        ->assertDontSee('Carton Box Large');
});

/**
 * Regression: the fragment renders the Edit modals, whose _item-fields include
 * needs $categories. Leaving it out of the partial's payload threw a 500 that
 * only showed up as "could not update the list" in the browser.
 */
it('renders the edit modals in the fragment for a user who may edit', function () {
    $item = itemsPartialItem('Cotton Thread White');

    $user = itemsPageUser();
    $user->givePermissionTo(Permission::findOrCreate('store.edit', 'web'));

    $this->actingAs($user)
        ->get(route('store.stock.items.index', ['partial' => 1]))
        ->assertOk()
        ->assertSee('editItem'.$item->id, false);
});

it('keeps the transport flag out of the pagination links', function () {
    itemsPartialItem('Cotton Thread White');

    // A "next page" href has to stay a real URL someone can open or share.
    $this->actingAs(itemsPageUser())
        ->get(route('store.stock.items.index', ['partial' => 1, 'search' => 'otton']))
        ->assertOk()
        ->assertDontSee('partial=1', false);
});
