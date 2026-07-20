<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Render smoke test for the non-Store screens.
 *
 * These pages had no coverage at all, which made the breadcrumb rollout across
 * them a blind change. Requesting each one catches the Blade-level mistakes a
 * bulk edit can introduce — a malformed component call, a route() name that
 * does not exist — which compiling the templates alone would not.
 *
 * Only parameterless GET routes are listed; create/edit screens that need a
 * bound model are left out rather than propped up with fixtures.
 */
function areaUser(string $role): User
{
    Role::findOrCreate($role, 'web');

    return User::factory()->create()->assignRole($role);
}

dataset('admin pages', [
    'admin workspace' => 'admin.workspace',
    'users index' => 'admin.users.index',
    'user create' => 'admin.users.create',
    'roles index' => 'admin.roles.index',
    'role create' => 'admin.roles.create',
    'supplier create' => 'admin.suppliers.create',
    'alert settings' => 'admin.alert-settings.edit',
    'email templates' => 'admin.email-templates.edit',
    'payment settings' => 'admin.payment-settings.edit',
    'pra approvers' => 'admin.pra-approvers.index',
    'booking instructions' => 'admin.booking-instructions.index',
    'booking instruction create' => 'admin.booking-instructions.create',
    'delivery destinations' => 'admin.booking-delivery-destinations.index',
    'delivery destination create' => 'admin.booking-delivery-destinations.create',

    // These three carry their own bespoke hero markup instead of the shared
    // app-hero-card, so their breadcrumbs had to be placed by hand.
    'suppliers index' => 'admin.suppliers.index',
    'headers index' => 'admin.headers.index',
    'po generate control' => 'admin.po-generate-control.index',
]);

it('renders admin pages', function (string $routeName) {
    $this->actingAs(areaUser('admin'))
        ->get(route($routeName))
        ->assertOk();
})->with('admin pages');

it('gives admin pages a breadcrumb rooted at the dashboard', function (string $routeName) {
    $this->actingAs(areaUser('admin'))
        ->get(route($routeName))
        ->assertOk()
        ->assertSee('gx-breadcrumb', false)
        ->assertSee('aria-current="page"', false)
        ->assertSee(route('admin.dashboard'), false);
})->with('admin pages');

dataset('other role pages', [
    'supply chain workspace' => ['supply_chain.workspace', 'supply_chain'],
    'payment requests' => ['supply_chain.payment_requests.index', 'supply_chain'],
    'my pra status' => ['supply_chain.payment_requests.my_status', 'supply_chain'],
    'sent emails' => ['supply_chain.sent_emails.index', 'supply_chain'],
    'commercial workspace' => ['commercial.workspace', 'commercial'],
    'account workspace' => ['account.workspace', 'account'],
]);

it('renders the other role workspaces', function (string $routeName, string $role) {
    $this->actingAs(areaUser($role))
        ->get(route($routeName))
        ->assertOk();
})->with('other role pages');
