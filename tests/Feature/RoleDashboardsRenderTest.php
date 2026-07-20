<?php

use App\Models\User;
use App\Services\DashboardMetricsService;
use Spatie\Permission\Models\Role;

/**
 * Every role dashboard, rendered as its own role.
 *
 * All seven are now built from the same component set and read live counts, so
 * a mistake in one of the shared components — or a controller that stops
 * passing a variable a view expects — would take several screens down at once.
 */
function dashboardUser(string $role): User
{
    Role::findOrCreate($role, 'web');

    return User::factory()->create()->assignRole($role);
}

dataset('role dashboards', [
    'store' => ['store.dashboard', 'store'],
    'admin' => ['admin.dashboard', 'admin'],
    'management' => ['management.dashboard', 'management'],
    'supply chain' => ['supply_chain.dashboard', 'supply_chain'],
    'merchant' => ['merchant.dashboard', 'merchant'],
    'commercial' => ['commercial.dashboard', 'commercial'],
    'account' => ['account.dashboard', 'account'],
]);

it('renders for its own role', function (string $routeName, string $role) {
    $this->actingAs(dashboardUser($role))
        ->get(route($routeName))
        ->assertOk();
})->with('role dashboards');

it('renders on an empty database without dividing by zero', function (string $routeName, string $role) {
    // No BOM rows, no POs, no PRAs. The completion maths and the trend delta
    // both divide, so an empty install is the case most likely to blow up —
    // and it is exactly the state a new deployment starts in.
    $this->actingAs(dashboardUser($role))
        ->get(route($routeName))
        ->assertOk();
})->with('role dashboards');

it('reports zero completion rather than failing when the workspace is empty', function () {
    $metrics = app(DashboardMetricsService::class);

    $result = $metrics->workspaceCompletionFor('commercial');

    expect($result['percent'])->toBe(0.0)
        ->and($result['pending'])->toBe(0)
        ->and($result['expected'])->toBe(0);
});

it('never returns a delta against a month with nothing in it', function () {
    $metrics = app(DashboardMetricsService::class);

    expect($metrics->deltaFor([['label' => 'Jan', 'value' => 0], ['label' => 'Feb', 'value' => 5]]))->toBeNull()
        ->and($metrics->deltaFor([['label' => 'Jan', 'value' => 4], ['label' => 'Feb', 'value' => 6]]))->toBe(50.0)
        ->and($metrics->deltaFor([['label' => 'Jan', 'value' => 8], ['label' => 'Feb', 'value' => 4]]))->toBe(-50.0)
        ->and($metrics->deltaFor([['label' => 'Jan', 'value' => 3]]))->toBeNull();
});
