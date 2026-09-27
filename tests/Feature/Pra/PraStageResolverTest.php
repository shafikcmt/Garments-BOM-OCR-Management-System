<?php

use App\Models\Buyer;
use App\Models\ExcelFile;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestItem;
use App\Models\PraApproval;
use App\Models\User;
use App\Services\PraStageResolver;
use App\Support\BuyerScope;
use Spatie\Permission\Models\Role;

/**
 * WHO ACTS AT EACH STEP of the 6-step departmental PRA chain.
 *
 * The resolver is the seam the whole workflow hangs on: it is the only place
 * that turns "Merchant Team" into a list of people. Every rule the business
 * gave is pinned here, because each one is a sentence that will otherwise be
 * re-litigated from memory in six months:
 *
 *   - any ONE of a department's heads satisfies the step, not all of them;
 *   - Merchant steps are confined to the PRA's buyer, Accounts and Commercial
 *     are deliberately NOT;
 *   - an unfilled post BLOCKS with a message naming the fix, rather than
 *     skipping the step or falling back to admin.
 */
function praRole(string $name): void
{
    Role::findOrCreate($name, 'web');
}

function praUser(string $role, bool $head = false, string $name = 'X'): User
{
    praRole($role);

    $user = User::factory()->create(['is_department_admin' => $head, 'name' => $name]);
    $user->assignRole($role);

    return $user->fresh();
}

/** One BOM file on the given buyer (null = untagged). */
function praBomFile(?int $buyerId): ExcelFile
{
    return ExcelFile::create([
        'file_name' => 'bom.xlsx',
        'original_file_name' => 'bom.xlsx',
        'file_path' => 'bom.xlsx',
        'uploaded_by' => User::factory()->create()->id,
        'buyer_id' => $buyerId,
        'total_rows' => 1,
    ]);
}

/** A PRA whose single item points at a BOM file tagged with $buyer. */
function praFor(?Buyer $buyer): PaymentRequest
{
    $pr = PaymentRequest::create([
        'request_no' => 'PRA-' . fake()->unique()->numerify('#####'),
        'status' => PaymentRequest::STATUS_PENDING_CHECK,
        'flow_version' => 2,
    ]);

    $file = praBomFile($buyer?->id);

    PaymentRequestItem::create([
        'payment_request_id' => $pr->id,
        'excel_file_id' => $file->id,
    ]);

    return $pr->fresh();
}

/** Buyer Access — the pivot, which is what confines a merchant team member. */
function praGrantAccess(User $user, Buyer $buyer): User
{
    $user->buyer_scope_mode = BuyerScope::MODE_SPECIFIC;
    $user->save();
    $user->buyers()->sync([$buyer->id]);

    return $user->fresh();
}

beforeEach(function () {
    $this->resolver = app(PraStageResolver::class);
    $this->hugo = Buyer::create(['buyer_name' => 'Hugo Boss']);
    $this->macys = Buyer::create(['buyer_name' => 'MACYS']);
});

// --- The step table ---------------------------------------------------------

it('defines exactly the five actionable steps, 2 through 6', function () {
    // Step 1 is the creator's own and deliberately has no entry: nobody acts on
    // a step they authored, and inventing a row for them would put a decision in
    // the audit trail that never happened.
    expect($this->resolver->sequences())->toBe([2, 3, 4, 5, 6]);
});

it('walks the chain in order and stops after the last step', function () {
    expect($this->resolver->nextSequence(2))->toBe(3)
        ->and($this->resolver->nextSequence(5))->toBe(6)
        ->and($this->resolver->nextSequence(6))->toBeNull();
});

it('refuses an unknown sequence rather than guessing', function () {
    expect(fn () => $this->resolver->definitionFor(9))
        ->toThrow(InvalidArgumentException::class);
});

it('routes each step to the right department, tier and stage', function (
    int $seq, string $department, string $kind, string $stage
) {
    expect($this->resolver->definitionFor($seq))
        ->toMatchArray(['department' => $department, 'actor_kind' => $kind, 'stage' => $stage]);
})->with([
    [2, 'merchandising', PraApproval::ACTOR_TEAM, PraApproval::STAGE_CHECK],
    [3, 'merchandising', PraApproval::ACTOR_HEAD, PraApproval::STAGE_APPROVE],
    [4, 'accounts', PraApproval::ACTOR_TEAM, PraApproval::STAGE_CHECK],
    [5, 'accounts', PraApproval::ACTOR_HEAD, PraApproval::STAGE_APPROVE],
    [6, 'commercial', PraApproval::ACTOR_HEAD, PraApproval::STAGE_COMPLETE],
]);

