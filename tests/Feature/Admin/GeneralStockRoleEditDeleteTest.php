<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Dropping Edit/Delete from the Store — General Stock bundle.
 *
 * The change is only safe because Phase B gave every current holder those
 * rights directly. These tests assert both halves of that: it is a no-op when
 * the backfill has happened, and it REFUSES TO RUN when it has not.
 *
 * In-memory sqlite. No real record touched.
 */
function runRoleStrip(string $direction = 'up'): void
{
    $migration = require database_path('migrations/2026_08_11_000003_drop_edit_delete_from_general_stock_role.php');

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $migration->{$direction}();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

function gsRole(): Role
{
    return Role::findByName('store_general_stock', 'web');
}

beforeEach(function () {
    // The migrations already seed this role; reset it to a known bundle so the
    // test does not depend on how many permissions shipped with it.
    foreach (['store.items.view', 'store.items.create', 'store.items.edit', 'store.items.delete',
        'store.issues.view', 'store.issues.edit', 'store.items.export',
        'store.requisition.approve', 'store.requisition.accounts', 'store.requisition.review'] as $p) {
        Permission::findOrCreate($p, 'web');
    }

    Role::findOrCreate('store_general_stock', 'web')->syncPermissions([
        'store.items.view', 'store.items.create', 'store.items.edit', 'store.items.delete',
        'store.issues.view', 'store.issues.edit', 'store.items.export',
        'store.requisition.approve', 'store.requisition.accounts', 'store.requisition.review',
    ]);

    DB::table('app_settings')->where('key', 'general_stock_role_edit_delete_removed')->delete();
});

it('leaves a backfilled holder with identical effective access', function () {
    $user = User::factory()->create()->assignRole('store_general_stock');

    // Phase B state: the role's grants are also held directly.
    $user->givePermissionTo(gsRole()->permissions->pluck('name')->all());

    $before = $user->fresh()->getAllPermissions()->pluck('name')->sort()->values()->all();

    runRoleStrip();

    expect($user->fresh()->getAllPermissions()->pluck('name')->sort()->values()->all())
        ->toBe($before);

    // Still holds edit/delete — now from the direct grant, not the role.
    expect($user->fresh()->can('store.items.edit'))->toBeTrue()
        ->and($user->fresh()->can('store.items.delete'))->toBeTrue();
});

it('strips edit and delete from the role but keeps view and create', function () {
    $user = User::factory()->create()->assignRole('store_general_stock');
    $user->givePermissionTo(gsRole()->permissions->pluck('name')->all());

    runRoleStrip();

    expect(gsRole()->fresh()->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['store.issues.view', 'store.items.create', 'store.items.export', 'store.items.view']);
});

it('gives a NEW user view and create only, with no edit or delete', function () {
    // The actual point of the change.
    $user = User::factory()->create()->assignRole('store_general_stock');
    $user->givePermissionTo(gsRole()->permissions->pluck('name')->all());

    runRoleStrip();

    $newcomer = User::factory()->create()->assignRole('store_general_stock');

    expect($newcomer->getAllPermissions()->pluck('name')->sort()->values()->all())
        ->toBe(['store.issues.view', 'store.items.create', 'store.items.export', 'store.items.view'])
        ->and($newcomer->can('store.items.edit'))->toBeFalse()
        ->and($newcomer->can('store.items.delete'))->toBeFalse();
});

it('also removes the approval-stage rights, which no suffix rule would catch', function () {
    // approve / accounts / review move a requisition through the purchase
    // workflow. They are not corrections, so the .edit/.delete rule misses
    // them, and they were sitting in the default bundle unnoticed.
    $user = User::factory()->create()->assignRole('store_general_stock');
    $user->givePermissionTo(gsRole()->permissions->pluck('name')->all());

    $before = $user->fresh()->getAllPermissions()->pluck('name')->sort()->values()->all();

    runRoleStrip();

    $bundle = gsRole()->fresh()->permissions->pluck('name');

    expect($bundle)->not->toContain('store.requisition.approve')
        ->and($bundle)->not->toContain('store.requisition.accounts')
        ->and($bundle)->not->toContain('store.requisition.review')
        // Export is deliberately kept: it reads what the user already sees.
        ->and($bundle)->toContain('store.items.export');

    // The existing holder keeps all three, from their direct grant.
    expect($user->fresh()->getAllPermissions()->pluck('name')->sort()->values()->all())->toBe($before);
    expect($user->fresh()->can('store.requisition.approve'))->toBeTrue();

    // A newcomer gets none of them.
    $newcomer = User::factory()->create()->assignRole('store_general_stock');

    expect($newcomer->can('store.requisition.approve'))->toBeFalse()
        ->and($newcomer->can('store.requisition.accounts'))->toBeFalse()
        ->and($newcomer->can('store.requisition.review'))->toBeFalse();
});

it('refuses to run when a holder has not been backfilled', function () {
    // No direct grants — this user's edit/delete comes only from the role, so
    // stripping it would silently remove their correction rights.
    $exposed = User::factory()->create()->assignRole('store_general_stock');

    expect($exposed->can('store.items.edit'))->toBeTrue();

    expect(fn () => runRoleStrip())
        ->toThrow(RuntimeException::class);

    // Rolled back: the role still carries them and the user is unharmed.
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($exposed->fresh()->can('store.items.edit'))->toBeTrue()
        ->and(gsRole()->fresh()->permissions->pluck('name'))->toContain('store.items.edit');
});

it('reverses exactly', function () {
    $user = User::factory()->create()->assignRole('store_general_stock');
    $user->givePermissionTo(gsRole()->permissions->pluck('name')->all());

    $bundleBefore = gsRole()->permissions->pluck('name')->sort()->values()->all();

    runRoleStrip('up');
    runRoleStrip('down');

    expect(gsRole()->fresh()->permissions->pluck('name')->sort()->values()->all())
        ->toBe($bundleBefore);

    expect(DB::table('app_settings')->where('key', 'general_stock_role_edit_delete_removed')->exists())
        ->toBeFalse();
});

it('touches no other role', function () {
    $user = User::factory()->create()->assignRole('store_general_stock');
    $user->givePermissionTo(gsRole()->permissions->pluck('name')->all());

    $others = Role::where('name', '!=', 'store_general_stock')->get()
        ->mapWithKeys(fn (Role $r) => [$r->name => $r->permissions->pluck('name')->sort()->values()->all()]);

    runRoleStrip();

    foreach ($others as $name => $perms) {
        expect(Role::findByName($name, 'web')->permissions->pluck('name')->sort()->values()->all())
            ->toBe($perms);
    }
});
