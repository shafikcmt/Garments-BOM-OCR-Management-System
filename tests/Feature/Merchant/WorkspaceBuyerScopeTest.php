<?php

use App\Models\Buyer;
use App\Models\ExcelFile;
use App\Models\User;
use App\Support\BuyerScope;
use Spatie\Permission\Models\Role;

/**
 * Buyer scoping on the Merchandising workspace file list.
 *
 * WHY THIS FILE EXISTS, and why it asserts the opposite of what the Commercial
 * and Accounts scope tests assert about their own workspaces.
 *
 * The Merchandising list used to be deliberately unfiltered, on the reasoning
 * that buyer scoping here was an EDIT LOCK rather than a visibility filter — a
 * merchant could see another buyer's file, but opening it rendered read-only.
 * That reasoning did not survive contact with the screen: the list prints the
 * file name and, through ExcelFileSummaryService, the buyer and style beside
 * it. A merchant holding one buyer was reading other buyers' names and styles
 * off a page they could not act on. Locking the edit does not un-leak the
 * label.
 *
 * So this list is now filtered, and these tests pin the three cases that
 * decision turns on:
 *
 *   confined user   sees their own buyers' files and nothing else.
 *   untagged file   stays VISIBLE to everyone. buyer_id null means no buyer can
 *                   claim it, and every live BOM file is untagged today —
 *                   hiding the pre-tagging archive is an outage, not a scope
 *                   rule. This is the case most likely to be broken by a
 *                   well-meaning "tighten the scope" change later.
 *   unconfined user sees everything, whatever they happen to hold.
 *
 * The Commercial and Accounts workspaces are SEPARATE controllers and are still
 * unfiltered; their own tests still assert that. This change was scoped to
 * Merchandising.
 */
function mwScopeUser(array $buyerIds = [], string $mode = BuyerScope::MODE_ALL): User
{
    Role::findOrCreate('merchant', 'web');

    $user = User::factory()->create([
        'buyer_scope_mode' => $mode,
        'buyer_id' => $buyerIds[0] ?? null,
    ])->assignRole('merchant');

    if ($buyerIds !== []) {
        $user->buyers()->sync($buyerIds);
    }

    return $user->fresh();
}

function mwScopeFile(string $name, ?int $buyerId): ExcelFile
{
    return ExcelFile::create([
        'file_name' => $name,
        'original_file_name' => $name,
        'file_path' => $name,
        'status' => 'processing',
        'uploaded_by' => User::factory()->create()->id,
        'buyer_id' => $buyerId,
        'total_rows' => 0,
    ]);
}

/** The file names the workspace actually handed the view. */
function mwVisibleFileNames($response): array
{
    return collect($response->original->getData()['files'])
        ->pluck('file_name')
        ->sort()
        ->values()
        ->all();
}

beforeEach(function () {
    Role::findOrCreate('merchant', 'web');
    Role::findOrCreate('admin', 'web');

    $this->macys = Buyer::create(['buyer_name' => 'MACYS', 'is_active' => true]);
    $this->annTaylor = Buyer::create(['buyer_name' => 'ANNTAYLOR', 'is_active' => true]);
    $this->hugoBoss = Buyer::create(['buyer_name' => 'Hugo Boss', 'is_active' => true]);

    mwScopeFile('macys.xlsx', $this->macys->id);
    mwScopeFile('anntaylor.xlsx', $this->annTaylor->id);
    mwScopeFile('hugoboss.xlsx', $this->hugoBoss->id);
    mwScopeFile('untagged.xlsx', null);
});

it('hides other buyers files from a merchant confined to one buyer', function () {
    // The reported case: a merchant assigned only to MACYS was seeing
    // ANNTAYLOR and Hugo Boss files in this list.
    $response = $this->actingAs(mwScopeUser([$this->macys->id], BuyerScope::MODE_SPECIFIC))
        ->get(route('merchant.workspace', ['tab' => 'files']))
        ->assertOk();

    expect(mwVisibleFileNames($response))->toEqual(['macys.xlsx', 'untagged.xlsx']);

    // FULL HIDE, not "rendered but locked". Asserted against the response BODY
    // as well as the view data, because a row excluded from the query but still
    // printed by some other partial would pass the check above.
    //
    // The row's OPEN LINK is the identifier, not the file name: the table
    // prints the name only inside a lock modal that merchants never render, so
    // a name-based assertion would pass whether the row was there or not. The
    // trailing quote anchors the match so /uploaded-files/3 cannot satisfy a
    // search for /uploaded-files/30.
    $linkTo = fn (string $name) => route(
        'uploaded-files.show',
        ExcelFile::where('file_name', $name)->value('id')
    ) . '"';

    $response->assertSee($linkTo('macys.xlsx'), false);
    $response->assertSee($linkTo('untagged.xlsx'), false);
    $response->assertDontSee($linkTo('anntaylor.xlsx'), false);
    $response->assertDontSee($linkTo('hugoboss.xlsx'), false);
});