// --- Buyer scoping: Merchant yes, Accounts and Commercial no ----------------

it('confines the merchant steps to the buyer and leaves the rest company-wide', function () {
    expect($this->resolver->isBuyerScoped(2))->toBeTrue()
        ->and($this->resolver->isBuyerScoped(3))->toBeTrue()
        // The business decision, pinned: a company-wide payments desk narrowed
        // to one buyer would stop seeing most of its work.
        ->and($this->resolver->isBuyerScoped(4))->toBeFalse()
        ->and($this->resolver->isBuyerScoped(5))->toBeFalse()
        ->and($this->resolver->isBuyerScoped(6))->toBeFalse();
});

it('offers step 2 only to merchant team members who hold this PRA buyer', function () {
    $mine = praGrantAccess(praUser('merchant', name: 'Mine'), $this->hugo);
    $theirs = praGrantAccess(praUser('merchant', name: 'Theirs'), $this->macys);

    $actors = $this->resolver->resolve(praFor($this->hugo), 2);

    expect($actors->includes($mine))->toBeTrue()
        ->and($actors->includes($theirs))->toBeFalse()
        ->and($actors->buyerScoped)->toBeTrue();
});

it('offers the accounts steps to the whole department whatever the buyer', function () {
    // No buyer access at all, and that is the point: Accounts is not confined.
    $team = praUser('account', name: 'Acc Team');
    $head = praUser('account', head: true, name: 'Acc Head');

    $pra = praFor($this->hugo);

    expect($this->resolver->resolve($pra, 4)->includes($team))->toBeTrue()
        ->and($this->resolver->resolve($pra, 5)->includes($head))->toBeTrue()
        ->and($this->resolver->resolve($pra, 4)->buyerScoped)->toBeFalse();
});

it('offers step 6 to the commercial head regardless of buyer', function () {
    $head = praUser('commercial', head: true, name: 'Comm Head');

    expect($this->resolver->resolve(praFor($this->macys), 6)->includes($head))->toBeTrue();
});

// --- Team vs Head are different people --------------------------------------

it('never offers a team step to a head, nor a head step to a team member', function () {
    $team = praGrantAccess(praUser('merchant', name: 'Team'), $this->hugo);
    $head = praGrantAccess(praUser('merchant', head: true, name: 'Head'), $this->hugo);
    $this->hugo->forceFill(['department_admin_id' => $head->id])->save();

    $pra = praFor($this->hugo);

    expect($this->resolver->resolve($pra, 2)->userIds())->toBe([$team->id])
        ->and($this->resolver->resolve($pra, 3)->userIds())->toBe([$head->id]);
});

it('picks the head OF THIS BUYER, not just any merchant head', function () {
    $hugoHead = praUser('merchant', head: true, name: 'Hugo Head');
    $macysHead = praUser('merchant', head: true, name: 'Macys Head');
    $this->hugo->forceFill(['department_admin_id' => $hugoHead->id])->save();
    $this->macys->forceFill(['department_admin_id' => $macysHead->id])->save();

    expect($this->resolver->resolve(praFor($this->hugo), 3)->userIds())->toBe([$hugoHead->id]);
});

it('ignores a stale buyer head who is no longer a department admin', function () {
    // department_admin_id is a plain column; demoting someone does not clear it.
    // The head query is intersected with the department query precisely so a
    // stale row cannot resurrect authority.
    $demoted = praUser('merchant', head: true, name: 'Demoted');
    $this->hugo->forceFill(['department_admin_id' => $demoted->id])->save();
    $demoted->forceFill(['is_department_admin' => false])->save();

    $actors = $this->resolver->resolve(praFor($this->hugo), 3);

    expect($actors->includes($demoted))->toBeFalse()
        ->and($actors->isActionable())->toBeFalse();
});

// --- Any one of them, not all of them ---------------------------------------

it('treats every head of a department as able to satisfy the step alone', function () {
    // Q3: any one approving carries the step. Three heads is three candidates,
    // not three required approvals — the resolver returns the whole eligible
    // set and the state machine takes the first to act.
    $a = praUser('account', head: true, name: 'A');
    $b = praUser('account', head: true, name: 'B');
    $c = praUser('account', head: true, name: 'C');

    $actors = $this->resolver->resolve(praFor($this->hugo), 5);

    expect($actors->userIds())->toHaveCount(3)
        ->and($actors->includes($a))->toBeTrue()
        ->and($actors->includes($b))->toBeTrue()
        ->and($actors->includes($c))->toBeTrue()
        ->and($actors->isActionable())->toBeTrue();
});

