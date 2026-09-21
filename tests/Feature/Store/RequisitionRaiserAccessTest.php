<?php

use App\Models\PurchaseRequisition;
use App\Models\StockItem;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The requisition_raiser role, and granting the requisition screen to an
 * existing user, through the Admin > Users screen that already exists.
 *
 *   - A raiser can open the requisition list and raise one, and NOTHING else:
 *     no Store dashboard, no other General Stock screen, no Accounts, no Admin.
 *   - Their sidebar offers only Purchase Requisition.
 *   - They land on the requisition list after sign-in.
 *   - Ticking the requisition permissions on an existing Merchandising user
 *     adds the screen and removes nothing they had.
 *
 * In-memory sqlite. No real record touched.
 */

/** The role exactly as migration 2026_09_21_000004 seeds it. */
function raiserRole(): Role
{
    $role = Role::findOrCreate('requisition_raiser', 'web');

    foreach (['store.requisition.view', 'store.requisition.create'] as $name) {
        $role->givePermissionTo(Permission::findOrCreate($name, 'web'));
    }

    return $role;
}

function raiser(): User
{
    raiserRole();

    return User::factory()->create()->assignRole('requisition_raiser');
}

function superAdmin(): User
{
    return User::factory()->create()->assignRole(Role::findOrCreate('admin', 'web'));
}

