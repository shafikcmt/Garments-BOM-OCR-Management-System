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
 */
it('reports no buyers for any other department, even with a buyer_id set', function (string $role) {
    expect(buyerScopeUser($role, ['buyer_id' => $this->buyerA->id])->merchantBuyerIds())->toBe([]);
})->with(['commercial', 'store', 'account', 'supply_chain', 'production', 'management', 'admin']);

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
 * is meaningless outside Merchandising, and these roles must reach the file
 * untouched even when one is set.
 */
it('never scopes any other department', function (string $role) {
    $user = buyerScopeUser($role, ['buyer_id' => $this->buyerA->id]);

    expect($user->merchantBuyerId())->toBeNull()
        ->and(buyerScopeFile($this->buyerB->id)->isBuyerLockedForUser($user))->toBeFalse();
})->with(['commercial', 'store', 'account', 'supply_chain', 'production', 'management']);

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

it('refuses a normal merchant granting upload to anyone, including themselves', function () {
    $user = buyerScopeUser('merchant', ['buyer_id' => $this->buyerA->id]);
    $peer = buyerScopeUser('merchant', ['buyer_id' => $this->buyerA->id]);

    expect($user->can('setCanUpload', $peer))->toBeFalse()
        ->and($user->can('setCanUpload', $user))->toBeFalse();
});
