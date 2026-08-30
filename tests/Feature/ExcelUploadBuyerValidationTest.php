<?php

use App\Http\Requests\ExcelUploadRequest;
use App\Models\Buyer;
use App\Models\ExcelFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Which buyer an upload may be tagged with.
 *
 * THE TEST THAT MATTERS IS THE FIRST ONE. A merchant posting a buyer_id that
 * is not theirs must be REFUSED — not ignored, not quietly replaced with their
 * own buyer, not accepted. Everything else here exists to prove that refusal
 * did not come at the cost of the people who were working fine already.
 *
 * The rule being guarded: the controller used to read
 * `merchantBuyerId() ?? $request->integer('buyer_id')`, where a null scope
 * meant "may pick anything". Implementing multi-buyer by returning null when
 * there is no single answer would have let a multi-buyer merchant tag a file
 * to any buyer in the system.
 */
function uploadUser(array $attributes = [], string $role = 'merchant'): User
{
    Role::findOrCreate($role, 'web');

    return User::factory()->create($attributes)->assignRole($role);
}

function uploadFile(): UploadedFile
{
    // Real content, so the mimetypes rule sees text/plain rather than an
    // empty file it cannot identify.
    return UploadedFile::fake()->createWithContent('bom.csv', "Buyer Name,Style Name\nHugo,ST-1\n");
}

/**
 * Runs the request exactly as the framework would — authorize(),
 * prepareForValidation(), then the rules — without involving the controller,
 * which is deliberately not wired to this yet.
 *
 * @return array<string, mixed>  validated data
 */
function validateUpload(User $user, array $payload): array
{
    $request = ExcelUploadRequest::create('/merchant/excel/upload', 'POST', $payload, [], [
        'file' => uploadFile(),
    ]);

    // Both are needed. setUserResolver covers $request->user(); the request's
    // authorize() and allowedBuyerIds() read the global auth() guard, which
    // the resolver does not touch.
    auth()->login($user);
    $request->setUserResolver(fn () => $user);
    $request->setContainer(app())->setRedirector(app('redirect'));
    $request->validateResolved();

    return $request->validated();
}

beforeEach(function () {
    $this->mine = Buyer::create(['buyer_name' => 'Mine']);
    $this->alsoMine = Buyer::create(['buyer_name' => 'Also Mine']);
    $this->theirs = Buyer::create(['buyer_name' => 'Somebody Elses']);
});

// =========================================================================
// THE ONE THAT MATTERS
// =========================================================================

it('REFUSES a buyer_id the user is not assigned to', function () {
    $user = uploadUser(['buyer_id' => $this->mine->id, 'can_upload' => true]);
    $user->buyers()->attach([$this->mine->id, $this->alsoMine->id]);

    expect(fn () => validateUpload($user, ['buyer_id' => $this->theirs->id]))
        ->toThrow(function (ValidationException $e) {
            expect($e->errors())->toHaveKey('buyer_id')
                ->and($e->errors()['buyer_id'][0])->toContain('not assigned to that buyer');
        });
});

it('refuses it through a real POST too, and records nothing', function () {
    $user = uploadUser(['buyer_id' => $this->mine->id, 'can_upload' => true]);
    $user->buyers()->attach([$this->mine->id, $this->alsoMine->id]);

    $this->actingAs($user)
        ->from(route('merchant.workspace'))
        ->post(route('merchant.excel.store'), [
            'file' => uploadFile(),
            'buyer_id' => $this->theirs->id,
        ])
        ->assertSessionHasErrors('buyer_id');

    // The refusal has to stop the upload, not merely complain about it.
    expect(ExcelFile::count())->toBe(0);
});

it('refuses a single-buyer user posting somebody elses buyer, rather than ignoring it', function () {
    // Previously the controller took this user's own buyer and threw the
    // posted value away. Silent, and it worked — but it meant the field was
    // never checked, which is exactly what would not survive multi-buyer.
    $user = uploadUser(['buyer_id' => $this->mine->id, 'can_upload' => true]);
    $user->buyers()->attach($this->mine->id);

    expect(fn () => validateUpload($user, ['buyer_id' => $this->theirs->id]))
        ->toThrow(ValidationException::class);
});

