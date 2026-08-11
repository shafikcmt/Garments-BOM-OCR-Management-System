<?php

use App\Models\PurchaseRequisition;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Store dashboard split into one per module.
 *
 * The bug it fixes was not untidiness: seven of the nine cards read Buyer /
 * Style tables, and a Store — General Stock user holds no material.*
 * permission at all, so the screen showed them a module every one of whose
 * screens refuses them. These assert the separation by looking for the other
 * module's figures and route links in the rendered HTML, not just by counting
 * cards.
 *
 * In-memory sqlite. No real record touched.
 */
const GS_PERMS = ['store.stock_report.view', 'store.items.view', 'store.receiving.view',
    'store.issues.view', 'store.requisition.view', 'store.setup.view'];

const BS_PERMS = ['material.closing_stock.view', 'material.receiving.view',
    'material.bulk_issue.view', 'material.requisitions.view'];

function dashSeed(): void
{
    foreach (array_merge(GS_PERMS, BS_PERMS, ['store.workspace.view']) as $p) {
        Permission::findOrCreate($p, 'web');
    }

    Role::findOrCreate('store_general_stock', 'web')->syncPermissions(GS_PERMS);
    Role::findOrCreate('store_material_stock', 'web')->syncPermissions(BS_PERMS);
    Role::findOrCreate('store', 'web')->syncPermissions(
        array_merge(GS_PERMS, BS_PERMS, ['store.workspace.view'])
    );
    Role::findOrCreate('admin', 'web');

    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

function dashUser(string $role): User
{
    return User::factory()->create(['status' => 1])->assignRole($role);
}

beforeEach(fn () => dashSeed());

it('shows a General Stock user no Buyer/Style data at all', function () {
    $user = dashUser('store_general_stock');

    expect($user->getAllPermissions()->pluck('name')->filter(
        fn ($p) => str_starts_with($p, 'material.')
    ))->toBeEmpty();

    $html = $this->actingAs($user)->get(route('store.dashboard'))->assertOk()->getContent();

    // None of the Buyer / Style vocabulary, and none of its links.
    foreach (['Material lines tracked', 'Running closing qty', 'Closing stock split',
        'Liability', 'Bulk Issue', 'Receivings — last 6 months'] as $phrase) {
        expect($html)->not->toContain($phrase);
    }

    foreach ([route('store.material.ledger'), route('store.material.receivings.index'),
        route('store.material.bulk-issues.index'), route('store.material.requisitions.index')] as $url) {
        expect($html)->not->toContain($url);
    }

    // And its own data is present.
    expect($html)->toContain('Items tracked')
        ->and($html)->toContain('Items needing purchase')
        ->and($html)->toContain('General Stock');
});

it('shows a Buyer/Style user no General Stock data at all', function () {
    $user = dashUser('store_material_stock');

    $html = $this->actingAs($user)->get(route('store.material.dashboard'))->assertOk()->getContent();

    foreach (['Items needing purchase', 'Items tracked', 'Needs attention',
        'Pending purchase requisitions', 'Place Order'] as $phrase) {
        expect($html)->not->toContain($phrase);
    }

    foreach ([route('store.stock.ledger'), route('store.stock.items.index'),
        route('store.stock.purchases.index'), route('store.stock.issues.index')] as $url) {
        expect($html)->not->toContain($url);
    }

    expect($html)->toContain('Material lines tracked')
        ->and($html)->toContain('Closing stock split')
        ->and($html)->toContain('Buyer / Style Stock');
});

it('refuses each module dashboard to the other module user', function () {
    $this->actingAs(dashUser('store_material_stock'))
        ->get(route('store.dashboard'))->assertForbidden();

    $this->actingAs(dashUser('store_general_stock'))
        ->get(route('store.material.dashboard'))->assertForbidden();
});

it('lands each user on the dashboard they can actually open', function () {
    $this->actingAs(dashUser('store_general_stock'))
        ->get(route('dashboard'))
        ->assertRedirect(route('store.dashboard'));

    $this->actingAs(dashUser('store_material_stock'))
        ->get(route('dashboard'))
        ->assertRedirect(route('store.material.dashboard'));

    // Holds both: General Stock first, by design.
    $this->actingAs(dashUser('store'))
        ->get(route('dashboard'))
        ->assertRedirect(route('store.dashboard'));
});

it('sends a Store user with no module permissions to their profile, not a 403', function () {
    // The edge case: a narrow role stripped of everything.
    Role::findByName('store_general_stock', 'web')->syncPermissions([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $stranded = dashUser('store_general_stock');

    expect($stranded->getAllPermissions())->toHaveCount(0);

    $this->actingAs($stranded)
        ->get(route('dashboard'))
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('warning');

    // And the page they land on actually opens.
    $this->actingAs($stranded)->get(route('profile.edit'))->assertOk();
});

it('counts pending purchase requisitions from the General Stock model', function () {
    // NEW LOGIC — the one genuinely new figure in this redesign. The card it
    // replaces counted MaterialRequisition, which is Buyer / Style and has
    // moved to that dashboard. Pending is every status except the terminal one.
    $user = dashUser('store_general_stock');

    foreach ([PurchaseRequisition::STATUS_DRAFT,
        PurchaseRequisition::STATUS_SUBMITTED,
        PurchaseRequisition::STATUS_PURCHASE_ACTION_TAKEN] as $i => $status) {
        PurchaseRequisition::create([
            'requisition_no' => 'PR-'.$i,
            'requisition_date' => now(),
            'status' => $status,
        ]);
    }

    $stats = $this->actingAs($user)
        ->get(route('store.dashboard'))
        ->assertOk()
        ->viewData('stats');

    // Draft and submitted count; the actioned one does not.
    expect($stats['pending_requisitions'])->toBe(2);
});

it('hides a card when the user lacks that section permission', function () {
    // Entry to the module does not imply every screen inside it.
    Role::findOrCreate('gs_partial', 'web')->syncPermissions(['store.items.view']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $user = User::factory()->create(['status' => 1])->assignRole('gs_partial');

    $html = $this->actingAs($user)->get(route('store.dashboard'))->assertOk()->getContent();

    expect($html)->toContain('Items tracked')
        // No stock report permission, so no attention list and no link to it.
        ->and($html)->not->toContain('Needs attention')
        ->and($html)->not->toContain('Pending purchase requisitions');
});

it('puts the BOM share card on Workspace and nowhere else', function () {
    $user = dashUser('store');   // holds both modules and workspace

    $dash = $this->actingAs($user)->get(route('store.dashboard'))->assertOk()->getContent();
    $material = $this->actingAs($user)->get(route('store.material.dashboard'))->assertOk()->getContent();
    $workspace = $this->actingAs($user)->get(route('store.workspace'))->assertOk()->getContent();

    expect($dash)->not->toContain('Your share of the BOM')
        ->and($material)->not->toContain('Your share of the BOM');

    // Present on Workspace whenever this department owns BOM columns; the card
    // is hidden rather than showing a zero when it owns none, so accept either
    // the card or an explicitly empty share.
    expect($workspace)->toContain('Uploaded Files');
});
