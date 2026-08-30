<?php

use App\Models\Buyer;
use App\Models\ExcelFile;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Buyer scoping, which exists for Merchandising and for nowhere else.
 *
 * The rule is narrow on purpose: a merchant assigned to a buyer may not edit
 * another buyer's file. Everything around that rule must be untouched — every
 * other department, the super admin, unscoped merchants, and files uploaded
 * before buyer tagging existed. Those are the cases that break a working
 * factory, so most of what follows asserts that nothing happened.
 */
function buyerScopeUser(string $role, array $attributes = []): User
{
    Role::findOrCreate($role, 'web');

    return User::factory()->create($attributes)->assignRole($role);
}

function buyerScopeFile(?int $buyerId): ExcelFile
{
    return ExcelFile::create([
        'file_name' => 'bom.xlsx',
        'original_file_name' => 'bom.xlsx',
        'file_path' => 'bom.xlsx',
        'status' => 'processing',
        'uploaded_by' => User::factory()->create()->id,
        'buyer_id' => $buyerId,
        'total_rows' => 0,
    ]);
}

beforeEach(function () {
    $this->buyerA = Buyer::create(['buyer_name' => 'Buyer A']);
    $this->buyerB = Buyer::create(['buyer_name' => 'Buyer B']);
});

// --- The rule itself ------------------------------------------------------

it('blocks a scoped merchant from editing another buyer file', function () {
    $user = buyerScopeUser('merchant', ['buyer_id' => $this->buyerA->id]);

    expect(buyerScopeFile($this->buyerB->id)->isBuyerLockedForUser($user))->toBeTrue();
});

it('allows a scoped merchant to edit their own buyer file', function () {
    $user = buyerScopeUser('merchant', ['buyer_id' => $this->buyerA->id]);

    expect(buyerScopeFile($this->buyerA->id)->isBuyerLockedForUser($user))->toBeFalse();
});

it('scopes a department admin by the buyer they own, not by users.buyer_id', function () {
    $admin = buyerScopeUser('merchant', ['is_department_admin' => true]);
    $this->buyerA->update(['department_admin_id' => $admin->id]);

    expect($admin->merchantBuyerId())->toBe($this->buyerA->id)
        ->and(buyerScopeFile($this->buyerB->id)->isBuyerLockedForUser($admin))->toBeTrue()
        ->and(buyerScopeFile($this->buyerA->id)->isBuyerLockedForUser($admin))->toBeFalse();
});

// --- An admin may own several buyers --------------------------------------
//
// The column always allowed it; a BuyerController hook used to null the
// others out. With that hook gone, these pin what the model now answers.

it('reports every buyer a department admin owns', function () {
    $admin = buyerScopeUser('merchant', ['is_department_admin' => true]);
    $this->buyerA->update(['department_admin_id' => $admin->id]);
    $this->buyerB->update(['department_admin_id' => $admin->id]);

    expect($admin->merchantBuyerIds())->toBe([$this->buyerA->id, $this->buyerB->id]);
});

/**
 * merchantBuyerId() has to keep answering something while the comparison
 * sites still read it, and "whichever the database returned first" is not an
 * answer — it could differ between MySQL and PostgreSQL, or after a reindex.
 */
it('picks the lowest-numbered buyer as the primary, whatever order they were assigned', function () {
    $admin = buyerScopeUser('merchant', ['is_department_admin' => true]);

    // Deliberately assigned newest-first.
    $this->buyerB->update(['department_admin_id' => $admin->id]);
    $this->buyerA->update(['department_admin_id' => $admin->id]);

    expect($admin->merchantBuyerId())->toBe($this->buyerA->id);
});

it('leaves a single-buyer admin answering exactly as before', function () {
    $admin = buyerScopeUser('merchant', ['is_department_admin' => true]);
    $this->buyerA->update(['department_admin_id' => $admin->id]);

    expect($admin->merchantBuyerIds())->toBe([$this->buyerA->id])
        ->and($admin->merchantBuyerId())->toBe($this->buyerA->id);
});

it('reports the one inherited buyer for a normal scoped merchant', function () {
    $user = buyerScopeUser('merchant', ['buyer_id' => $this->buyerA->id]);

    expect($user->merchantBuyerIds())->toBe([$this->buyerA->id]);
});

