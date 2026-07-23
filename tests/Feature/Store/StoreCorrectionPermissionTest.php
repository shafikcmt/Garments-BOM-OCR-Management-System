<?php

use App\Models\MaterialReceiving;
use App\Models\MaterialRequisition;
use App\Models\StockIssue;
use App\Models\StockItem;
use App\Models\StockPurchase;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Who may correct a recorded store record.
 *
 * Business rule: a Store user records movements but may not edit or delete them
 * afterwards, because every change recomputes closing stock that other
 * departments and management read. Corrections carry the store.edit /
 * store.delete permissions, held by Admin and Management.
 *
 * These tests hit the ROUTES directly rather than the buttons: hiding a control
 * in a view is presentation, and the guarantee that actually matters is that a
 * hand-crafted request from an unauthorised role is refused.
 */
function correctionUser(string $role, array $permissions = []): User
{
    $roleModel = Role::findOrCreate($role, 'web');

    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
        if (! $roleModel->hasPermissionTo($name)) {
            $roleModel->givePermissionTo($name);
        }
    }

    return User::factory()->create()->assignRole($roleModel);
}

function storeOnlyUser(): User
{
    return correctionUser('store');
}

function correctorUser(string $role = 'admin'): User
{
    return correctionUser($role, ['store.edit', 'store.delete']);
}

function makeReceiving(): MaterialReceiving
{
    return MaterialReceiving::create([
        'buyer_name' => 'Buyer A',
        'style_name' => 'STYLE-1',
        'material_description' => 'Woven label',
        'receive_date' => '2026-07-01',
        'qty' => 10,
    ]);
}

function makeStockItem(): StockItem
{
    return StockItem::create(['name' => 'Carton Box', 'uom' => 'PCS']);
}

// --- Store role is refused, server-side --------------------------------------
it('refuses a Store user deleting a receiving', function () {
    $receiving = makeReceiving();

    $this->actingAs(storeOnlyUser())
        ->delete(route('store.material.receivings.destroy', $receiving))
        ->assertForbidden();

    expect(MaterialReceiving::find($receiving->id))->not->toBeNull();
});

it('refuses a Store user deleting a general stock issue', function () {
    $item = makeStockItem();
    $issue = StockIssue::create([
        'stock_item_id' => $item->id, 'issue_date' => '2026-07-01', 'qty' => 2,
    ]);

    $this->actingAs(storeOnlyUser())
        ->delete(route('store.stock.issues.destroy', $issue))
        ->assertForbidden();

    expect(StockIssue::find($issue->id))->not->toBeNull();
});

it('refuses a Store user deleting a general stock purchase', function () {
    $item = makeStockItem();
    $purchase = StockPurchase::create([
        'stock_item_id' => $item->id, 'purchase_date' => '2026-07-01', 'qty' => 5,
    ]);

    $this->actingAs(storeOnlyUser())
        ->delete(route('store.stock.purchases.destroy', $purchase))
        ->assertForbidden();

    expect(StockPurchase::find($purchase->id))->not->toBeNull();
});

it('refuses a Store user editing or deleting a stock item', function () {
    $item = makeStockItem();
    $user = storeOnlyUser();

    $this->actingAs($user)
        ->put(route('store.stock.items.update', $item), ['name' => 'Renamed'])
        ->assertForbidden();

    $this->actingAs($user)
        ->delete(route('store.stock.items.destroy', $item))
        ->assertForbidden();

    expect($item->fresh()->name)->toBe('Carton Box');
});

it('refuses a Store user deleting a requisition', function () {
    $requisition = MaterialRequisition::create([
        'material_description' => 'Woven label',
        'qty' => 5,
        'status' => 'pending',
    ]);

    $this->actingAs(storeOnlyUser())
        ->delete(route('store.material.requisitions.destroy', $requisition))
        ->assertForbidden();

    expect(MaterialRequisition::find($requisition->id))->not->toBeNull();
});

it('refuses a Store user linking an independent receiving to a PO', function () {
    $receiving = makeReceiving();

    $this->actingAs(storeOnlyUser())
        ->post(route('store.material.receivings.link', $receiving), [
            'booking_po_id' => 1, 'excel_row_id' => 1,
        ])
        ->assertForbidden();
});

// --- Admin and Management keep working ---------------------------------------
it('lets an authorised role delete a receiving', function (string $role) {
    $receiving = makeReceiving();

    $this->actingAs(correctorUser($role))
        ->delete(route('store.material.receivings.destroy', $receiving))
        ->assertRedirect();

    expect(MaterialReceiving::find($receiving->id))->toBeNull();
})->with(['admin', 'management']);

it('lets an authorised role edit and delete a stock item', function (string $role) {
    $item = makeStockItem();
    $user = correctorUser($role);

    $this->actingAs($user)
        ->put(route('store.stock.items.update', $item), ['name' => 'Renamed', 'uom' => 'PCS'])
        ->assertRedirect();

    expect($item->fresh()->name)->toBe('Renamed');

    $this->actingAs($user)
        ->delete(route('store.stock.items.destroy', $item))
        ->assertRedirect();
})->with(['admin', 'management']);

// --- The buttons themselves ---------------------------------------------------
it('renders no Delete control for a Store user, and one for an authorised role', function () {
    makeReceiving();

    $this->actingAs(storeOnlyUser())
        ->get(route('store.material.receivings.index'))
        ->assertOk()
        ->assertDontSee('Remove this receiving?');

    $this->actingAs(correctorUser())
        ->get(route('store.material.receivings.index'))
        ->assertOk()
        ->assertSee('Remove this receiving?');
});

it('does not render the stock item edit form for a Store user', function () {
    makeStockItem();

    $this->actingAs(storeOnlyUser())
        ->get(route('store.stock.items.index'))
        ->assertOk()
        // The whole edit modal, not just its button, is absent — there is no
        // form markup left for a non-admin to replay.
        ->assertDontSee('Edit Item');
});

// --- The permissions themselves ----------------------------------------------
it('grants the correction permissions to admin and management via the seeder', function () {
    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('management', 'web');
    Role::findOrCreate('store', 'web');

    $this->seed(\Database\Seeders\StoreIssueControlPermissionSeeder::class);

    expect(Role::findByName('admin', 'web')->hasPermissionTo('store.delete'))->toBeTrue()
        ->and(Role::findByName('management', 'web')->hasPermissionTo('store.delete'))->toBeTrue()
        // Store deliberately does not receive them.
        ->and(Role::findByName('store', 'web')->hasPermissionTo('store.delete'))->toBeFalse();
});
