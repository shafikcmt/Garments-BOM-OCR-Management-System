<?php

use App\Models\Buyer;
use App\Models\User;
use App\Support\BuyerScope;
use App\Support\BuyerScopeModeBackfill;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * The buyer_scope_mode backfill.
 *
 * These exist for the same reason BuyerPivotBackfillTest does: the development
 * database holds no user with a buyer at all, so the migration's backfill runs
 * against nothing there and proves nothing. Production may hold real
 * assignments. Everything below builds the data local does not have.
 *
 * What is actually being protected: buyer_scope_mode defaults to 'all', and
 * 'all' means UNCONFINED. A confined merchant left on the default would be
 * silently handed every buyer in the system the moment isBuyerConfined() starts
 * reading the column. Every test here is a variation on "that must not happen".
 */
function scopeModeUser(?int $buyerId, string $role = 'merchant', array $attributes = []): User
{
    Role::findOrCreate($role, 'web');

    return User::factory()
        ->create($attributes + [
            'buyer_id' => $buyerId,
            // Explicitly the default, so each test starts from the state the
            // migration's column default actually produces.
            'buyer_scope_mode' => BuyerScope::MODE_ALL,
        ])
        ->assignRole($role);
}

beforeEach(function () {
    $this->buyerA = Buyer::create(['buyer_name' => 'Buyer A']);
    $this->buyerB = Buyer::create(['buyer_name' => 'Buyer B']);

    DB::table('buyer_user')->delete();
});

it('marks a merchant holding a buyer by column as specific', function () {
    $user = scopeModeUser($this->buyerA->id);

    expect(app(BuyerScopeModeBackfill::class)->run())->toBe(1)
        ->and($user->fresh()->buyer_scope_mode)->toBe(BuyerScope::MODE_SPECIFIC);
});

/**
 * The column is not the only source. A user assigned through the pivot alone —
 * which is every multi-buyer team member from Phase 2 onward — is just as
 * confined, and reading only users.buyer_id would write them as 'all'.
 */
it('marks a merchant holding a buyer by pivot only as specific', function () {
    $user = scopeModeUser(null);
    $user->buyers()->sync([$this->buyerA->id, $this->buyerB->id]);

    expect(app(BuyerScopeModeBackfill::class)->run())->toBe(1)
        ->and($user->fresh()->buyer_scope_mode)->toBe(BuyerScope::MODE_SPECIFIC);
});

it('leaves a merchant with no buyer on all', function () {
    $user = scopeModeUser(null);

    expect(app(BuyerScopeModeBackfill::class)->run())->toBe(0)
        ->and($user->fresh()->buyer_scope_mode)->toBe(BuyerScope::MODE_ALL);
});

it('updates nothing on a second run', function () {
    scopeModeUser($this->buyerA->id);

    expect(app(BuyerScopeModeBackfill::class)->run())->toBe(1)
        ->and(app(BuyerScopeModeBackfill::class)->run())->toBe(0);
});

/**
 * THE ONE THAT GUARDS PHASE 4C.
 *
 * A Supply Chain user carrying a stray buyer_id must come out of this as
 * 'all'. If the backfill wrote them 'specific', then switching Supply Chain on
 * in 4c would confine them to a buyer nobody deliberately assigned — an
 * access change caused by a config const rather than by an administrator.
 * Switching a department on must never confine anyone by itself.
 */
it('leaves a user outside the scoped departments on all, stray buyer or not', function (string $role) {
    $user = scopeModeUser($this->buyerA->id, $role);

    expect(app(BuyerScopeModeBackfill::class)->run())->toBe(0)
        ->and($user->fresh()->buyer_scope_mode)->toBe(BuyerScope::MODE_ALL);
})->with(['supply_chain', 'commercial', 'account', 'store', 'production']);

/**
 * The audit that must be read before 4c is deployed. It reports exactly the
 * users the test above proves are left alone — so that a human decides what
 * happens to them rather than a migration deciding silently.
 */
it('reports stray buyer holders outside the scoped departments', function () {
    $stray = scopeModeUser($this->buyerA->id, 'supply_chain');
    $fine = scopeModeUser($this->buyerB->id, 'merchant');

    $reported = collect(app(BuyerScopeModeBackfill::class)->strayBuyerHolders())->pluck('id');

    expect($reported->all())->toBe([$stray->id])
        ->and($reported)->not->toContain($fine->id);
});

it('reports no stray holders when every assignment is a merchant one', function () {
    scopeModeUser($this->buyerA->id, 'merchant');

    expect(app(BuyerScopeModeBackfill::class)->strayBuyerHolders())->toBe([]);
});
