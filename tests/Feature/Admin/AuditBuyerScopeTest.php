<?php

use App\Models\Buyer;
use App\Models\User;
use App\Support\BuyerScope;
use App\Support\BuyerScopeModeBackfill;
use Spatie\Permission\Models\Role;

/**
 * The read-only production audit, and its --department= flag.
 *
 * The flag exists to answer a question the default cannot: if BuyerScope listed
 * ONE named department tomorrow, who in it becomes confined? It must therefore
 * answer for a department that is NOT scoped yet, which is the whole point.
 */
beforeEach(function () {
    foreach (['admin', 'merchant', 'commercial', 'supply_chain', 'store'] as $role) {
        Role::findOrCreate($role, 'web');
    }

    $this->buyerA = Buyer::create(['buyer_name' => 'Buyer A']);
});

test('the default audit still reports and writes nothing', function () {
    $this->artisan('buyer:audit-scope')
        ->expectsOutputToContain('Buyer scope audit')
        ->assertSuccessful();
});

test('the flag answers for a department that is not scoped yet', function () {
    expect(BuyerScope::SCOPED_DEPARTMENTS)->not->toContain('commercial');

    $holder = User::factory()->create(['buyer_id' => $this->buyerA->id])->assignRole('commercial');

    $this->artisan('buyer:audit-scope --department=commercial')
        ->expectsOutputToContain('NOT SCOPED')
        ->expectsOutputToContain($holder->email)
        ->assertSuccessful();
});

test('the flag passes cleanly for a department holding no buyers', function () {
    User::factory()->create()->assignRole('store');

    $this->artisan('buyer:audit-scope --department=store')
        ->expectsOutputToContain('PASS')
        ->assertSuccessful();
});

test('the flag marks an already-scoped department as live rather than hypothetical', function () {
    $this->artisan('buyer:audit-scope --department=merchandising')
        ->expectsOutputToContain('ALREADY SCOPED')
        ->assertSuccessful();
});

test('an unknown department is refused with the list of known keys', function () {
    $this->artisan('buyer:audit-scope --department=nonsense')
        ->expectsOutputToContain('Unknown department')
        ->expectsOutputToContain('merchandising')
        ->assertSuccessful();
});

test('a department admin who owns buyers is reported even with no buyer_id or pivot row', function () {
    // The case a column/pivot-only check misses: confinement by ownership.
    $admin = User::factory()->create(['is_department_admin' => true])->assignRole('commercial');
    $this->buyerA->update(['department_admin_id' => $admin->id]);

    $holders = (new BuyerScopeModeBackfill())->buyerHoldersInDepartment('commercial');

    expect(collect($holders)->pluck('id')->all())->toBe([$admin->id]);
});

test('holders are found by column, by pivot and by ownership alike', function () {
    $byColumn = User::factory()->create(['buyer_id' => $this->buyerA->id])->assignRole('commercial');

    $byPivot = User::factory()->create()->assignRole('commercial');
    $byPivot->buyers()->sync([$this->buyerA->id]);

    $byOwnership = User::factory()->create(['is_department_admin' => true])->assignRole('commercial');
    Buyer::create(['buyer_name' => 'Buyer B', 'department_admin_id' => $byOwnership->id]);

    $holders = (new BuyerScopeModeBackfill())->buyerHoldersInDepartment('commercial');

    expect(collect($holders)->pluck('id')->all())
        ->toBe([$byColumn->id, $byPivot->id, $byOwnership->id]);
});

test('it reports only the department asked about', function () {
    User::factory()->create(['buyer_id' => $this->buyerA->id])->assignRole('merchant');

    expect((new BuyerScopeModeBackfill())->buyerHoldersInDepartment('commercial'))->toBe([]);
});

test('the audit writes nothing', function () {
    $user = User::factory()->create(['buyer_id' => $this->buyerA->id])->assignRole('commercial');

    // Snapshot from fresh(), not from the in-memory model: buyer_scope_mode is
    // a database default, so an unrefreshed model reports null and the
    // comparison would fail without anything having been written.
    $before = $user->fresh()->only(['buyer_id', 'buyer_scope_mode']);

    $this->artisan('buyer:audit-scope --department=commercial')->assertSuccessful();
    $this->artisan('buyer:audit-scope')->assertSuccessful();

    expect($user->fresh()->only(['buyer_id', 'buyer_scope_mode']))->toBe($before)
        ->and(Buyer::count())->toBe(1);
});
