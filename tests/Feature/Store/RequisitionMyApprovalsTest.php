<?php

use App\Models\IndentSection;
use App\Models\PurchaseRequisition;
use App\Models\StockItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * My Approvals (step 3e): the sheets waiting for the signed-in user, the ones
 * returned to them, and Admin / Management's read-only All Pending tab. The
 * sidebar badge counts the very list the page shows.
 *
 * In-memory sqlite. No real record touched.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-07 10:00:00');
    $this->approvers = requisitionApprovers();
});

afterEach(fn () => Carbon::setTestNow());

function rmaUser(string $role, array $attributes = [], array $permissions = ['store.requisition.view']): User
{
    $user = User::factory()->create($attributes)->assignRole(Role::findOrCreate($role, 'web'));

    foreach ($permissions as $p) {
        $user->givePermissionTo(Permission::findOrCreate($p, 'web'));
    }

    return $user;
}

/** @return array{0: User, 1: User} a section head and a raiser in a new section */
function rmaSection(string $name): array
{
    $head = rmaUser('production', ['name' => $name.' Head']);
    $section = IndentSection::create(['name' => $name, 'department_key' => 'production', 'head_user_id' => $head->id, 'is_active' => true]);
    $raiser = rmaUser('production', ['name' => $name.' Raiser', 'requisition_section_id' => $section->id], ['store.requisition.view', 'store.requisition.create']);

    return [$head, $raiser];
}

function rmaSubmit(User $raiser): PurchaseRequisition
{
    $stock = StockItem::firstOrCreate(['name' => 'Machine Oil'], ['uom' => 'Pcs', 'is_active' => true]);

    test()->actingAs($raiser)->post(route('store.stock.requisitions.store'), [
        'action' => 'submit', 'mode' => PurchaseRequisition::MODE_MULTI, 'requisition_date' => '2026-10-05',
        'items' => [['stock_item_id' => $stock->id, 'qty_requested' => 5]],
    ])->assertSessionHasNoErrors();

    return PurchaseRequisition::latest('id')->firstOrFail();
}

it('lists the waiting sheets for the head, and the sidebar badge equals the list', function () {
    [$head, $raiser] = rmaSection('Cutting');
    [, $otherRaiser] = rmaSection('Sewing');
    $first = rmaSubmit($raiser);

    $response = $this->actingAs($head)->get(route('store.stock.requisitions.my-approvals'))->assertOk();
    $response->assertSee($first->requisition_no)->assertSee('Review')->assertSee('Waiting for you');

    // The badge in the sidebar and the tab: one waiting.
    expect($response->getContent())->toMatch('/My Approvals\s*<span class="badge[^>]*>1<\/span>/');

    // A second sheet in the same section, then the list and the badge both say 2.
    $second = rmaSubmit($raiser);
    $response = $this->actingAs($head)->get(route('store.stock.requisitions.my-approvals'))->assertOk();
    $response->assertSee($first->requisition_no)->assertSee($second->requisition_no);
    expect($response->getContent())->toMatch('/My Approvals\s*<span class="badge[^>]*>2<\/span>/');

    // Another section's head has none of them.
    $this->actingAs($this->approvers['hr'])->get(route('store.stock.requisitions.my-approvals'))
        ->assertOk()->assertDontSee($first->requisition_no)->assertSee('Nothing is waiting for you.');
});

it('moves a sheet to the Store team list when the head approves it', function () {
    [$head, $raiser] = rmaSection('Cutting');
    $sheet = rmaSubmit($raiser);
    $clerk = rmaUser('store');

    $this->actingAs($clerk)->get(route('store.stock.requisitions.my-approvals'))->assertOk()->assertDontSee(">".$sheet->requisition_no."<", false);

    $this->actingAs($head)->post(route('store.stock.requisitions.stage-approve', $sheet))->assertSessionHasNoErrors();

    $this->actingAs($clerk)->get(route('store.stock.requisitions.my-approvals'))->assertOk()->assertSee($sheet->requisition_no);
    $this->actingAs($head)->get(route('store.stock.requisitions.my-approvals'))->assertOk()->assertDontSee(">".$sheet->requisition_no."<", false);
});

