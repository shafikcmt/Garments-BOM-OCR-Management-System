<?php

use App\Models\StockIssue;
use App\Models\StockItem;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Issue History's live search swaps a server-rendered fragment in place of the
 * list. The contract that makes that safe: ?partial=1 returns the list only —
 * no layout, no filter form, and none of the page above it — while the same
 * action without the flag keeps returning the whole page. If the partial ever
 * starts carrying the page chrome, every keystroke nests another copy of the
 * screen inside the last.
 *
 * The per-issue Edit modals are part of the fragment on purpose: they are keyed
 * by issue id, so a row swapped in without its modal would have an Edit button
 * opening a dialog built for a row no longer on screen.
 *
 * The Record Issue form and the skipped-rows notice stay OUTSIDE the fragment
 * and are asserted absent from it here, because that is exactly what keeps the
 * line-builder script and the dismiss/fade behaviour working across a swap.
 */
function issuesPageUser(): User
{
    Role::findOrCreate('store', 'web')->givePermissionTo(
        Permission::findOrCreate('store.workspace.view', 'web')
    );

    return User::factory()->create()->assignRole('store');
}

function issuesPartialItem(string $name): StockItem
{
    return StockItem::create([
        'name' => $name,
        'uom' => 'PCS',
        'category' => 'Needle',
        'is_active' => true,
        'opening_qty' => 100,
    ]);
}

function issuesPartialIssue(StockItem $item, array $extra = []): StockIssue
{
    return StockIssue::create(array_merge([
        'stock_item_id' => $item->id,
        'issue_date' => '2026-08-05',
        'qty' => 5,
        'requisition_no' => 'REQ-001',
    ], $extra));
}

it('returns only the list fragment when asked for a partial', function () {
    issuesPartialIssue(issuesPartialItem('Cotton Thread White'));

    $response = $this->actingAs(issuesPageUser())
        ->get(route('store.stock.issues.index', ['partial' => 1]))
        ->assertOk();

    $response->assertSee('data-list-meta', false);

    // None of the page around it.
    $response->assertDontSee('data-list-filters', false);
    $response->assertDontSee('<body', false);

    // The two things that must keep working across a swap precisely because
    // they are not in the swapped region — see the partial's own docblock.
    $response->assertDontSee('id="issueForm"', false);
    $response->assertDontSee('issueLineTemplate', false);
    $response->assertDontSee('skippedRowsNotice', false);
});

it('still returns the full page without the partial flag', function () {
    issuesPartialIssue(issuesPartialItem('Cotton Thread White'));

    $this->actingAs(issuesPageUser())
        ->get(route('store.stock.issues.index'))
        ->assertOk()
        ->assertSee('data-list-filters', false)
        ->assertSee('data-list-meta', false)
        // The fragment renders inline on a full page load, so the create form
        // and the list are both present exactly once.
        ->assertSee('id="issueForm"', false);
});

it('applies the search filter to the partial', function () {
    issuesPartialIssue(issuesPartialItem('Cotton Thread White'));
    issuesPartialIssue(issuesPartialItem('Carton Box Large'), ['requisition_no' => 'REQ-002']);

    // A mid-word substring, which is the case live search exists for.
    $this->actingAs(issuesPageUser())
        ->get(route('store.stock.issues.index', ['partial' => 1, 'search' => 'otton']))
        ->assertOk()
        ->assertSee('Cotton Thread White')
        ->assertDontSee('Carton Box Large');
});

it('applies the month filter to the partial', function () {
    $item = issuesPartialItem('Cotton Thread White');
    issuesPartialIssue($item, ['issue_date' => '2026-08-05', 'requisition_no' => 'REQ-AUG']);
    issuesPartialIssue($item, ['issue_date' => '2026-07-05', 'requisition_no' => 'REQ-JUL']);

    $this->actingAs(issuesPageUser())
        ->get(route('store.stock.issues.index', ['partial' => 1, 'month' => '2026-08']))
        ->assertOk()
        ->assertSee('REQ-AUG')
        ->assertDontSee('REQ-JUL');
});

/**
 * Regression: the fragment renders the Edit modals, whose dropdowns need
 * $sections, $persons, $approvers, $categories and $requisitionTypes. Leaving
 * any of them out of the partial's payload throws a 500 that only shows up as
 * "could not update the list" in the browser.
 */
it('renders the edit modals in the fragment for a user who may edit', function () {
    $issue = issuesPartialIssue(issuesPartialItem('Cotton Thread White'));

    $user = issuesPageUser();
    $user->givePermissionTo(Permission::findOrCreate('store.edit', 'web'));

    $this->actingAs($user)
        ->get(route('store.stock.issues.index', ['partial' => 1]))
        ->assertOk()
        ->assertSee('editIssue'.$issue->id, false);
});

it('leaves the edit modals out of the fragment for a user who may not edit', function () {
    $issue = issuesPartialIssue(issuesPartialItem('Cotton Thread White'));

    $this->actingAs(issuesPageUser())
        ->get(route('store.stock.issues.index', ['partial' => 1]))
        ->assertOk()
        ->assertDontSee('editIssue'.$issue->id, false);
});

it('keeps the transport flag out of the pagination links', function () {
    $item = issuesPartialItem('Cotton Thread White');

    // Past the 25-a-page limit, so there is a paginator to inspect.
    foreach (range(1, 30) as $n) {
        issuesPartialIssue($item, ['requisition_no' => 'REQ-'.$n]);
    }

    // A "next page" href has to stay a real URL someone can open or share.
    $this->actingAs(issuesPageUser())
        ->get(route('store.stock.issues.index', ['partial' => 1, 'search' => 'otton']))
        ->assertOk()
        ->assertSee('page=2', false)
        ->assertDontSee('partial=1', false);
});
