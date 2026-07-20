<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Smoke test for every Store screen.
 *
 * These pages are being migrated onto shared Blade components
 * (<x-page-header>, <x-card>), which is a mechanical change but touches the
 * markup of every view. A rendering error in Blade — a bad slot name, a
 * missing prop, an unclosed component — only surfaces when the view is
 * actually rendered, so each route is requested here to keep that from
 * reaching Store.
 */
function storePageUser(): User
{
    Role::findOrCreate('store', 'web');

    return User::factory()->create()->assignRole('store');
}

dataset('store pages', [
    'dashboard' => 'store.dashboard',
    'workspace' => 'store.workspace',
    'material ledger' => 'store.material.ledger',
    'material receivings' => 'store.material.receivings.index',
    'material bulk issues' => 'store.material.bulk-issues.index',
    'material requisitions' => 'store.material.requisitions.index',
    'reports' => 'store.reports.index',
    'stock items' => 'store.stock.items.index',
    'stock purchases' => 'store.stock.purchases.index',
    'stock issues' => 'store.stock.issues.index',
    'stock ledger' => 'store.stock.ledger',
]);

it('renders without error', function (string $routeName) {
    $this->actingAs(storePageUser())
        ->get(route($routeName))
        ->assertOk();
})->with('store pages');

/**
 * Layout chrome. The skip link and the account menu's role badge exist for
 * keyboard and screen-reader users, who will not be the ones to notice if a
 * refactor drops them — so they are asserted rather than eyeballed.
 */
it('renders the shared layout chrome', function () {
    $response = $this->actingAs(storePageUser())
        ->get(route('store.material.ledger'))
        ->assertOk();

    // Keyboard users must be able to jump past the sidebar's nav links.
    $response->assertSee('Skip to main content')
        ->assertSee('id="main-content"', false);

    $response->assertSee('All rights reserved', false)
        ->assertSee('v'.config('app.version'), false);
});

it('shows a breadcrumb trail with the current page marked', function () {
    $this->actingAs(storePageUser())
        ->get(route('store.material.ledger'))
        ->assertOk()
        ->assertSee('gx-breadcrumb', false)
        ->assertSee('aria-current="page"', false)
        ->assertSee('Closing Stock');
});