// --- The unfilled post: block, and say how to fix it -------------------------

it('blocks with a nameable message when a department has no head', function (
    int $seq, string $role, string $expected
) {
    // Q6. The department exists and has staff, but nobody holds the post.
    praUser($role, name: 'Only a team member');

    $actors = $this->resolver->resolve(praFor($this->hugo), $seq);

    expect($actors->isActionable())->toBeFalse()
        ->and($actors->postIsFilled)->toBeFalse()
        ->and($actors->problem())->toBe($expected);
})->with([
    [3, 'merchant', 'This PRA cannot proceed — no Merchandising Head is configured. Ask a Super Admin to assign one.'],
    [5, 'account', 'This PRA cannot proceed — no Accounts Head is configured. Ask a Super Admin to assign one.'],
    [6, 'commercial', 'This PRA cannot proceed — no Commercial Head is configured. Ask a Super Admin to assign one.'],
]);

it('blocks when a department has no team members either', function () {
    praUser('merchant', head: true, name: 'Head only');

    $actors = $this->resolver->resolve(praFor($this->hugo), 2);

    expect($actors->isActionable())->toBeFalse()
        ->and($actors->problem())->toContain('no Merchandising Team member is configured');
});

it('distinguishes an unfilled post from a post nobody in scope holds', function () {
    // THE DISTINCTION THIS VALUE OBJECT EXISTS FOR. Both give an empty user
    // list; they are different problems with different fixes, and a bare
    // Collection would render them identically as "nothing to do here".
    $head = praUser('merchant', head: true, name: 'Wrong buyer head');
    $this->macys->forceFill(['department_admin_id' => $head->id])->save();

    $actors = $this->resolver->resolve(praFor($this->hugo), 3);

    expect($actors->isActionable())->toBeFalse()
        // Somebody IS a merchant head — just not of Hugo Boss.
        ->and($actors->postIsFilled)->toBeTrue()
        ->and($actors->problem())->toContain('has access to this buyer');
});

it('says nothing is wrong when the step is fine', function () {
    praUser('commercial', head: true, name: 'Comm Head');

    expect($this->resolver->resolve(praFor($this->hugo), 6)->problem())->toBeNull();
});

// --- The untagged PRA -------------------------------------------------------

it('routes an untagged PRA to the whole merchant department and flags why', function () {
    // A PRA whose BOM file carries no buyer. Blocking these would strand every
    // untagged file; the existing "untagged record belongs to no buyer's team"
    // rule applies instead, and the caller is told so it can be shown.
    $confined = praGrantAccess(praUser('merchant', name: 'Confined'), $this->macys);

    $actors = $this->resolver->resolve(praFor(null), 2);

    expect($actors->includes($confined))->toBeTrue()
        ->and($actors->note)->toBe(PraStageResolver::NOTE_UNTAGGED_PRA)
        ->and($actors->buyerIds)->toBe([]);
});

// --- Buyer resolution -------------------------------------------------------

it('reads the PRA buyer from the BOM file, not the display name column', function () {
    $pra = praFor($this->hugo);
    // The string column disagrees on purpose; excelFile.buyer_id is authoritative.
    $pra->update(['buyer_name' => 'SOMETHING ELSE']);

    expect($this->resolver->buyerIdsFor($pra->fresh()))->toBe([$this->hugo->id]);
});

it('lets someone holding either buyer act on a PRA spanning two', function () {
    $pra = praFor($this->hugo);
    $second = praBomFile($this->macys->id);
    PaymentRequestItem::create(['payment_request_id' => $pra->id, 'excel_file_id' => $second->id]);

    $macysOnly = praGrantAccess(praUser('merchant', name: 'Macys only'), $this->macys);

    $actors = $this->resolver->resolve($pra->fresh(), 2);

    expect($actors->buyerIds)->toBe([$this->hugo->id, $this->macys->id])
        ->and($actors->includes($macysOnly))->toBeTrue();
});

// --- mayAct -----------------------------------------------------------------

it('answers mayAct consistently with the resolved set', function () {
    $team = praGrantAccess(praUser('merchant', name: 'Team'), $this->hugo);
    $outsider = praUser('supply_chain', name: 'Outsider');
    $pra = praFor($this->hugo);

    expect($this->resolver->mayAct($pra, 2, $team))->toBeTrue()
        ->and($this->resolver->mayAct($pra, 2, $outsider))->toBeFalse()
        // Right person, wrong step.
        ->and($this->resolver->mayAct($pra, 3, $team))->toBeFalse();
});