it('reports no buyers at all for an unscoped merchant', function () {
    expect(buyerScopeUser('merchant')->merchantBuyerIds())->toBe([]);
});

it('reports no buyers for a department admin who owns none', function () {
    $admin = buyerScopeUser('merchant', ['is_department_admin' => true]);

    expect($admin->merchantBuyerIds())->toBe([])
        ->and($admin->merchantBuyerId())->toBeNull();
});

/**
 * The same guarantee the single-buyer version carries, restated for the new
 * method: the departments with no buyer concept must not acquire one.
 *
 * supply_chain, commercial and account are deliberately NOT in this list. All
 * three were, while BuyerScope::SCOPED_DEPARTMENTS named fewer departments;
 * each is scoped now and the test below asserts the opposite for it. Adding a
 * department to that const is expected to move it from this list to that one —
 * if a role here starts failing, check the const before changing the test.
 */
it('reports no buyers for any other department, even with a buyer_id set', function (string $role) {
    expect(buyerScopeUser($role, ['buyer_id' => $this->buyerA->id])->merchantBuyerIds())->toBe([]);
})->with(['store_general_stock', 'production', 'management', 'admin']);

it('reports the buyers of a scoped department other than Merchandising', function (string $role) {
    $user = buyerScopeUser($role, ['buyer_id' => $this->buyerA->id]);

    expect($user->scopedBuyerIds())->toBe([$this->buyerA->id])
        ->and($user->isBuyerConfined())->toBeTrue();
})->with(['supply_chain', 'commercial', 'account', 'store', 'store_material_stock']);

// --- The lock reads every buyer, not just the primary ---------------------

it('lets a department admin edit files of EITHER buyer they own', function () {
    $admin = buyerScopeUser('merchant', ['is_department_admin' => true]);
    $this->buyerA->update(['department_admin_id' => $admin->id]);
    $this->buyerB->update(['department_admin_id' => $admin->id]);

    // buyerB is not the primary — under the old single-value comparison this
    // was locked, while the screen said the admin owned it.
    expect(buyerScopeFile($this->buyerA->id)->isBuyerLockedForUser($admin))->toBeFalse()
        ->and(buyerScopeFile($this->buyerB->id)->isBuyerLockedForUser($admin))->toBeFalse();
});

it('still locks a department admin out of a buyer they do not own', function () {
    $admin = buyerScopeUser('merchant', ['is_department_admin' => true]);
    $this->buyerA->update(['department_admin_id' => $admin->id]);

    $outside = Buyer::create(['buyer_name' => 'Buyer C']);

    expect(buyerScopeFile($outside->id)->isBuyerLockedForUser($admin))->toBeTrue();
});

it('lets a team member edit files of any buyer assigned to them', function () {
    $user = buyerScopeUser('merchant', ['buyer_id' => $this->buyerA->id]);
    $user->buyers()->attach([$this->buyerA->id, $this->buyerB->id]);

    expect(buyerScopeFile($this->buyerB->id)->isBuyerLockedForUser($user->fresh()))->toBeFalse();
});

it('still locks a team member out of a buyer nobody assigned them', function () {
    $user = buyerScopeUser('merchant', ['buyer_id' => $this->buyerA->id]);
    $user->buyers()->attach($this->buyerA->id);

    expect(buyerScopeFile($this->buyerB->id)->isBuyerLockedForUser($user->fresh()))->toBeTrue();
});

/**
 * The lock has to STOP the write, not just describe it. Same standard the
 * upload validation is held to: assert the data did not change.
 */
it('refuses the save itself, not just with a warning', function () {
    $user = buyerScopeUser('merchant', ['buyer_id' => $this->buyerA->id]);
    $file = buyerScopeFile($this->buyerB->id);

    $this->actingAs($user)
        ->post(route('uploaded-files.rows.store', $file->id))
        ->assertRedirect(route('uploaded-files.show', $file->id))
        ->assertSessionHas('warning');

    expect($file->rows()->count())->toBe(0);
});

