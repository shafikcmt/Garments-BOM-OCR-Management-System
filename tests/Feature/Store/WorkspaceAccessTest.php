<?php

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Workspace is gated on store.workspace.view, and handed out through the
 * ordinary Additional Permissions matrix on the user edit screen.
 *
 * It briefly had a panel of its own. One permission did not justify a second
 * place to look and a second set of guard rails to keep in step, so these
 * exercise the same grants through the screen that already existed — which
 * means the department scoping and self-escalation rules under test here are
 * the ones protecting every other permission too, not a copy of them.
 *
 * Proved at the route throughout: a checkbox that flips a pivot row but leaves
 * the guard refusing would look like success and fail in the user's hands.
 *
 * In-memory sqlite. No real record touched.
 */
const WS = 'store.workspace.view';

function wsSeed(): void
{
    foreach ([WS, 'store.items.view', 'store.receiving.view', 'store.stock_report.view'] as $p) {
        Permission::findOrCreate($p, 'web');
    }

    // Mirrors 2026_08_11_000006: the permission rides on these bundles only.
    Role::findOrCreate('store', 'web')->givePermissionTo([WS, 'store.items.view']);
    Role::findOrCreate('management', 'web')->givePermissionTo([WS, 'store.items.view']);
    Role::findOrCreate('admin', 'web')->givePermissionTo(WS);

    Role::findOrCreate('store_general_stock', 'web')
        ->syncPermissions(['store.items.view', 'store.receiving.view', 'store.stock_report.view']);
    Role::findOrCreate('store_material_stock', 'web')->syncPermissions(['store.items.view']);
    Role::findOrCreate('commercial', 'web');

    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

function wsStoreAdmin(): User
{
    return User::factory()->create(['status' => 1, 'is_department_admin' => true])->assignRole('store');
}

function wsSuperAdmin(): User
{
    return User::factory()->create(['status' => 1])->assignRole('admin');
}

function wsNarrowUser(string $role = 'store_general_stock'): User
{
    return User::factory()->create(['status' => 1])->assignRole($role);
}

/** The user edit form's payload, with an explicit permission set. */
function wsPayload(User $user, array $permissions, string $role = 'store_general_stock'): array
{
    return [
        'name' => $user->name,
        'email' => $user->email,
        'status' => 1,
        'department' => 'store',
        'role' => $role,
        'permissions' => $permissions,
    ];
}

beforeEach(fn () => wsSeed());

it('hides Workspace from a narrow store user and refuses the direct URL', function () {
    $user = wsNarrowUser();

    expect($user->can(WS))->toBeFalse();

    $html = $this->actingAs($user)->get(route('store.stock.items.index'))->getContent();

    expect($html)->not->toContain(route('store.workspace'));

    $this->actingAs($user)->get(route('store.workspace'))->assertForbidden();
});

it('keeps Workspace working for store, management and admin', function () {
    foreach (['store', 'management', 'admin'] as $role) {
        $user = User::factory()->create(['status' => 1])->assignRole($role);

        expect($user->can(WS))->toBeTrue("{$role} should hold ".WS);

        $this->actingAs($user)->get(route('store.workspace'))->assertOk();
    }
});

it('offers Workspace as an unlocked checkbox in the matrix, under General Stock', function () {
    $target = wsNarrowUser();

    foreach ([wsSuperAdmin(), wsStoreAdmin()] as $actor) {
        $html = $this->actingAs($actor)
            ->get(route('admin.users.edit', $target))
            ->assertOk()
            ->getContent();

        // The section the catalogue files it under, and the box itself.
        expect($html)->toContain('>Workspace</');

        $chip = null;

        foreach (explode('<label', $html) as $chunk) {
            if (str_contains($chunk, 'value="'.WS.'"')) {
                $chip = $chunk;
                break;
            }
        }

        expect($chip)->not->toBeNull(WS.' is missing from the matrix');
        expect($chip)->not->toContain('disabled')
            ->and($chip)->not->toContain('is-locked');
    }
});

it('GRANT then REVOKE through the matrix takes effect at the route', function () {
    $admin = wsStoreAdmin();
    $target = wsNarrowUser();

    $this->actingAs($target)->get(route('store.workspace'))->assertForbidden();

    $this->actingAs($admin)
        ->put(route('admin.users.update', $target), wsPayload($target, [WS]))
        ->assertRedirect();

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($target->fresh()->getDirectPermissions()->pluck('name'))->toContain(WS);

    $this->actingAs($target->fresh())->get(route('store.workspace'))->assertOk();

    // Untick it and save again.
    $this->actingAs($admin)
        ->put(route('admin.users.update', $target), wsPayload($target, []))
        ->assertRedirect();

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($target->fresh())->get(route('store.workspace'))->assertForbidden();
});

it('lets a super admin grant Workspace to anyone, including another department', function () {
    $outsider = User::factory()->create(['status' => 1])->assignRole('commercial');

    $this->actingAs(wsSuperAdmin())
        ->put(route('admin.users.update', $outsider), array_merge(
            wsPayload($outsider, [WS], 'commercial'),
            ['department' => 'commercial']
        ))
        ->assertRedirect();

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($outsider->fresh()->can(WS))->toBeTrue();
});

it('refuses a store admin granting Workspace outside their department', function () {
    $admin = wsStoreAdmin();
    $outsider = User::factory()->create(['status' => 1])->assignRole('commercial');

    $this->actingAs($admin)
        ->put(route('admin.users.update', $outsider), array_merge(
            wsPayload($outsider, [WS], 'commercial'),
            ['department' => 'commercial']
        ))
        ->assertForbidden();

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($outsider->fresh()->can(WS))->toBeFalse();

    // And by ID alone, without naming the other department.
    $this->actingAs($admin)
        ->get(route('admin.users.edit', $outsider))
        ->assertForbidden();
});

it('refuses a store admin granting Workspace they do not hold themselves', function () {
    $admin = wsStoreAdmin();
    Role::findByName('store', 'web')->revokePermissionTo(WS);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $target = wsNarrowUser();

    expect($admin->fresh()->can(WS))->toBeFalse();

    $this->actingAs($admin->fresh())
        ->put(route('admin.users.update', $target), wsPayload($target, [WS]))
        ->assertForbidden();

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($target->fresh()->can(WS))->toBeFalse();
});

it('does not let a store admin give themselves Workspace', function () {
    // Their own edit locks role, status and permissions, so the matrix cannot
    // be used to add anything to the account doing the editing.
    $admin = wsStoreAdmin();
    Role::findByName('store', 'web')->revokePermissionTo(WS);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($admin->fresh()->can(WS))->toBeFalse();

    $this->actingAs($admin->fresh())
        ->put(route('admin.users.update', $admin), wsPayload($admin, [WS], 'store'))
        ->assertForbidden();

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($admin->fresh()->can(WS))->toBeFalse();
});

it('leaves a narrow role bundle without Workspace, so new users start blocked', function () {
    foreach (['store_general_stock', 'store_material_stock'] as $role) {
        expect(Role::findByName($role, 'web')->permissions->pluck('name'))->not->toContain(WS);

        $newcomer = User::factory()->create(['status' => 1])->assignRole($role);

        expect($newcomer->can(WS))->toBeFalse();

        $this->actingAs($newcomer)->get(route('store.workspace'))->assertForbidden();
    }
});

it('no longer exposes a standalone Workspace Access screen', function () {
    // The route is gone; nothing should resolve it, and the sidebar must not
    // offer a link to a screen that no longer exists.
    expect(Illuminate\Support\Facades\Route::has('store.workspace-access.index'))->toBeFalse()
        ->and(Illuminate\Support\Facades\Route::has('store.workspace-access.toggle'))->toBeFalse();

    $admin = wsStoreAdmin();

    $html = $this->actingAs($admin)->get(route('store.workspace'))->assertOk()->getContent();

    expect($html)->not->toContain('Workspace Access');
});
