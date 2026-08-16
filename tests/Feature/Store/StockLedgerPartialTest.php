<?php

use App\Models\StockItem;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The Stock Report's live search swaps a server-rendered fragment in place of
 * the table. The contract that makes that safe is narrow but easy to break by
 * accident: ?partial=1 must return the table only — no layout, no filter form —
 * while the same action without the flag keeps returning the whole page. If the
 * partial ever starts carrying the page chrome, every keystroke nests another
 * copy of the report inside the last one.
 */
function ledgerUser(): User
{
    Role::findOrCreate('store', 'web')->givePermissionTo(
        Permission::findOrCreate('store.workspace.view', 'web')
    );

    return User::factory()->create()->assignRole('store');
}

it('returns only the table fragment when asked for a partial', function () {
    $response = $this->actingAs(ledgerUser())
        ->get(route('store.stock.ledger', ['partial' => 1]))
        ->assertOk();

    // The fragment's own marker is there...
    $response->assertSee('data-ledger-meta', false);

    // ...and none of the page around it, which is what stops the swap from
    // nesting a second report inside the first.
    $response->assertDontSee('data-ledger-filters', false);
    $response->assertDontSee('<body', false);
});

it('still returns the full page without the partial flag', function () {
    $this->actingAs(ledgerUser())
        ->get(route('store.stock.ledger'))
        ->assertOk()
        ->assertSee('data-ledger-filters', false)
        ->assertSee('data-ledger-meta', false);
});

it('applies the search filter to the partial', function () {
    // Created directly: StockItem has no factory, and the report only needs a
    // name and an active flag to list a row.
    StockItem::create(['name' => 'Cotton Thread White', 'uom' => 'PCS', 'is_active' => true]);
    StockItem::create(['name' => 'Carton Box Large', 'uom' => 'PCS', 'is_active' => true]);

    $user = ledgerUser();

    // A mid-word substring, which is the case the live search exists for.
    $this->actingAs($user)
        ->get(route('store.stock.ledger', ['partial' => 1, 'search' => 'otton']))
        ->assertOk()
        ->assertSee('Cotton Thread White')
        ->assertDontSee('Carton Box Large');
});

/**
 * The report's signal is inverted on purpose: an item that is fine is the
 * common case, so it gets no badge and no colour, and only a row that needs
 * buying is allowed either. A green pill returning to every healthy row would
 * quietly undo the point of the whole layout.
 */
it('renders a healthy row as quiet text, not a green badge', function () {
    StockItem::create([
        'name' => 'Cotton Thread White',
        'uom' => 'PCS',
        'is_active' => true,
        'opening_qty' => 5000,
        'opening_as_on' => now()->subYear(),
    ]);

    $response = $this->actingAs(ledgerUser())
        ->get(route('store.stock.ledger', ['partial' => 1]))
        ->assertOk();

    // Still says "Ok" in words — the state is never carried by colour alone.
    $response->assertSee('gx-ledger-ok', false);
    $response->assertSee('Ok');

    // The old green pill is gone, and a healthy row gets no action spine.
    $response->assertDontSee('bg-success-subtle', false);
    $response->assertDontSee('gx-ledger-flag-ok', false);
});

it('keeps the transport flag out of the pagination links', function () {
    // A "next page" href has to stay a real URL someone can open or share, so
    // `partial` must not survive into it.
    $this->actingAs(ledgerUser())
        ->get(route('store.stock.ledger', ['partial' => 1, 'search' => 'x']))
        ->assertOk()
        ->assertDontSee('partial=1', false);
});