it('keeps the untagged archive visible to a confined merchant', function () {
    // The clause most likely to be "tidied away" later. Every live BOM file
    // carries a null buyer_id, so dropping it empties the screen for everyone.
    $response = $this->actingAs(mwScopeUser([$this->macys->id], BuyerScope::MODE_SPECIFIC))
        ->get(route('merchant.workspace', ['tab' => 'files']))
        ->assertOk();

    expect(mwVisibleFileNames($response))->toContain('untagged.xlsx');
});

it('shows every buyer a merchant holds, not only the primary one', function () {
    $response = $this->actingAs(
        mwScopeUser([$this->macys->id, $this->hugoBoss->id], BuyerScope::MODE_SPECIFIC)
    )->get(route('merchant.workspace', ['tab' => 'files']))->assertOk();

    expect(mwVisibleFileNames($response))
        ->toEqual(['hugoboss.xlsx', 'macys.xlsx', 'untagged.xlsx']);

    // The one buyer they do not hold is the one that is gone.
    expect(mwVisibleFileNames($response))->not->toContain('anntaylor.xlsx');
});

it('never narrows a merchant with no buyer assigned at all', function () {
    // The unconfined case, and the one that matters for a live deployment:
    // nobody assigned buyers yet, so nobody loses a file.
    $response = $this->actingAs(mwScopeUser())
        ->get(route('merchant.workspace', ['tab' => 'files']))
        ->assertOk();

    expect(mwVisibleFileNames($response))->toHaveCount(4);
});

it('still confines an all-mode merchant who actually holds buyers', function () {
    // 'all' + assignments is a contradiction the user form cannot produce — it
    // writes 'specific' whenever it assigns — and isBuyerConfined() resolves it
    // the safe way round: holding buyers confines you whatever the mode says.
    // Pinned here because the alternative reading would hand a user with a
    // stale mode every buyer in the system.
    $response = $this->actingAs(mwScopeUser([$this->macys->id], BuyerScope::MODE_ALL))
        ->get(route('merchant.workspace', ['tab' => 'files']))
        ->assertOk();

    expect(mwVisibleFileNames($response))->toEqual(['macys.xlsx', 'untagged.xlsx']);
});

/*
 * No super-admin case here: merchant.workspace is behind the merchant role
 * middleware and answers 403 to an admin, who reads admin.workspace instead.
 * The "unconfined viewer is never narrowed" rule lives in the trait and is
 * asserted against it directly, below, rather than through a route the viewer
 * cannot reach.
 */
it('leaves the query untouched for an unconfined viewer', function () {
    $unconfined = mwScopeUser();

    $scoped = new class($unconfined)
    {
        use App\Support\Concerns\ScopesToAssignedBuyers;

        public function __construct(private User $viewer) {}

        protected function buyerScopeViewer(): ?User
        {
            return $this->viewer;
        }

        public function count(): int
        {
            $query = ExcelFile::query();
            $this->applyBuyerScopeToFiles($query);

            return $query->count();
        }
    };

    expect($scoped->count())->toBe(4);
});

it('leaves the upload buyer dropdown alone', function () {
    // Scoping the LIST must not disturb the upload panel, which is built from
    // the buyer table and has its own three-state rule.
    $this->actingAs(mwScopeUser([$this->macys->id], BuyerScope::MODE_SPECIFIC))
        ->get(route('merchant.workspace', ['tab' => 'files']))
        ->assertOk()
        ->assertViewHas('scopedBuyer', fn ($buyer) => $buyer?->buyer_name === 'MACYS');
});

it('keeps Delete behind the More menu in the Existing Files list', function () {
    $html = $this->actingAs(mwScopeUser([$this->macys->id], BuyerScope::MODE_SPECIFIC))
        ->get(route('merchant.workspace', ['tab' => 'files']))
        ->assertOk()->getContent();

    expect($html)->toContain('</i>More')->toContain('Delete file')->not->toMatch('/btn btn-sm btn-danger">\s*<i class="bi bi-trash me-1"[^>]*><\/i>Delete\s/');
});

it('offers the Hide order details toggle on the file sheet', function () {
    $user = mwScopeUser([$this->macys->id], BuyerScope::MODE_SPECIFIC);
    $file = \App\Models\ExcelFile::where('buyer_id', $this->macys->id)->firstOrFail();

    $this->actingAs($user)->get(route('uploaded-files.show', $file->id))
        ->assertOk()->assertSee('id="toggleOrderDetails"', false)->assertSee('Hide order details');
});
