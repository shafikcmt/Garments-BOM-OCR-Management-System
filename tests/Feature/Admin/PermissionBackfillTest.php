<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase B — copying role-derived permissions down to direct grants.
 *
 * The migration's whole claim is that it changes nothing an authorization check
 * can see, so that is what these assert: effective access before equals
 * effective access after, in both directions.
 *
 * Runs on the in-memory sqlite database from phpunit.xml. No real record is
 * touched.
 */
function runBackfill(string $direction = 'up'): void
{
    $migration = require database_path('migrations/2026_08_11_000002_backfill_role_permissions_to_users.php');

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $migration->{$direction}();

    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/** @return array<int, list<string>> */
function effectiveMap(): array
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $out = [];

    foreach (User::orderBy('id')->get() as $u) {
        $out[$u->id] = $u->getAllPermissions()->pluck('name')->sort()->values()->all();
    }

    return $out;
}

beforeEach(function () {
    foreach (['alpha.view', 'alpha.edit', 'beta.view', 'gamma.approve'] as $p) {
        Permission::findOrCreate($p, 'web');
    }

    Role::findOrCreate('bundled', 'web')->givePermissionTo(['alpha.view', 'alpha.edit', 'gamma.approve']);
    Role::findOrCreate('empty', 'web');
});

it('leaves every user effective permissions identical', function () {
    $bundled = User::factory()->create()->assignRole('bundled');
    $withExtra = User::factory()->create()->assignRole('bundled');
    $withExtra->givePermissionTo('beta.view');
    $roleless = User::factory()->create();
    $emptyRole = User::factory()->create()->assignRole('empty');

    $before = effectiveMap();

    runBackfill();

    expect(effectiveMap())->toBe($before);

    // And the copy actually happened — otherwise the assertion above passes
    // for the wrong reason.
    expect($bundled->fresh()->getDirectPermissions()->pluck('name')->sort()->values()->all())
        ->toBe(['alpha.edit', 'alpha.view', 'gamma.approve']);

    expect($withExtra->fresh()->getDirectPermissions()->pluck('name')->sort()->values()->all())
        ->toBe(['alpha.edit', 'alpha.view', 'beta.view', 'gamma.approve']);

    // Nothing invented for users who had nothing.
    expect($roleless->fresh()->getDirectPermissions())->toHaveCount(0)
        ->and($emptyRole->fresh()->getDirectPermissions())->toHaveCount(0);
});

it('survives the strip that phase D will perform', function () {
    // The point of the whole phase: after the backfill, emptying the role must
    // cost the user nothing.
    $user = User::factory()->create()->assignRole('bundled');

    $before = $user->getAllPermissions()->pluck('name')->sort()->values()->all();

    runBackfill();

    Role::findByName('bundled')->syncPermissions([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($user->fresh()->getAllPermissions()->pluck('name')->sort()->values()->all())
        ->toBe($before);
});

it('reverses exactly, keeping a direct grant the role also provided', function () {
    // The case the dev database actually contains: two management users hold
    // approve-pra BOTH directly and through their role. A down() that dropped
    // every direct grant the role also provides would strip it, and nothing
    // else would grant it back.
    $overlapping = User::factory()->create()->assignRole('bundled');
    $overlapping->givePermissionTo('gamma.approve');   // also in the role
    $plain = User::factory()->create()->assignRole('bundled');

    $beforeDirect = $overlapping->getDirectPermissions()->pluck('name')->all();
    expect($beforeDirect)->toBe(['gamma.approve']);

    $beforeEffective = effectiveMap();

    runBackfill('up');
    runBackfill('down');

    expect(effectiveMap())->toBe($beforeEffective);

    // The pre-existing direct grant survived the round trip...
    expect($overlapping->fresh()->getDirectPermissions()->pluck('name')->all())
        ->toBe(['gamma.approve']);

    // ...and the ones the backfill created are gone.
    expect($plain->fresh()->getDirectPermissions())->toHaveCount(0);

    // Receipt cleaned up, so a second up() starts from a clean slate.
    expect(DB::table('app_settings')->where('key', 'phase_b_permission_backfill')->exists())->toBeFalse();
});

it('is idempotent when run twice', function () {
    User::factory()->create()->assignRole('bundled');

    runBackfill();
    $after = effectiveMap();
    $receipt = DB::table('app_settings')->where('key', 'phase_b_permission_backfill')->value('value');

    runBackfill();

    expect(effectiveMap())->toBe($after);

    // Second pass finds nothing left to add, so the receipt records no grants
    // and a later down() cannot revoke something it did not create.
    expect(json_decode(DB::table('app_settings')->where('key', 'phase_b_permission_backfill')->value('value'), true))
        ->toBe([])
        ->and($receipt)->not->toBe(null);
});

it('does not touch role bundles', function () {
    User::factory()->create()->assignRole('bundled');

    runBackfill();

    expect(Role::findByName('bundled')->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['alpha.edit', 'alpha.view', 'gamma.approve']);
});
