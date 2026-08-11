<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Removing permissions that gate nothing from four ordinary roles.
 *
 * The claim under test is narrow and total: no user's effective access moves.
 * So most of these assert absence of change, and the one that matters asserts
 * the migration REFUSES when that would not hold.
 *
 * In-memory sqlite. No real record touched.
 */
function runDecorativeCleanup(string $direction = 'up'): void
{
    $migration = require database_path('migrations/2026_08_11_000005_drop_decorative_permissions_from_normal_roles.php');

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $migration->{$direction}();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/** role => the permissions this migration should drop. */
const CLEANUP_MAP = [
    'supply_chain' => ['materials.edit', 'shipments.edit'],
    'commercial' => ['payments.edit'],
    'account' => ['payments.approve', 'store.requisition.accounts'],
    'store' => ['store.requisition.review', 'store.adjust', 'store.return'],
];

/** A keeper per role, to prove the strip is surgical. */
const CLEANUP_KEEPERS = [
    'supply_chain' => 'materials.view',
    'commercial' => 'payments.view',
    'account' => 'payments.view',
    'store' => 'store.issue',
];

function roleNow(string $name): Role
{
    return Role::findByName($name, 'web');
}

beforeEach(function () {
    foreach (array_merge(...array_values(CLEANUP_MAP)) as $p) {
        Permission::findOrCreate($p, 'web');
    }

    foreach (CLEANUP_KEEPERS as $p) {
        Permission::findOrCreate($p, 'web');
    }

    foreach (CLEANUP_MAP as $role => $perms) {
        Role::findOrCreate($role, 'web')
            ->syncPermissions(array_merge($perms, [CLEANUP_KEEPERS[$role]]));
    }

    DB::table('app_settings')->where('key', 'decorative_role_permissions_removed')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('leaves every user effective access identical', function () {
    // One backfilled holder per role, as Phase B left them.
    foreach (CLEANUP_MAP as $role => $perms) {
        $u = User::factory()->create(['status' => 1])->assignRole($role);
        $u->givePermissionTo(roleNow($role)->permissions->pluck('name')->all());
    }

    $before = User::orderBy('id')->get()
        ->mapWithKeys(fn (User $u) => [$u->id => $u->getAllPermissions()->pluck('name')->sort()->values()->all()])
        ->all();

    runDecorativeCleanup();

    $after = User::orderBy('id')->get()
        ->mapWithKeys(fn (User $u) => [$u->id => $u->getAllPermissions()->pluck('name')->sort()->values()->all()])
        ->all();

    expect($after)->toBe($before);
});

it('removes exactly the listed permissions and keeps the rest', function () {
    foreach (CLEANUP_MAP as $role => $perms) {
        $u = User::factory()->create(['status' => 1])->assignRole($role);
        $u->givePermissionTo(roleNow($role)->permissions->pluck('name')->all());
    }

    runDecorativeCleanup();

    foreach (CLEANUP_MAP as $role => $perms) {
        $bundle = roleNow($role)->permissions->pluck('name');

        foreach ($perms as $p) {
            expect($bundle)->not->toContain($p);
        }

        // Only the keeper survives, so the strip is surgical rather than a wipe.
        expect($bundle)->toContain(CLEANUP_KEEPERS[$role]);
        expect($bundle)->toHaveCount(1);
    }
});

it('keeps store.issue on the store role', function () {
    // Explicitly confirmed as core operational capability, not a correction.
    $u = User::factory()->create(['status' => 1])->assignRole('store');
    $u->givePermissionTo(roleNow('store')->permissions->pluck('name')->all());

    runDecorativeCleanup();

    expect(roleNow('store')->permissions->pluck('name'))->toContain('store.issue');

    $newcomer = User::factory()->create(['status' => 1])->assignRole('store');

    expect($newcomer->can('store.issue'))->toBeTrue()
        ->and($newcomer->can('store.adjust'))->toBeFalse()
        ->and($newcomer->can('store.return'))->toBeFalse();
});

it('touches no role outside the list', function () {
    Role::findOrCreate('admin', 'web')->syncPermissions(['materials.edit', 'payments.approve', 'store.adjust']);
    Role::findOrCreate('management', 'web')->syncPermissions(['payments.edit', 'store.return']);
    Role::findOrCreate('store_general_stock', 'web')->syncPermissions(['materials.view']);

    $untouched = ['admin', 'management', 'store_general_stock'];

    $before = collect($untouched)
        ->mapWithKeys(fn ($r) => [$r => roleNow($r)->permissions->pluck('name')->sort()->values()->all()]);

    runDecorativeCleanup();

    foreach ($untouched as $r) {
        expect(roleNow($r)->permissions->pluck('name')->sort()->values()->all())->toBe($before[$r]);
    }
});

it('refuses to run when a holder has not been backfilled', function () {
    // Their access comes from the role alone, so removing it would cost them.
    $exposed = User::factory()->create(['status' => 1])->assignRole('supply_chain');

    expect($exposed->can('materials.edit'))->toBeTrue();

    expect(fn () => runDecorativeCleanup())->toThrow(RuntimeException::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($exposed->fresh()->can('materials.edit'))->toBeTrue()
        ->and(roleNow('supply_chain')->permissions->pluck('name'))->toContain('materials.edit');
});

it('reverses exactly', function () {
    foreach (CLEANUP_MAP as $role => $perms) {
        $u = User::factory()->create(['status' => 1])->assignRole($role);
        $u->givePermissionTo(roleNow($role)->permissions->pluck('name')->all());
    }

    $before = collect(array_keys(CLEANUP_MAP))
        ->mapWithKeys(fn ($r) => [$r => roleNow($r)->permissions->pluck('name')->sort()->values()->all()]);

    runDecorativeCleanup('up');
    runDecorativeCleanup('down');

    foreach (array_keys(CLEANUP_MAP) as $r) {
        expect(roleNow($r)->permissions->pluck('name')->sort()->values()->all())->toBe($before[$r]);
    }

    expect(DB::table('app_settings')->where('key', 'decorative_role_permissions_removed')->exists())->toBeFalse();
});

it('records only what was actually present, so down cannot invent a grant', function () {
    // commercial does not carry payments.approve; the migration must not add it back.
    roleNow('commercial')->syncPermissions(['payments.view']);

    runDecorativeCleanup('up');
    runDecorativeCleanup('down');

    expect(roleNow('commercial')->permissions->pluck('name')->all())->toBe(['payments.view']);
});
