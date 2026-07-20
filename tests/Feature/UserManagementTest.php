<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Admin user management.
 *
 * The permission matrix is the notable part: 69 role/permission assignments
 * were seeded and are enforced through @can(), but no screen had ever shown
 * them, so an admin had to read the database to find out who could do what.
 * It is displayed read-only — editing it changes access for everyone.
 */
function adminUser(): User
{
    Role::findOrCreate('admin', 'web');

    return User::factory()->create(['status' => 1])->assignRole('admin');
}

it('counts users by real status rather than an invite state', function () {
    // A migration seeds an inactive system account (system@garments-ocr.local)
    // that has never signed in, so the counts start from it rather than zero.
    $admin = adminUser();
    User::factory()->create(['status' => 1, 'last_login_at' => now()]);
    User::factory()->create(['status' => 0, 'last_login_at' => null]);
    User::factory()->create(['status' => 1, 'last_login_at' => null]);

    $stats = $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->viewData('stats');

    expect($stats['total'])->toBe(User::count())
        ->and($stats['active'])->toBe(User::where('status', 1)->count())
        ->and($stats['inactive'])->toBe(User::where('status', '!=', 1)->count())
        ->and($stats['admins'])->toBe(1)
        // Stands in for "pending invites", which this system has no concept of.
        ->and($stats['never_signed_in'])->toBe(User::whereNull('last_login_at')->count());

    // And the breakdown is genuinely split, not everything in one bucket.
    expect($stats['active'])->toBeGreaterThan(0)
        ->and($stats['inactive'])->toBeGreaterThan(0)
        ->and($stats['never_signed_in'])->toBeGreaterThan(0);
});

it('shows when each user last signed in', function () {
    $admin = adminUser();
    User::factory()->create(['name' => 'Never Loggedin', 'last_login_at' => null]);

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertSee('Never Loggedin')
        ->assertSee('Never');
});

it('displays the permission matrix that nothing previously exposed', function () {
    $admin = adminUser();

    $permission = Permission::findOrCreate('materials.view', 'web');
    Role::findOrCreate('store', 'web')->givePermissionTo($permission);

    $response = $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertOk();

    $response->assertSee('Role permissions')
        ->assertSee('materials.view')
        // Marked read-only on purpose: granting rights is a separate action.
        ->assertSee('Read-only');

    $matrix = $response->viewData('permissionMatrix');
    $store = $matrix['roles']->firstWhere('name', 'store');

    expect($store->permissions->pluck('name'))->toContain('materials.view');
});

it('lists signed-in sessions with their address and device', function () {
    // The suite runs with the array session driver; the panel reads the
    // database one, which is what production uses.
    config(['session.driver' => 'database']);

    $admin = adminUser();

    DB::table('sessions')->insert([
        'id' => 'test-session-id',
        'user_id' => $admin->id,
        'ip_address' => '192.168.1.50',
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0)',
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertSee('192.168.1.50');
});

it('does not offer to delete the account you are signed in as', function () {
    $admin = adminUser();
    $other = User::factory()->create();

    $response = $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertOk();

    // The destroy and show routes share a URI and differ only by verb, so the
    // delete form itself is what has to be asserted on.
    $response->assertSee('data-delete-user="'.$other->id.'"', false)
        ->assertDontSee('data-delete-user="'.$admin->id.'"', false);
});

it('keeps the reset-password action reachable for each user', function () {
    $admin = adminUser();
    $other = User::factory()->create();

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertSee(route('admin.users.reset-password', $other), false);
});