it('allows that same write once the buyer is one of theirs', function () {
    $user = buyerScopeUser('merchant', ['buyer_id' => $this->buyerA->id]);
    $user->buyers()->attach([$this->buyerA->id, $this->buyerB->id]);
    $file = buyerScopeFile($this->buyerB->id);

    $this->actingAs($user->fresh())
        ->post(route('uploaded-files.rows.store', $file->id));

    expect($file->rows()->count())->toBe(1);
});

// --- Nothing breaks retroactively -----------------------------------------

it('leaves an unscoped merchant unrestricted', function () {
    $user = buyerScopeUser('merchant');

    expect(buyerScopeFile($this->buyerB->id)->isBuyerLockedForUser($user))->toBeFalse();
});

it('leaves files uploaded before buyer tagging editable by everyone', function () {
    $user = buyerScopeUser('merchant', ['buyer_id' => $this->buyerA->id]);

    expect(buyerScopeFile(null)->isBuyerLockedForUser($user))->toBeFalse();
});

it('never scopes the super admin', function () {
    $admin = buyerScopeUser('admin', ['buyer_id' => $this->buyerA->id]);

    expect(buyerScopeFile($this->buyerB->id)->isBuyerLockedForUser($admin))->toBeFalse();
});

it('never scopes a user with no account at all', function () {
    expect(buyerScopeFile($this->buyerB->id)->isBuyerLockedForUser(null))->toBeFalse();
});

/**
 * The guarantee the rest of the factory depends on. A buyer_id on the user row
 * is meaningless outside a buyer-scoped department, and these roles must reach
 * the file untouched even when one is set.
 *
 * supply_chain, commercial and account each left this list when their
 * department was added to BuyerScope::SCOPED_DEPARTMENTS — see the tests below.
 * What remains here is every department that genuinely has no buyer concept.
 */
it('never scopes any other department', function (string $role) {
    $user = buyerScopeUser($role, ['buyer_id' => $this->buyerA->id]);

    expect($user->merchantBuyerId())->toBeNull()
        ->and(buyerScopeFile($this->buyerB->id)->isBuyerLockedForUser($user))->toBeFalse();
})->with(['store_general_stock', 'production', 'management']);

it('locks a scoped user of any scoped department out of another buyer file', function (string $role) {
    $user = buyerScopeUser($role, ['buyer_id' => $this->buyerA->id]);

    expect(buyerScopeFile($this->buyerB->id)->isBuyerLockedForUser($user))->toBeTrue()
        ->and(buyerScopeFile($this->buyerA->id)->isBuyerLockedForUser($user))->toBeFalse()
        // An untagged file stays editable, the rule every scoped department
        // shares.
        ->and(buyerScopeFile(null)->isBuyerLockedForUser($user))->toBeFalse();
})->with(['supply_chain', 'commercial', 'account', 'store', 'store_material_stock']);

/* THE DEFAULT, restated per department: switching a department on confines
 * NOBODY by itself. Every existing user in it holds no buyer and stays
 * unrestricted until an admin assigns one deliberately. */
it('leaves an unassigned user of a scoped department unscoped, as before', function (string $role) {
    $user = buyerScopeUser($role);

    // ->fresh() deliberately: buyer_scope_mode is filled by the COLUMN DEFAULT,
    // so the in-memory instance the factory just built has not seen it. Reading
    // it back from the database is the assertion that matters here — it is the
    // default itself, not the model, that keeps a whole department unconfined.
    expect($user->isBuyerConfined())->toBeFalse()
        ->and($user->fresh()->buyer_scope_mode)->toBe(\App\Support\BuyerScope::MODE_ALL)
        ->and(buyerScopeFile($this->buyerB->id)->isBuyerLockedForUser($user))->toBeFalse();
})->with(['supply_chain', 'commercial', 'account', 'store', 'store_material_stock']);

// --- Upload gate ----------------------------------------------------------

it('lets an unscoped merchant upload, as today', function () {
    expect(buyerScopeUser('merchant')->mayUploadBom())->toBeTrue();
});

it('blocks a scoped merchant from uploading by default', function () {
    expect(buyerScopeUser('merchant', ['buyer_id' => $this->buyerA->id])->mayUploadBom())->toBeFalse();
});

it('lets a scoped merchant upload once granted the override', function () {
    $user = buyerScopeUser('merchant', ['buyer_id' => $this->buyerA->id, 'can_upload' => true]);

    expect($user->mayUploadBom())->toBeTrue();
});

