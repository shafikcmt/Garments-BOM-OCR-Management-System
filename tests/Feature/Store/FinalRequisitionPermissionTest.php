<?php

use App\Models\PurchaseRequisition;
use App\Models\User;
use App\Support\PermissionCatalog;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Two per-user permissions Admin can tick on Admin > Users, so a person on another role
 * (a General Stock clerk, say) can do the Store's requisition desk work:
 *
 *   store.requisition.final       prepare the Final Requisition (raise it, see Requested, merge)
 *   store.category_summary.view   open the Category Summary and its exports
 *
 * Roles that already did this (store, admin, management) are unchanged.
 */
function deskUser(array $permissions = []): User
{
    $role = Role::findOrCreate('general_stock_clerk', 'web');

    foreach (['store.requisition.view', 'store.requisition.create'] as $name) {
        $role->givePermissionTo(Permission::findOrCreate($name, 'web'));
    }

    $user = User::factory()->create()->assignRole($role);

    foreach ($permissions as $name) {
        $user->givePermissionTo(Permission::findOrCreate($name, 'web'));
    }

    return $user->fresh();
}

it('creates both permissions and offers them on the Admin permission screen', function () {
    expect(Permission::where('name', 'store.requisition.final')->exists())->toBeTrue()
        ->and(Permission::where('name', 'store.category_summary.view')->exists())->toBeTrue();

    $labels = collect(app(PermissionCatalog::class)->grouped()->flatMap(fn ($m) => $m['rows']))
        ->flatMap(fn ($row) => collect($row['actions'])->map(fn ($a) => [$row['section'], $a['label'], $a['name']])->values());

    expect($labels->firstWhere(2, 'store.requisition.final'))->toBe(['Purchase Requisition', 'Prepare Final Requisition', 'store.requisition.final'])
        ->and($labels->firstWhere(2, 'store.category_summary.view')[0])->toBe('Category Summary (view + export)');
});

it('keeps an ordinary requisition user off the Store desk', function () {
    $user = deskUser();

    expect(PurchaseRequisition::levelFor($user))->toBe(PurchaseRequisition::LEVEL_DEPARTMENT)
        ->and(PurchaseRequisition::canMerge($user))->toBeFalse()
        ->and(PurchaseRequisition::worksStoreDesk($user))->toBeFalse()
        ->and(PurchaseRequisition::canViewCategorySummary($user))->toBeFalse();

    $this->actingAs($user)->get(route('store.stock.requisitions.requested'))->assertForbidden();
    $this->actingAs($user)->get(route('store.stock.requisitions.category-summary'))->assertForbidden();
});

it('lets a user ticked for Prepare Final Requisition raise the final sheet, see Requested and merge', function () {
    $user = deskUser(['store.requisition.final']);

    expect(PurchaseRequisition::levelFor($user))->toBe(PurchaseRequisition::LEVEL_FINAL)
        ->and(PurchaseRequisition::canMerge($user))->toBeTrue()
        ->and(PurchaseRequisition::worksStoreDesk($user))->toBeTrue();

    $this->actingAs($user)->get(route('store.stock.requisitions.requested'))->assertOk();
    $this->actingAs($user)->get(route('store.stock.requisitions.create'))->assertOk();
    $this->actingAs($user)->get(route('store.stock.requisitions.index', ['level' => 'final']))->assertOk()->assertSee('Category Summary');

    // The merge routes admit them (an empty selection is refused by the form, never by the door).
    expect($this->actingAs($user)->get(route('store.stock.requisitions.merge-review'))->status())->not->toBe(403);
    expect($this->actingAs($user)->post(route('store.stock.requisitions.merge'))->status())->not->toBe(403);
});

it('lets the final-requisition permission open the permission without needing the create tick', function () {
    // View only on the role, no create: the Prepare Final tick is enough to reach the form and the merge.
    $role = Role::findOrCreate('view_only_clerk', 'web');
    $role->givePermissionTo(Permission::findOrCreate('store.requisition.view', 'web'));
    $user = User::factory()->create()->assignRole($role);
    $user->givePermissionTo(Permission::findOrCreate('store.requisition.final', 'web'));
    $user = $user->fresh();

    expect(PurchaseRequisition::canMerge($user))->toBeTrue();
    $this->actingAs($user)->get(route('store.stock.requisitions.create'))->assertOk();
});

it('lets a user ticked for Category Summary open it, and nothing else of the Store desk', function () {
    $user = deskUser(['store.category_summary.view']);

    expect(PurchaseRequisition::canViewCategorySummary($user))->toBeTrue()
        ->and(PurchaseRequisition::worksStoreDesk($user))->toBeFalse()
        ->and(PurchaseRequisition::canMerge($user))->toBeFalse()
        ->and(PurchaseRequisition::levelFor($user))->toBe(PurchaseRequisition::LEVEL_DEPARTMENT);

    $this->actingAs($user)->get(route('store.stock.requisitions.category-summary'))->assertOk();
    $this->actingAs($user)->get(route('store.stock.requisitions.category-summary.excel'))->assertOk();
    $this->actingAs($user)->get(route('store.stock.requisitions.requested'))->assertForbidden();

    // The button shows on the list for them, the Store-only buttons do not.
    $this->actingAs($user)->get(route('store.stock.requisitions.index'))
        ->assertOk()->assertSee('Category Summary')->assertDontSee('Monthly Report');
});

it('leaves the Store, Admin and Management roles exactly as they were', function () {
    foreach (['store', 'admin', 'management'] as $role) {
        $user = User::factory()->create()->assignRole(Role::findOrCreate($role, 'web'));

        expect(PurchaseRequisition::levelFor($user))->toBe(PurchaseRequisition::LEVEL_FINAL)
            ->and(PurchaseRequisition::canViewCategorySummary($user))->toBeTrue();
    }
});