beforeEach(function () {
    // Every permission the refused screens are guarded by, so role_or_perm
    // has something to look up rather than throwing "permission not found".
    foreach ([
        'store.stock_report.view', 'store.items.view', 'store.receiving.view', 'store.issues.view',
        'store.setup.view', 'store.requisition.review', 'store.requisition.approve',
        'material.closing_stock.view', 'material.receiving.view', 'material.bulk_issue.view', 'material.requisitions.view',
    ] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    foreach (['store', 'management', 'merchant', 'account', 'commercial'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

// --- What a raiser CAN do ------------------------------------------------------

it('lets a requisition raiser open the list, the create screen, and raise a requisition', function () {
    $user = raiser();
    $item = StockItem::create(['name' => 'Machine Oil', 'uom' => 'Ltr', 'is_active' => true]);

    $this->actingAs($user)->get(route('store.stock.requisitions.index'))->assertOk();
    $this->actingAs($user)->get(route('store.stock.requisitions.create'))->assertOk();

    $this->actingAs($user)
        ->post(route('store.stock.requisitions.store'), [
            'action' => 'submit',
            'mode' => PurchaseRequisition::MODE_MULTI,
            'requisition_date' => '2026-09-21',
            'items' => [['stock_item_id' => $item->id, 'qty_requested' => 3]],
        ])
        ->assertRedirect();

    $requisition = PurchaseRequisition::query()->latest('id')->first();

    expect($requisition->created_by)->toBe($user->id)
        // No department role, so no department head: straight to the Store.
        ->and($requisition->level)->toBe(PurchaseRequisition::LEVEL_DEPARTMENT)
        ->and($requisition->department_role)->toBeNull()
        ->and($requisition->awaitingStoreReceipt())->toBeTrue();

    $this->actingAs($user)->get(route('store.stock.requisitions.show', $requisition))->assertOk();
});

it('lands a requisition raiser on the requisition list after sign-in', function () {
    $this->actingAs(raiser())
        ->get('/dashboard')
        ->assertRedirect(route('store.stock.requisitions.index'));
});

// --- What a raiser CANNOT do ----------------------------------------------------

it('refuses a requisition raiser every Store screen other than the requisition', function () {
    $user = raiser();

    foreach ([
        route('store.dashboard'),
        route('store.stock.ledger'),
        route('store.stock.items.index'),
        route('store.stock.purchases.index'),
        route('store.stock.setup'),
        route('store.stock.requisitions.requested'),
        route('store.stock.requisitions.report'),
    ] as $url) {
        $this->actingAs($user)->get($url)->assertForbidden();
    }
});

it('refuses a requisition raiser the Accounts, Commercial, Merchandising and Admin areas', function () {
    $user = raiser();

    foreach ([
        route('account.dashboard'),
        route('commercial.dashboard'),
        route('merchant.dashboard'),
        route('admin.dashboard'),
        route('admin.users.index'),
        route('admin.requisition-signatures.edit'),
    ] as $url) {
        $this->actingAs($user)->get($url)->assertForbidden();
    }
});

it('does not let a raiser move a requisition along, even their own', function () {
    $user = raiser();

    $requisition = PurchaseRequisition::create([
        'requisition_no' => 'RAISER-'.uniqid(),
        'requisition_date' => '2026-09-21',
        'mode' => PurchaseRequisition::MODE_MULTI,
        'level' => PurchaseRequisition::LEVEL_DEPARTMENT,
        'status' => PurchaseRequisition::STATUS_SUBMITTED,
        'submitted_at' => now(),
        'created_by' => $user->id,
    ]);

    $this->actingAs($user)->post(route('store.stock.requisitions.received', $requisition))->assertForbidden();
    $this->actingAs($user)->post(route('store.stock.requisitions.approve', $requisition))->assertForbidden();
});

it('shows a raiser only the Purchase Requisition entry in the sidebar', function () {
    $html = $this->actingAs(raiser())
        ->get(route('store.stock.requisitions.index'))
        ->assertOk()
        ->getContent();

    $sidebar = substr($html, strpos($html, 'id="appSidebar"'));
    $sidebar = substr($sidebar, 0, strpos($sidebar, '</nav>'));

    expect($sidebar)->toContain('Purchase Requisition')
        ->and($sidebar)->not->toContain('Main Menu')
        ->and($sidebar)->not->toContain('Stock Report')
        ->and($sidebar)->not->toContain('Workspace')
        ->and($sidebar)->not->toContain('Users &amp; Roles')
        ->and($sidebar)->not->toContain(route('store.dashboard'));
});

// --- Through Admin > Users ------------------------------------------------------

it('lets the admin create a requisition-only user from the existing Users screen', function () {
    raiserRole();

    $this->actingAs(superAdmin())
        ->post(route('admin.users.store'), [
            'name' => 'Line Clerk',
            'email' => 'clerk@example.test',
            'password' => 'secret123',
            'status' => '1',
            'role' => 'requisition_raiser',
        ])
        ->assertRedirect(route('admin.users.index'));

    $clerk = User::where('email', 'clerk@example.test')->firstOrFail();

    expect($clerk->hasRole('requisition_raiser'))->toBeTrue()
        ->and($clerk->getAllPermissions()->pluck('name')->sort()->values()->all())
        ->toBe(['store.requisition.create', 'store.requisition.view']);

    $this->actingAs($clerk)->get(route('store.stock.requisitions.create'))->assertOk();
    $this->actingAs($clerk)->get(route('store.dashboard'))->assertForbidden();
});

it('adds the requisition screen to an existing Merchandising user without removing anything', function () {
    Role::findByName('merchant')->givePermissionTo(Permission::findOrCreate('merchant.orders.view', 'web'));
    $merchant = User::factory()->create()->assignRole('merchant');

    $before = $merchant->getAllPermissions()->pluck('name')->sort()->values()->all();
    expect($before)->toContain('merchant.orders.view');

    $this->actingAs($merchant)->get(route('store.stock.requisitions.index'))->assertForbidden();

    $this->actingAs(superAdmin())
        ->put(route('admin.users.update', $merchant), [
            'name' => $merchant->name,
            'email' => $merchant->email,
            'status' => '1',
            'department' => 'merchandising',
            'role' => 'merchant',
            'permissions' => ['store.requisition.view', 'store.requisition.create'],
        ])
        ->assertRedirect();

    $merchant->refresh();
    $after = $merchant->getAllPermissions()->pluck('name')->sort()->values()->all();

    expect($merchant->hasRole('merchant'))->toBeTrue()
        ->and(array_diff($before, $after))->toBe([])
        ->and($after)->toContain('store.requisition.view')
        ->and($after)->toContain('store.requisition.create');

    $this->actingAs($merchant)->get(route('store.stock.requisitions.index'))->assertOk();
    $this->actingAs($merchant)->get(route('merchant.dashboard'))->assertOk();
    // Still lands on their own department's dashboard, not the requisition list.
    $this->actingAs($merchant)->get('/dashboard')->assertRedirect(route('merchant.dashboard'));
});
