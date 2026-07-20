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