it('lets the owning department admin upload without an override', function () {
    $admin = buyerScopeUser('merchant', ['is_department_admin' => true]);
    $this->buyerA->update(['department_admin_id' => $admin->id]);

    expect($admin->mayUploadBom())->toBeTrue();
});

// --- Who may grant the override -------------------------------------------

it('lets the owning department admin grant upload to their own team member', function () {
    $admin = buyerScopeUser('merchant', ['is_department_admin' => true]);
    $this->buyerA->update(['department_admin_id' => $admin->id]);

    $member = buyerScopeUser('merchant', ['buyer_id' => $this->buyerA->id]);

    expect($admin->can('setCanUpload', $member))->toBeTrue();
});

it('refuses a department admin granting upload on another buyer team', function () {
    $admin = buyerScopeUser('merchant', ['is_department_admin' => true]);
    $this->buyerA->update(['department_admin_id' => $admin->id]);

    $outsider = buyerScopeUser('merchant', ['buyer_id' => $this->buyerB->id]);

    expect($admin->can('setCanUpload', $outsider))->toBeFalse();
});

// --- The subset rule, restated for several buyers -------------------------

it('lets an admin grant upload on a team member of their SECOND buyer', function () {
    $admin = buyerScopeUser('merchant', ['is_department_admin' => true]);
    $this->buyerA->update(['department_admin_id' => $admin->id]);
    $this->buyerB->update(['department_admin_id' => $admin->id]);

    // Under the old single-value comparison this member was out of reach,
    // because buyerB is not the admin's primary.
    $member = buyerScopeUser('merchant', ['buyer_id' => $this->buyerB->id]);

    expect($admin->fresh()->can('setCanUpload', $member))->toBeTrue();
});

/**
 * The subset rule itself. Widening the actor's side to a set must not widen
 * the target's — an admin still reaches only buyers they hold.
 */
it('still refuses an admin granting upload on a buyer they do not own', function () {
    $admin = buyerScopeUser('merchant', ['is_department_admin' => true]);
    $this->buyerA->update(['department_admin_id' => $admin->id]);

    $outside = Buyer::create(['buyer_name' => 'Buyer C']);
    $outsider = buyerScopeUser('merchant', ['buyer_id' => $outside->id]);

    expect($admin->fresh()->can('setCanUpload', $outsider))->toBeFalse();
});

/**
 * The hole the set comparison could have opened. A target assigned to one of
 * the admin's buyers AND one outside it is not wholly theirs — granting upload
 * would hand rights that reach a buyer the admin has no claim on. Overlap is
 * not enough; the target's whole set must sit inside the actor's.
 */
it('refuses when the target also holds a buyer outside the admins own', function () {
    $admin = buyerScopeUser('merchant', ['is_department_admin' => true]);
    $this->buyerA->update(['department_admin_id' => $admin->id]);

    $outside = Buyer::create(['buyer_name' => 'Buyer C']);
    $member = buyerScopeUser('merchant', ['buyer_id' => $this->buyerA->id]);
    $member->buyers()->attach([$this->buyerA->id, $outside->id]);

    expect($admin->fresh()->can('setCanUpload', $member->fresh()))->toBeFalse();
});

it('refuses an admin who owns no buyer at all', function () {
    $admin = buyerScopeUser('merchant', ['is_department_admin' => true]);
    $member = buyerScopeUser('merchant', ['buyer_id' => $this->buyerA->id]);

    expect($admin->can('setCanUpload', $member))->toBeFalse();
});

it('refuses granting upload to an unscoped merchant', function () {
    $admin = buyerScopeUser('merchant', ['is_department_admin' => true]);
    $this->buyerA->update(['department_admin_id' => $admin->id]);

    expect($admin->fresh()->can('setCanUpload', buyerScopeUser('merchant')))->toBeFalse();
});

it('refuses a normal merchant granting upload to anyone, including themselves', function () {
    $user = buyerScopeUser('merchant', ['buyer_id' => $this->buyerA->id]);
    $peer = buyerScopeUser('merchant', ['buyer_id' => $this->buyerA->id]);

    expect($user->can('setCanUpload', $peer))->toBeFalse()
        ->and($user->can('setCanUpload', $user))->toBeFalse();
});