it('shows a returned sheet to its raiser in a Returned to you box, with the reason', function () {
    [$head, $raiser] = rmaSection('Cutting');
    $sheet = rmaSubmit($raiser);

    $this->actingAs($raiser)->get(route('store.stock.requisitions.my-approvals'))->assertOk()->assertDontSee('Returned to you');

    $this->actingAs($head)->post(route('store.stock.requisitions.stage-return', $sheet), ['comment' => 'Quantity looks wrong'])->assertSessionHasNoErrors();

    $this->actingAs($raiser)->get(route('store.stock.requisitions.my-approvals'))
        ->assertOk()
        ->assertSee('Returned to you')
        ->assertSee($sheet->requisition_no)
        ->assertSee('Cutting Head')
        ->assertSee('Quantity looks wrong');
});

it('shows days waiting and highlights more than three days', function () {
    [$head, $raiser] = rmaSection('Cutting');
    rmaSubmit($raiser);

    $this->actingAs($head)->get(route('store.stock.requisitions.my-approvals'))->assertOk()
        ->assertSee('Today')->assertDontSee('bg-danger-subtle text-danger">0');

    Carbon::setTestNow('2026-10-09 10:00:00');
    $this->actingAs($head)->get(route('store.stock.requisitions.my-approvals'))->assertOk()
        ->assertSee('2 days')->assertDontSee('badge bg-danger-subtle text-danger">2 days');

    Carbon::setTestNow('2026-10-12 10:00:00');
    $this->actingAs($head)->get(route('store.stock.requisitions.my-approvals'))->assertOk()
        ->assertSee('badge bg-danger-subtle text-danger">5 days', false);
});

it('gives Admin and Management a read-only All Pending tab and nobody else', function () {
    [$head, $raiser] = rmaSection('Cutting');
    $sheet = rmaSubmit($raiser);
    $admin = rmaUser('admin');
    $management = rmaUser('management');

    foreach ([$admin, $management] as $viewer) {
        // Nothing for them to approve...
        $this->actingAs($viewer)->get(route('store.stock.requisitions.my-approvals'))
            ->assertOk()->assertSee('All Pending')->assertSee('Nothing is waiting for you.');

        // ...but everything pending is listed, with where it waits.
        $all = $this->actingAs($viewer)->get(route('store.stock.requisitions.my-approvals', ['tab' => 'all']))
            ->assertOk()
            ->assertSee($sheet->requisition_no)
            ->assertSee('Waiting at')
            ->assertSee('Cutting Head')
            ->assertSee('Today');

        // Read only: a View link, never Review / Approve.
        $all->assertSee('View')->assertDontSee('Review');
    }

    // The head has no All Pending tab; asking for it still shows only their own list.
    $this->actingAs($head)->get(route('store.stock.requisitions.my-approvals', ['tab' => 'all']))
        ->assertOk()->assertDontSee('All Pending')->assertSee('Waiting for you');
});

it('lists a sheet in All Pending at its Store team stage, and not once it is cancelled', function () {
    [$head, $raiser] = rmaSection('Cutting');
    $sheet = rmaSubmit($raiser);
    $this->actingAs($head)->post(route('store.stock.requisitions.stage-approve', $sheet))->assertSessionHasNoErrors();

    $admin = rmaUser('admin');
    $this->actingAs($admin)->get(route('store.stock.requisitions.my-approvals', ['tab' => 'all']))
        ->assertOk()->assertSee($sheet->requisition_no)->assertSee('Store team');

    $this->actingAs($admin)->post(route('store.stock.requisitions.cancel', $sheet), ['comment' => 'Not needed'])->assertRedirect();

    $this->actingAs($admin)->get(route('store.stock.requisitions.my-approvals', ['tab' => 'all']))
        ->assertOk()->assertDontSee(">".$sheet->requisition_no."<", false);
});

