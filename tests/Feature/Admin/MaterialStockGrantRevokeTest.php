<?php

use App\Models\MaterialReceiving;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Store — Buyer / Style Stock: edit/delete stop being default, and become
 * grantable and revocable per user.
 *
 * Proved at the route. store.material.receivings.destroy runs
 * authorizeStoreDelete() before it touches anything, so its status is a direct
 * read of whether the permission is in force.
 *
 * In-memory sqlite. No real record touched.
 */
function runMaterialStrip(string $direction = 'up'): void
{
    $migration = require database_path('migrations/2026_08_11_000004_drop_edit_delete_from_material_stock_role.php');

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $migration->{$direction}();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

function materialRole(): Role
{
    foreach (['material.closing_stock.view', 'material.closing_stock.export',
        'material.receiving.view', 'material.receiving.create', 'material.receiving.export',
        'material.receiving.edit', 'material.receiving.delete',
        'material.bulk_issue.view', 'material.bulk_issue.create', 'material.bulk_issue.export',
        'material.bulk_issue.edit', 'material.bulk_issue.delete',
        'material.requisitions.view', 'material.requisitions.create', 'material.requisitions.export',
        'material.requisitions.edit', 'material.requisitions.delete'] as $p) {
        Permission::findOrCreate($p, 'web');
    }

    return tap(Role::findOrCreate('store_material_stock', 'web'))->syncPermissions([
        'material.closing_stock.view', 'material.closing_stock.export',
        'material.receiving.view', 'material.receiving.create', 'material.receiving.export',
        'material.receiving.edit', 'material.receiving.delete',
        'material.bulk_issue.view', 'material.bulk_issue.create', 'material.bulk_issue.export',
        'material.bulk_issue.edit', 'material.bulk_issue.delete',
        'material.requisitions.view', 'material.requisitions.create', 'material.requisitions.export',
        'material.requisitions.edit', 'material.requisitions.delete',
    ]);
}

/** Read-only fetch. materialRole() re-syncs the bundle and must not be used after a strip. */
function materialRoleNow(): Role
{
    return Role::findByName('store_material_stock', 'web');
}

function materialAdmin(): User
{
    Role::findOrCreate('admin', 'web');

    return User::factory()->create(['status' => 1])->assignRole('admin');
}

function aReceiving(): MaterialReceiving
{
    return MaterialReceiving::create([
        'po_no' => 'PO-TEST-1',
        'material_name' => 'Test Fabric',
        'received_qty' => 10,
    ]);
}

function materialPayload(User $user, array $permissions): array
{
    return [
        'name' => $user->name,
        'email' => $user->email,
        'status' => 1,
        'department' => 'store',
        'role' => 'store_material_stock',
        'permissions' => $permissions,
    ];
}

beforeEach(function () {
    materialRole();
    DB::table('app_settings')->where('key', 'material_stock_role_edit_delete_removed')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('strips the six edit/delete permissions and keeps view, create and export', function () {
    runMaterialStrip();

    expect(materialRoleNow()->permissions->pluck('name')->sort()->values()->all())->toBe([
        'material.bulk_issue.create', 'material.bulk_issue.export', 'material.bulk_issue.view',
        'material.closing_stock.export', 'material.closing_stock.view',
        'material.receiving.create', 'material.receiving.export', 'material.receiving.view',
        'material.requisitions.create', 'material.requisitions.export', 'material.requisitions.view',
    ]);
});

it('gives a NEW buyer/style stock user no edit or delete anywhere', function () {
    runMaterialStrip();

    $newcomer = User::factory()->create(['status' => 1])->assignRole('store_material_stock');

    foreach (['material.receiving.edit', 'material.receiving.delete',
        'material.bulk_issue.edit', 'material.bulk_issue.delete',
        'material.requisitions.edit', 'material.requisitions.delete'] as $p) {
        expect($newcomer->can($p))->toBeFalse("newcomer should not hold {$p}");
    }

    expect($newcomer->can('material.receiving.view'))->toBeTrue()
        ->and($newcomer->can('material.receiving.create'))->toBeTrue()
        ->and($newcomer->can('material.receiving.export'))->toBeTrue();
});

it('GRANT: ticking Receiving > Delete lets the user actually delete a receiving', function () {
    runMaterialStrip();

    $target = User::factory()->create(['status' => 1])->assignRole('store_material_stock');
    $receiving = aReceiving();

    $this->actingAs($target)
        ->delete(route('store.material.receivings.destroy', $receiving))
        ->assertForbidden();

    expect(MaterialReceiving::find($receiving->id))->not->toBeNull();

    $this->actingAs(materialAdmin())
        ->put(route('admin.users.update', $target),
            materialPayload($target, ['material.receiving.delete']))
        ->assertRedirect();

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($target->fresh())
        ->delete(route('store.material.receivings.destroy', $receiving))
        ->assertRedirect();

    expect(MaterialReceiving::find($receiving->id))->toBeNull();
});

it('REVOKE: unticking Receiving > Delete stops the user deleting a receiving', function () {
    runMaterialStrip();

    $target = User::factory()->create(['status' => 1])->assignRole('store_material_stock');
    $target->givePermissionTo(['material.receiving.delete', 'material.bulk_issue.edit']);
    $receiving = aReceiving();

    $this->actingAs($target->fresh())
        ->delete(route('store.material.receivings.destroy', $receiving))
        ->assertRedirect();

    expect(MaterialReceiving::find($receiving->id))->toBeNull();

    // Admin unticks Receiving > Delete, leaving Bulk Issue > Edit ticked.
    $this->actingAs(materialAdmin())
        ->put(route('admin.users.update', $target),
            materialPayload($target, ['material.bulk_issue.edit']))
        ->assertRedirect();

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $another = aReceiving();

    $this->actingAs($target->fresh())
        ->delete(route('store.material.receivings.destroy', $another))
        ->assertForbidden();

    // Untouched by the refused attempt.
    expect(MaterialReceiving::find($another->id))->not->toBeNull();
});

it('revoking one permission leaves the others alone', function () {
    runMaterialStrip();

    $target = User::factory()->create(['status' => 1])->assignRole('store_material_stock');
    $target->givePermissionTo([
        'material.receiving.delete', 'material.receiving.edit',
        'material.bulk_issue.edit', 'material.requisitions.delete',
    ]);

    $this->actingAs(materialAdmin())
        ->put(route('admin.users.update', $target), materialPayload($target, [
            'material.receiving.edit', 'material.bulk_issue.edit', 'material.requisitions.delete',
        ]))
        ->assertRedirect();

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $fresh = $target->fresh();

    expect($fresh->can('material.receiving.delete'))->toBeFalse()
        ->and($fresh->can('material.receiving.edit'))->toBeTrue()
        ->and($fresh->can('material.bulk_issue.edit'))->toBeTrue()
        ->and($fresh->can('material.requisitions.delete'))->toBeTrue()
        // Role-derived access is untouched by a direct-grant edit.
        ->and($fresh->can('material.receiving.view'))->toBeTrue()
        ->and($fresh->can('material.closing_stock.view'))->toBeTrue();
});

it('refuses to run when a holder has not been backfilled', function () {
    $exposed = User::factory()->create(['status' => 1])->assignRole('store_material_stock');

    expect($exposed->can('material.receiving.edit'))->toBeTrue();

    expect(fn () => runMaterialStrip())->toThrow(RuntimeException::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($exposed->fresh()->can('material.receiving.edit'))->toBeTrue()
        ->and(materialRoleNow()->permissions->pluck('name'))->toContain('material.receiving.edit');
});

it('reverses exactly and touches no other role', function () {
    $bundleBefore = materialRole()->permissions->pluck('name')->sort()->values()->all();

    $others = Role::where('name', '!=', 'store_material_stock')->get()
        ->mapWithKeys(fn (Role $r) => [$r->name => $r->permissions->pluck('name')->sort()->values()->all()]);

    runMaterialStrip('up');

    foreach ($others as $name => $perms) {
        expect(Role::findByName($name, 'web')->permissions->pluck('name')->sort()->values()->all())->toBe($perms);
    }

    runMaterialStrip('down');

    expect(materialRoleNow()->permissions->pluck('name')->sort()->values()->all())->toBe($bundleBefore)
        ->and(DB::table('app_settings')->where('key', 'material_stock_role_edit_delete_removed')->exists())->toBeFalse();
});
