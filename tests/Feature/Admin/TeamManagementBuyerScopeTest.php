<?php

use App\Models\Buyer;
use App\Models\User;
use App\Support\BuyerScope;
use Spatie\Permission\Models\Role;

/**
 * Buyer scoping on Team Management (Admin › Users).
 *
 * THE BUG THIS EXISTS FOR. UserPolicy::administers() compared departmentKey()
 * and nothing else, so in Merchandising — where there is one team per buyer —
 * the admin who owns MACYS saw, edited, reset passwords for and DELETED the
 * Hugo Boss team. The screen showed their names, emails, roles and last sign-in.
 *
 * Found on the live server and reproduced here against the same shape of data:
 * two Merchandising department admins, one buyer each, a non-admin member on
 * each buyer.
 *
 * WHAT IS NOT TESTED HERE, because it did not move: a department admin owning
 * no buyer still administers their whole department. Every Store, Commercial
 * and Accounts admin is that case today, and DepartmentAdminUserManagementTest
 * covers it — the first test below only pins it so this change cannot quietly
 * take it away.
 *
 * These run on the in-memory sqlite database configured in phpunit.xml.
 */
beforeEach(function () {
    foreach (['admin', 'merchant', 'store', 'store_general_stock'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

/** A Merchandising department admin owning the given buyer names. */
function tmAdminOwning(array $buyerNames): array
{
    $admin = User::factory()->create(['status' => 1, 'is_department_admin' => true])
        ->assignRole('merchant');

    $buyers = [];

    foreach ($buyerNames as $name) {
        $buyers[$name] = Buyer::create([
            'buyer_name' => $name,
            'department_admin_id' => $admin->id,
        ]);
    }

    return [$admin->fresh(), $buyers];
}

/** A non-admin merchant confined to the given buyers. */
function tmMemberOn(array $buyerIds): User
{
    $user = User::factory()->create([
        'status' => 1,
        'buyer_id' => $buyerIds[0] ?? null,
        'buyer_scope_mode' => $buyerIds === [] ? BuyerScope::MODE_ALL : BuyerScope::MODE_SPECIFIC,
    ])->assignRole('merchant');

    if ($buyerIds !== []) {
        $user->buyers()->sync($buyerIds);
    }

    return $user->fresh();
}

function tmVisibleIds(User $actor): array
{
    return test()->actingAs($actor)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->viewData('users')
        ->pluck('id')
        ->all();
}

/*
|--------------------------------------------------------------------------
| The regression itself
|--------------------------------------------------------------------------
*/

it('hides another buyer\'s team member from a buyer-owning department admin', function () {
    [$macysAdmin, $macysBuyers] = tmAdminOwning(['MACYS']);
    [, $hugoBuyers] = tmAdminOwning(['Hugo Boss']);

    $mine = tmMemberOn([$macysBuyers['MACYS']->id]);
    $theirs = tmMemberOn([$hugoBuyers['Hugo Boss']->id]);

    $ids = tmVisibleIds($macysAdmin);

    expect($ids)->toContain($mine->id)
        ->and($ids)->toContain($macysAdmin->id)
        ->and($ids)->not->toContain($theirs->id);
});

/**
 * THE HALF THAT MATTERED MOST. A list that omits a row and an action that is
 * refused are different controls, and the leak was never only about reading:
 * the same predicate gates editing, password reset and deletion.
 */
it('refuses every write against another buyer\'s team member', function () {
    [$macysAdmin, $macysBuyers] = tmAdminOwning(['MACYS']);
    [, $hugoBuyers] = tmAdminOwning(['Hugo Boss']);

    $theirs = tmMemberOn([$hugoBuyers['Hugo Boss']->id]);
    $mine = tmMemberOn([$macysBuyers['MACYS']->id]);

    foreach (['view', 'update', 'delete', 'resetPassword'] as $ability) {
        expect($macysAdmin->can($ability, $theirs))->toBeFalse("$ability leaked")
            ->and($macysAdmin->can($ability, $mine))->toBeTrue("$ability wrongly refused");
    }

    // The route, not just the predicate — a guessed URL arrives here too.
    $this->actingAs($macysAdmin)
        ->get(route('admin.users.edit', $theirs))
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| The three branches of coversBuyers(), each of which is a hole if dropped
|--------------------------------------------------------------------------
*/

it('leaves a department admin who owns no buyer administering their whole department', function () {
    $storeAdmin = User::factory()->create(['status' => 1, 'is_department_admin' => true])
        ->assignRole('store');

    $member = User::factory()->create(['status' => 1])->assignRole('store_general_stock');

    expect($storeAdmin->scopedBuyerIds())->toBe([])
        ->and($storeAdmin->can('view', $member))->toBeTrue()
        ->and($storeAdmin->can('update', $member))->toBeTrue()
        ->and(tmVisibleIds($storeAdmin))->toContain($member->id);
});

it('keeps a member holding no buyer visible to every department admin', function () {
    [$macysAdmin] = tmAdminOwning(['MACYS']);

    // The user who was just created and not yet put on a buyer. Hiding them
    // would leave nobody but the super admin able to assign them one.
    $unassigned = tmMemberOn([]);

    expect($macysAdmin->can('view', $unassigned))->toBeTrue()
        ->and($macysAdmin->can('update', $unassigned))->toBeTrue()
        ->and(tmVisibleIds($macysAdmin))->toContain($unassigned->id);
});

it('reaches every team of an admin who owns several buyers, and no further', function () {
    [$bothAdmin, $buyers] = tmAdminOwning(['MACYS', 'Hugo Boss']);
    [, $otherBuyers] = tmAdminOwning(['TARGET']);

    $onMacys = tmMemberOn([$buyers['MACYS']->id]);
    $onHugo = tmMemberOn([$buyers['Hugo Boss']->id]);
    $onBoth = tmMemberOn([$buyers['MACYS']->id, $buyers['Hugo Boss']->id]);
    $outside = tmMemberOn([$otherBuyers['TARGET']->id]);

    // Spans one buyer this admin owns and one they do not. STILL THEIRS: the
    // rule is that they share a buyer, not that the member is wholly ours. A
    // subset rule here would 403 this admin out of a member of their own MACYS
    // team merely because a super admin had also put them on TARGET — and
    // UserController deliberately preserves that outside buyer on save rather
    // than stripping it (BuyerAssignmentOnUserEditTest, "leaves buyers outside
    // the acting admin reach untouched"). The two must agree.
    $spanning = tmMemberOn([$buyers['MACYS']->id, $otherBuyers['TARGET']->id]);

    $ids = tmVisibleIds($bothAdmin);

    expect($ids)->toContain($onMacys->id)
        ->and($ids)->toContain($onHugo->id)
        ->and($ids)->toContain($onBoth->id)
        ->and($ids)->toContain($spanning->id)
        ->and($ids)->not->toContain($outside->id);
});

/*
|--------------------------------------------------------------------------
| Unchanged neighbours
|--------------------------------------------------------------------------
*/

it('leaves the super admin seeing everybody', function () {
    $super = User::factory()->create(['status' => 1])->assignRole('admin');

    [, $macysBuyers] = tmAdminOwning(['MACYS']);
    [, $hugoBuyers] = tmAdminOwning(['Hugo Boss']);

    $mine = tmMemberOn([$macysBuyers['MACYS']->id]);
    $theirs = tmMemberOn([$hugoBuyers['Hugo Boss']->id]);

    $ids = tmVisibleIds($super);

    expect($ids)->toContain($mine->id)
        ->and($ids)->toContain($theirs->id);
});

/**
 * The stat tiles are counted over the visible collection, so they follow the
 * scope on their own. Pinned because a later change that counts over User::all()
 * would put another buyer's headcount back on the screen without listing a
 * single row — a quieter version of the same leak.
 */
it('counts the stat tiles over the visible users only', function () {
    [$macysAdmin, $macysBuyers] = tmAdminOwning(['MACYS']);
    [, $hugoBuyers] = tmAdminOwning(['Hugo Boss']);

    tmMemberOn([$macysBuyers['MACYS']->id]);
    tmMemberOn([$hugoBuyers['Hugo Boss']->id]);

    $stats = $this->actingAs($macysAdmin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->viewData('stats');

    // The admin and their one member. Not the other buyer's admin or member.
    expect($stats['total'])->toBe(2);
});