it('offers My Approvals in the sidebar to a department user and highlights only that entry', function () {
    [$head] = rmaSection('Cutting');

    $html = $this->actingAs($head)->get(route('store.stock.requisitions.index'))->assertOk()->getContent();
    expect($html)->toContain(route('store.stock.requisitions.my-approvals'));

    $html = $this->actingAs($head)->get(route('store.stock.requisitions.my-approvals'))->assertOk()->getContent();
    expect(substr_count($html, 'sidebar-nav-link is-active'))->toBe(1);
});

it('puts My Approvals directly under Dashboard as a top-level item, for every role that sees it', function () {
    $roles = [
        'admin' => [],
        'management' => [],
        'store' => [],
        'merchant' => ['store.requisition.view'],
        'account' => ['store.requisition.view'],
        'commercial' => ['store.requisition.view'],
        'supply_chain' => ['store.requisition.view'],
    ];

    foreach ($roles as $role => $permissions) {
        $user = rmaUser($role, [], $permissions);
        $html = $this->actingAs($user)->get(route('store.stock.requisitions.my-approvals'))->assertOk()->getContent();

        // Dashboard, then immediately the next top-level item is My Approvals.
        expect($html)->toMatch('/Dashboard<\/span>(?:\s*<\/span>)?\s*<\/a>\s*<\/li>\s*<li class="sidebar-item">\s*<a href="[^"]*requisitions\/my-approvals"/', $role);

        // Never as a link inside a group.
        expect($html)->not->toMatch('/sidebar-sub-link[^>]*my-approvals/', $role);
    }
});

it('does not offer My Approvals to a user without the requisition permission', function () {
    $user = rmaUser('merchant', [], []);

    $html = $this->actingAs($user)->get(route('merchant.dashboard'))->assertOk()->getContent();

    expect($html)->not->toContain('requisitions/my-approvals');
});

it('leads a requisition-only raiser\'s menu with My Approvals, with no Main Menu heading', function () {
    $raiser = rmaUser('requisition_raiser', [], ['store.requisition.view', 'store.requisition.create']);

    $html = $this->actingAs($raiser)->get(route('store.stock.requisitions.index'))->assertOk()->getContent();
    $sidebar = substr($html, strpos($html, 'id="appSidebar"'));
    $sidebar = substr($sidebar, 0, strpos($sidebar, '</nav>'));

    expect($sidebar)->toContain('My Approvals')
        ->and($sidebar)->not->toContain('Main Menu')
        ->and(strpos($sidebar, 'My Approvals'))->toBeLessThan(strpos($sidebar, 'Purchase Requisition'));
});

it('counts waiting days as calendar days on the Dhaka clock, across Dhaka midnight', function () {
    // Submitted 23:00 Dhaka on 30 Sep (17:00 UTC)...
    Carbon::setTestNow('2026-09-30 17:00:00');
    [$head, $raiser] = rmaSection('Cutting');
    rmaSubmit($raiser);

    $this->actingAs($head)->get(route('store.stock.requisitions.my-approvals'))->assertOk()->assertSee('Today');

    // ...read two hours later, 01:00 Dhaka on 1 Oct: a different Dhaka day, so 1 day.
    Carbon::setTestNow('2026-09-30 19:00:00');
    $this->actingAs($head)->get(route('store.stock.requisitions.my-approvals'))->assertOk()
        ->assertSee('1 day')->assertDontSee('Today');

    // Same Dhaka day, 23 hours apart in the other direction: still Today.
    Carbon::setTestNow('2026-09-30 17:30:00');
    $this->actingAs($head)->get(route('store.stock.requisitions.my-approvals'))->assertOk()->assertSee('Today');
});
