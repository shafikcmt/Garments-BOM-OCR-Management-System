<?php

use App\Models\StockItem;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Granting and revoking Edit/Delete per user, now that Store — General Stock
 * no longer bundles them.
 *
 * Proved at the route, not at the checkbox. A tick that updates a pivot table
 * but leaves the controller refusing the request would look like success on the
 * screen and fail in the user's hands, so every case here ends with a real PUT
 * to store.stock.items.update and an assertion on its status.
 *
 * In-memory sqlite. No real record touched.
 */

/** The role as it stands after 2026_08_11_000003 — no edit/delete/approval. */
function generalStockRole(): Role
{
    foreach (['store.items.view', 'store.items.create', 'store.items.export',
        'store.items.edit', 'store.items.delete',
        'store.issues.view', 'store.issues.edit',
        'store.requisition.approve'] as $p) {
        Permission::findOrCreate($p, 'web');
    }

    return tap(Role::findOrCreate('store_general_stock', 'web'))
        ->syncPermissions(['store.items.view', 'store.items.create', 'store.items.export', 'store.issues.view']);
}

function superAdminUser(): User
{
    Role::findOrCreate('admin', 'web');

    return User::factory()->create(['status' => 1])->assignRole('admin');
}

function anItem(): StockItem
{
    return StockItem::create([
        'name' => 'Test Thread',
        'uom' => 'pcs',
        'opening_qty' => 0,
    ]);
}

/** The payload the user edit form posts, with an explicit permission set. */
function editPayload(User $user, array $permissions): array
{
    return [
        'name' => $user->name,
        'email' => $user->email,
        'status' => 1,
        'department' => 'store',
        'role' => 'store_general_stock',
        'permissions' => $permissions,
    ];
}

beforeEach(function () {
    generalStockRole();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('shows edit and delete as unlocked checkboxes rather than role-locked ticks', function () {
    $target = User::factory()->create(['status' => 1])->assignRole('store_general_stock');

    $html = $this->actingAs(superAdminUser())
        ->get(route('admin.users.edit', $target))
        ->assertOk()
        ->getContent();

    // The matrix marks a role-derived permission with is-locked and a disabled
    // input. After the strip, store.items.edit must be neither.
    $editChip = null;

    foreach (explode('<label', $html) as $chunk) {
        if (str_contains($chunk, 'value="store.items.edit"')) {
            $editChip = $chunk;
            break;
        }
    }

    expect($editChip)->not->toBeNull('store.items.edit is missing from the matrix entirely');
    expect($editChip)->not->toContain('is-locked')
        ->and($editChip)->not->toContain('disabled')
        ->and($editChip)->not->toContain('(from role)');

    // ...while a permission the role DOES still carry stays locked.
    foreach (explode('<label', $html) as $chunk) {
        if (str_contains($chunk, 'value="store.items.view"')) {
            expect($chunk)->toContain('is-locked')
                ->and($chunk)->toContain('disabled');
            break;
        }
    }
});

it('GRANT: ticking Items > Edit lets the user actually update an item', function () {
    $target = User::factory()->create(['status' => 1])->assignRole('store_general_stock');
    $item = anItem();

    // Before: no direct grant, and the route refuses them.
    expect($target->can('store.items.edit'))->toBeFalse();

    $this->actingAs($target)
        ->put(route('store.stock.items.update', $item), ['name' => 'Blocked', 'uom' => 'pcs'])
        ->assertForbidden();

    // Admin ticks the box and saves.
    $this->actingAs(superAdminUser())
        ->put(route('admin.users.update', $target), editPayload($target, ['store.items.edit']))
        ->assertRedirect();

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($target->fresh()->getDirectPermissions()->pluck('name')->all())->toBe(['store.items.edit']);

    // After: the same request now succeeds and the item really changes.
    $this->actingAs($target->fresh())
        ->put(route('store.stock.items.update', $item), ['name' => 'Granted Thread', 'uom' => 'pcs'])
        ->assertRedirect();

    expect($item->fresh()->name)->toBe('Granted Thread');
});

it('REVOKE: unticking Items > Edit stops the user updating an item', function () {
    // Mirrors Md Shafiqul Islam: holds the permission as a direct grant.
    $target = User::factory()->create(['status' => 1])->assignRole('store_general_stock');
    $target->givePermissionTo(['store.items.edit', 'store.items.delete', 'store.issues.edit']);
    $item = anItem();

    $this->actingAs($target)
        ->put(route('store.stock.items.update', $item), ['name' => 'Allowed', 'uom' => 'pcs'])
        ->assertRedirect();

    expect($item->fresh()->name)->toBe('Allowed');

    // Admin unticks Items > Edit only, leaving the other two ticked.
    $this->actingAs(superAdminUser())
        ->put(route('admin.users.update', $target),
            editPayload($target, ['store.items.delete', 'store.issues.edit']))
        ->assertRedirect();

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Matrix state: gone.
    expect($target->fresh()->getDirectPermissions()->pluck('name')->sort()->values()->all())
        ->toBe(['store.issues.edit', 'store.items.delete']);

    // Route level: refused, and the item is untouched by the attempt.
    $this->actingAs($target->fresh())
        ->put(route('store.stock.items.update', $item), ['name' => 'Should Not Save', 'uom' => 'pcs'])
        ->assertForbidden();

    expect($item->fresh()->name)->toBe('Allowed');
});

it('revoking one permission leaves the others alone', function () {
    $target = User::factory()->create(['status' => 1])->assignRole('store_general_stock');
    $target->givePermissionTo([
        'store.items.edit', 'store.items.delete', 'store.issues.edit', 'store.requisition.approve',
    ]);

    $this->actingAs(superAdminUser())
        ->put(route('admin.users.update', $target),
            editPayload($target, ['store.items.delete', 'store.issues.edit', 'store.requisition.approve']))
        ->assertRedirect();

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $fresh = $target->fresh();

    expect($fresh->can('store.items.edit'))->toBeFalse()
        ->and($fresh->can('store.items.delete'))->toBeTrue()
        ->and($fresh->can('store.issues.edit'))->toBeTrue()
        ->and($fresh->can('store.requisition.approve'))->toBeTrue()
        // Role-derived access is untouched by a direct-grant edit.
        ->and($fresh->can('store.items.view'))->toBeTrue()
        ->and($fresh->can('store.items.create'))->toBeTrue();
});

it('a department admin unticking cannot strip what is outside their reach', function () {
    // The negative control: "unticked" and "never in my submitted set because I
    // cannot see it" must not be treated the same way.
    $deptAdmin = User::factory()->create(['status' => 1, 'is_department_admin' => true])
        ->assignRole(tap(Role::findOrCreate('store', 'web'))
            ->syncPermissions(['store.items.view', 'store.items.edit'])->name);

    $target = User::factory()->create(['status' => 1])->assignRole('store_general_stock');
    $target->givePermissionTo(['store.items.edit', 'store.requisition.approve']);

    // The Store Admin holds store.items.edit but NOT store.requisition.approve,
    // so their form posts only the former and the latter is drawn locked.
    $this->actingAs($deptAdmin)
        ->put(route('admin.users.update', $target), editPayload($target, []))
        ->assertRedirect();

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $fresh = $target->fresh();

    // The one they could reach was revoked...
    expect($fresh->can('store.items.edit'))->toBeFalse()
        // ...and the one they could not is still there.
        ->and($fresh->can('store.requisition.approve'))->toBeTrue();
});