it('refuses a buyer that does not exist at all', function () {
    $user = uploadUser(['buyer_id' => $this->mine->id, 'can_upload' => true]);

    expect(fn () => validateUpload($user, ['buyer_id' => 999999]))
        ->toThrow(ValidationException::class);
});

// =========================================================================
// The single-buyer user must not notice any of this
// =========================================================================

it('fills in the only buyer for a user who is never shown a chooser', function () {
    $user = uploadUser(['buyer_id' => $this->mine->id, 'can_upload' => true]);
    $user->buyers()->attach($this->mine->id);

    // Their form posts no buyer_id — it renders a read-only chip.
    $validated = validateUpload($user, []);

    expect($validated['buyer_id'])->toBe($this->mine->id);
});

it('fills it in for a single-buyer user who has no pivot row yet', function () {
    // The backfill has not reached them. merchantBuyerIds() still answers from
    // users.buyer_id, so the chip keeps working.
    $user = uploadUser(['buyer_id' => $this->mine->id, 'can_upload' => true]);

    expect(validateUpload($user, [])['buyer_id'])->toBe($this->mine->id);
});

it('accepts a single-buyer user posting their own buyer', function () {
    $user = uploadUser(['buyer_id' => $this->mine->id, 'can_upload' => true]);

    expect(validateUpload($user, ['buyer_id' => $this->mine->id])['buyer_id'])->toBe($this->mine->id);
});

// =========================================================================
// Multi-buyer: a choice, and a real one
// =========================================================================

it('accepts either of the buyers a multi-buyer user actually holds', function () {
    $user = uploadUser(['buyer_id' => $this->mine->id, 'can_upload' => true]);
    $user->buyers()->attach([$this->mine->id, $this->alsoMine->id]);

    expect(validateUpload($user, ['buyer_id' => $this->mine->id])['buyer_id'])->toBe($this->mine->id)
        ->and(validateUpload($user, ['buyer_id' => $this->alsoMine->id])['buyer_id'])->toBe($this->alsoMine->id);
});

it('makes a multi-buyer user choose, rather than defaulting silently', function () {
    $user = uploadUser(['buyer_id' => $this->mine->id, 'can_upload' => true]);
    $user->buyers()->attach([$this->mine->id, $this->alsoMine->id]);

    expect(fn () => validateUpload($user, []))->toThrow(ValidationException::class);
});

it('lets a department admin tag either buyer they own', function () {
    $admin = uploadUser(['is_department_admin' => true]);
    $this->mine->update(['department_admin_id' => $admin->id]);
    $this->alsoMine->update(['department_admin_id' => $admin->id]);

    expect(validateUpload($admin, ['buyer_id' => $this->alsoMine->id])['buyer_id'])->toBe($this->alsoMine->id)
        ->and(fn () => validateUpload($admin, ['buyer_id' => $this->theirs->id]))
            ->toThrow(ValidationException::class);
});

// =========================================================================
// Unscoped stays unrestricted, by design
// =========================================================================

it('lets an unscoped merchant pick any buyer, exactly as before', function () {
    $user = uploadUser();

    expect(validateUpload($user, ['buyer_id' => $this->theirs->id])['buyer_id'])->toBe($this->theirs->id);
});

it('still makes an unscoped merchant pick something', function () {
    expect(fn () => validateUpload(uploadUser(), []))->toThrow(ValidationException::class);
});

it('still refuses an unscoped merchant naming a buyer that does not exist', function () {
    expect(fn () => validateUpload(uploadUser(), ['buyer_id' => 999999]))
        ->toThrow(ValidationException::class);
});

/**
 * A buyer_id sitting on a non-Merchandising user's row means nothing —
 * merchantBuyerIds() refuses to read it — so they are unscoped here and must
 * not find themselves confined by a column that does not apply to them.
 */
it('treats a non-merchant user as unscoped even with a buyer_id column set', function () {
    // store_general_stock, not store: Store is a scoped department now, and its
    // General Stock role is the one carved back out of it.
    $user = uploadUser(['buyer_id' => $this->mine->id], 'store_general_stock');

    expect(validateUpload($user, ['buyer_id' => $this->theirs->id])['buyer_id'])->toBe($this->theirs->id);
});
