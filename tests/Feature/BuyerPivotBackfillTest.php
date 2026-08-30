<?php

use App\Models\Buyer;
use App\Models\User;
use App\Support\BuyerPivotBackfill;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * The buyer_user backfill.
 *
 * These exist because the development database cannot exercise this at all:
 * not one user there has users.buyer_id set, so running the migration locally
 * inserts nothing and proves nothing. Production may hold real assignments.
 * Everything below builds the data local does not have.
 *
 * The property that matters most is idempotency. A backfill inside a migration
 * gets re-run whenever a deploy is retried, and one that duplicated rows on the
 * second pass would double every team member's assignment silently.
 */
function backfillUser(?int $buyerId, string $role = 'merchant'): User
{
    Role::findOrCreate($role, 'web');

    return User::factory()->create(['buyer_id' => $buyerId])->assignRole($role);
}

beforeEach(function () {
    $this->buyerA = Buyer::create(['buyer_name' => 'Buyer A']);
    $this->buyerB = Buyer::create(['buyer_name' => 'Buyer B']);

    // The migration already ran its own backfill against an empty users table.
    // Start from a known-clean pivot so each test owns its rows.
    DB::table('buyer_user')->delete();
});

it('copies one pivot row per user holding a buyer', function () {
    $one = backfillUser($this->buyerA->id);
    $two = backfillUser($this->buyerB->id);

    $inserted = app(BuyerPivotBackfill::class)->run();

    expect($inserted)->toBe(2)
        ->and(DB::table('buyer_user')->count())->toBe(2)
        ->and($one->fresh()->buyers->pluck('id')->all())->toBe([$this->buyerA->id])
        ->and($two->fresh()->buyers->pluck('id')->all())->toBe([$this->buyerB->id]);
});

it('inserts nothing on a second run', function () {
    backfillUser($this->buyerA->id);

    $first = app(BuyerPivotBackfill::class)->run();
    $second = app(BuyerPivotBackfill::class)->run();

    expect($first)->toBe(1)
        ->and($second)->toBe(0)
        ->and(DB::table('buyer_user')->count())->toBe(1);
});

it('ignores users with no buyer at all', function () {
    backfillUser(null);
    backfillUser(null, 'store');

    expect(app(BuyerPivotBackfill::class)->run())->toBe(0)
        ->and(DB::table('buyer_user')->count())->toBe(0);
});

/**
 * The backfill copies the column as it finds it. It is not the place to decide
 * that a Store user's buyer_id is meaningless — merchantBuyerIds() already
 * refuses to read it for a non-merchant, and duplicating that judgement here
 * would mean two places to keep in step.
 */
it('copies the column without judging the department, which the model does', function () {
    // store_general_stock, not store: the Store DEPARTMENT is scoped now, and
    // this test needs a role the model still refuses to read a buyer for.
    // General Stock is carved out of Store by BuyerScope::UNSCOPED_ROLES
    // because its stock carries no buyer at all.
    $storeUser = backfillUser($this->buyerA->id, 'store_general_stock');

    app(BuyerPivotBackfill::class)->run();

    expect(DB::table('buyer_user')->where('user_id', $storeUser->id)->count())->toBe(1)
        ->and($storeUser->fresh()->merchantBuyerIds())->toBe([]);
});

// --- What the model reads back --------------------------------------------

it('lists the primary buyer first, then the rest in id order', function () {
    $user = backfillUser($this->buyerB->id);          // primary is the HIGHER id
    $user->buyers()->attach([$this->buyerA->id, $this->buyerB->id]);

    // Primary first even though buyerA sorts lower — users.buyer_id wins.
    expect($user->fresh()->merchantBuyerIds())->toBe([$this->buyerB->id, $this->buyerA->id])
        ->and($user->fresh()->merchantBuyerId())->toBe($this->buyerB->id);
});

it('does not repeat the primary buyer when the pivot also holds it', function () {
    $user = backfillUser($this->buyerA->id);
    app(BuyerPivotBackfill::class)->run();

    expect($user->fresh()->merchantBuyerIds())->toBe([$this->buyerA->id]);
});

/**
 * The safety net for a user the backfill never reached — created before it ran,
 * or inserted straight into the table. Reading the pivot alone would return an
 * empty set, and an empty set means UNSCOPED, which would hand them every
 * buyer rather than lock them out of one.
 */
it('still scopes a user whose pivot row is missing', function () {
    $user = backfillUser($this->buyerA->id);   // no backfill run

    expect(DB::table('buyer_user')->count())->toBe(0)
        ->and($user->merchantBuyerIds())->toBe([$this->buyerA->id]);
});

it('scopes a user who has pivot rows but no primary column', function () {
    $user = backfillUser(null);
    $user->buyers()->attach([$this->buyerB->id, $this->buyerA->id]);

    expect($user->fresh()->merchantBuyerIds())->toBe([$this->buyerA->id, $this->buyerB->id]);
});

// --- Nothing else moved ---------------------------------------------------

it('leaves a single-buyer team member answering exactly as before', function () {
    $user = backfillUser($this->buyerA->id);
    app(BuyerPivotBackfill::class)->run();

    expect($user->fresh()->merchantBuyerIds())->toBe([$this->buyerA->id])
        ->and($user->fresh()->merchantBuyerId())->toBe($this->buyerA->id)
        ->and($user->fresh()->mayUploadBom())->toBeFalse();
});

it('leaves an unscoped merchant unscoped even with the pivot in place', function () {
    $user = backfillUser(null);

    expect($user->merchantBuyerIds())->toBe([])
        ->and($user->merchantBuyerId())->toBeNull()
        ->and($user->mayUploadBom())->toBeTrue();
});

it('leaves department admin ownership untouched by the pivot', function () {
    Role::findOrCreate('merchant', 'web');
    $admin = User::factory()->create(['is_department_admin' => true])->assignRole('merchant');

    $this->buyerA->update(['department_admin_id' => $admin->id]);
    $this->buyerB->update(['department_admin_id' => $admin->id]);

    // Ownership is read from buyers.department_admin_id, never from the pivot.
    expect($admin->merchantBuyerIds())->toBe([$this->buyerA->id, $this->buyerB->id])
        ->and(DB::table('buyer_user')->count())->toBe(0);
});
