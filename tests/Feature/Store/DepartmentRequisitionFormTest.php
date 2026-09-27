<?php

use App\Models\IndentSection;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Models\RequisitionDepartmentHead;
use App\Models\StockItem;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The department-level requisition form: what it fixes, what it hides, and
 * what the server refuses to take from it — against the Store's own form,
 * which must not have moved.
 *
 *   - Section, User Dept. and the number follow the user's requisition
 *     section (Admin > Users); Requested By and Contact follow the
 *     department head (Admin > Settings). Whatever is posted, the server
 *     writes those.
 *   - Stock, last purchase, rate and amount are hidden from a department
 *     user and stripped from the snapshot endpoint; a posted rate is dropped.
 *   - A department with no head, or a user with no section, keeps today's
 *     open fields.
 *   - "Not in Item Master" lines: the Store is told on Received, the Add
 *     form opens seeded, and the save links the line.
 *
 * In-memory sqlite. No real record touched.
 */

function formDeptUser(string $department = 'production', ?IndentSection $section = null): User
{
    Role::findOrCreate($department, 'web');
    $role = Role::findOrCreate('dept-requisition', 'web');

    foreach (['store.requisition.view', 'store.requisition.create'] as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return User::factory()->create(['requisition_section_id' => $section?->id])
        ->assignRole($department, 'dept-requisition');
}

function formStoreUser(): User
{
    $role = Role::findOrCreate('store', 'web');
    foreach (['store.requisition.review', 'store.items.create', 'store.items.view'] as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return User::factory()->create()->assignRole('store');
}

function cuttingSection(): IndentSection
{
    return IndentSection::create(['name' => 'Cutting']);
}

function oilItem(): StockItem
{
    return StockItem::create(['name' => 'Machine Oil', 'uom' => 'Ltr', 'brand' => 'Mobil', 'is_active' => true]);
}

/** @return array<string, mixed> a full post, with the fields a tampered form might send */
function requisitionPost(StockItem $item, array $overrides = []): array
{
    return $overrides + [
        'action' => 'submit',
        'mode' => PurchaseRequisition::MODE_MULTI,
        'requisition_date' => '2026-09-22',
        'items' => [['stock_item_id' => $item->id, 'qty_requested' => 4]],
    ];
}

// --- Numbering and fixed header fields ------------------------------------------

it('numbers a department sheet under the user\'s own section, and fixes Section and User Dept.', function () {
    $section = cuttingSection();
    $other = IndentSection::create(['name' => 'Finishing']);
    $user = formDeptUser('production', $section);
    $item = oilItem();

    // The form shows the section locked and the number previewed under it.
    $this->actingAs($user)
        ->get(route('store.stock.requisitions.create'))
        ->assertOk()
        ->assertSee('HAPL/CUTTING/2026/')
        ->assertSee('value="Cutting"', false)
        ->assertDontSee('id="sectionSelect" class="form-select', false);

    // A tampered post naming another section and another department is
    // overridden on save.
    $this->actingAs($user)
        ->post(route('store.stock.requisitions.store'), requisitionPost($item, [
            'indent_section_id' => $other->id,
            'items' => [['stock_item_id' => $item->id, 'qty_requested' => 4, 'user_dept' => 'Finishing']],
        ]))
        ->assertRedirect();

    $requisition = PurchaseRequisition::query()->latest('id')->with('items')->first();

    expect($requisition->requisition_no)->toStartWith('HAPL/CUTTING/2026/')
        ->and($requisition->indent_section_id)->toBe($section->id)
        ->and($requisition->items->first()->user_dept)->toBe('Cutting');
});

it('fills Requested By and Contact from the department head, ignoring what is posted', function () {
    $head = formDeptUser('production');
    RequisitionDepartmentHead::assign('production', $head);
    $user = formDeptUser('production', cuttingSection());
    $item = oilItem();

    $this->actingAs($user)
        ->get(route('store.stock.requisitions.create'))
        ->assertOk()
        ->assertSee('value="'.e($head->name).'"', false)
        ->assertSee('value="'.$head->email.'"', false)
        ->assertDontSee('name="indent_person_id"', false);

    $this->actingAs($user)
        ->post(route('store.stock.requisitions.store'), requisitionPost($item, ['contact' => 'someone@else.test']))
        ->assertRedirect();

    $requisition = PurchaseRequisition::query()->latest('id')->first();

    expect($requisition->contact)->toBe($head->email)
        ->and($requisition->indent_person_id)->toBeNull();

    // The sheet names the head as its user, on screen and in the document.
    $this->actingAs($user)
        ->get(route('store.stock.requisitions.show', $requisition))
        ->assertOk()
        ->assertSee($head->name);
});

it('keeps the open Section and Requested By fields when nothing is configured for the department', function () {
    $user = formDeptUser('commercial');   // no section, no head
    $section = cuttingSection();
    $item = oilItem();

    $this->actingAs($user)
        ->get(route('store.stock.requisitions.create'))
        ->assertOk()
        ->assertSee('name="indent_section_id"', false)
        ->assertSee('name="indent_person_id"', false)
        ->assertSee('name="contact"', false)
        // Numbered under the department rather than the Store's ALL.
        ->assertSee('HAPL/COMMERCIAL/2026/');

    $this->actingAs($user)
        ->post(route('store.stock.requisitions.store'), requisitionPost($item, [
            'indent_section_id' => $section->id,
            'contact' => '01700000000',
        ]))
        ->assertRedirect();

    $requisition = PurchaseRequisition::query()->latest('id')->with('items')->first();

    expect($requisition->indent_section_id)->toBe($section->id)
        ->and($requisition->contact)->toBe('01700000000')
        ->and($requisition->requisition_no)->toStartWith('HAPL/COMMERCIAL/2026/')
        ->and($requisition->items->first()->user_dept)->toBe('Commercial');
});

it('leaves the Store\'s own form exactly as it was', function () {
    $store = formStoreUser();
    $section = cuttingSection();
    $item = oilItem();

    $html = $this->actingAs($store)
        ->get(route('store.stock.requisitions.create'))
        ->assertOk()
        ->assertSee('HAPL/ALL/2026/')
        ->assertSee('name="indent_section_id"', false)
        ->assertSee('name="indent_person_id"', false)
        ->assertSee('name="contact"', false)
        ->getContent();

    // Figures visible, rate editable, total shown.
    expect($html)->toContain('js-line-stock')
        ->and($html)->not->toMatch('~class="gx-req-line-foot"\s+hidden~')
        ->and($html)->not->toMatch('~class="gx-req-field is-rate"\s+hidden~')
        ->and($html)->not->toMatch('~class="gx-req-total"\s+hidden~');

    $this->actingAs($store)
        ->post(route('store.stock.requisitions.store'), requisitionPost($item, [
            'indent_section_id' => $section->id,
            'contact' => 'store@x.test',
            'items' => [['stock_item_id' => $item->id, 'qty_requested' => 4, 'user_dept' => 'Sewing', 'rate_appx' => 12.5]],
        ]))
        ->assertRedirect();

    $requisition = PurchaseRequisition::query()->latest('id')->with('items')->first();

    expect($requisition->level)->toBe(PurchaseRequisition::LEVEL_FINAL)
        ->and($requisition->requisition_no)->toStartWith('HAPL/CUTTING/2026/')
        ->and($requisition->contact)->toBe('store@x.test')
        ->and($requisition->items->first()->user_dept)->toBe('Sewing')
        ->and((float) $requisition->items->first()->rate_appx)->toBe(12.5);
});

// --- Hidden figures ----------------------------------------------------------------

it('hides stock, last purchase, rate and amount from a department user and drops a posted rate', function () {
    $user = formDeptUser('production', cuttingSection());
    $item = oilItem();

    $html = $this->actingAs($user)
        ->get(route('store.stock.requisitions.create'))
        ->assertOk()
        ->getContent();

    expect($html)->toMatch('~class="gx-req-line-foot"\s+hidden~')
        ->and($html)->toMatch('~class="gx-req-field is-rate"\s+hidden~')
        ->and($html)->toMatch('~class="gx-req-total"\s+hidden~');

    // The snapshot endpoint hands them only the descriptive fields.
    $this->actingAs($user)
        ->getJson(route('store.stock.requisitions.item-snapshot', $item))
        ->assertOk()
        ->assertJsonStructure(['uom', 'specification', 'item_category_id'])
        ->assertJsonMissing(['stock_in_hand'])
        ->assertJsonMissingPath('last_purchase_rate');

    // …while the Store still gets everything.
    $this->actingAs(formStoreUser())
        ->getJson(route('store.stock.requisitions.item-snapshot', $item))
        ->assertOk()
        ->assertJsonPath('uom', 'Ltr')
        ->assertJsonStructure(['stock_in_hand', 'last_purchase_rate']);

    // A rate slipped into the post is not taken.
    $this->actingAs($user)
        ->post(route('store.stock.requisitions.store'), requisitionPost($item, [
            'items' => [['stock_item_id' => $item->id, 'qty_requested' => 4, 'rate_appx' => 999]],
        ]))
        ->assertRedirect();

    expect((float) PurchaseRequisitionItem::query()->latest('id')->first()->rate_appx)->not->toBe(999.0);
});

// --- Not in Item Master ---------------------------------------------------------

it('lets a department raise a typed item, tells the Store on Received, and links the line once added', function () {
    $user = formDeptUser('production', cuttingSection());
    $store = formStoreUser();

    $this->actingAs($user)
        ->post(route('store.stock.requisitions.store'), [
            'action' => 'submit',
            'mode' => PurchaseRequisition::MODE_MULTI,
            'requisition_date' => '2026-09-22',
            'items' => [['custom_item_name' => 'Laser Pointer', 'specification' => 'Green', 'qty_requested' => 2]],
        ])
        ->assertRedirect();

    $requisition = PurchaseRequisition::query()->latest('id')->with('items')->first();
    $line = $requisition->items->first();

    expect($line->isCustomItem())->toBeTrue()
        ->and($line->user_dept)->toBe('Cutting');

    // The department sees no promotion notice…
    $this->actingAs($user)
        ->get(route('store.stock.requisitions.show', $requisition))
        ->assertOk()
        ->assertDontSee('Add to Item Master');

    // …the Store is told on Received and offered the action.
    $this->actingAs($store)
        ->post(route('store.stock.requisitions.received', $requisition))
        ->assertRedirect(route('store.stock.requisitions.show', $requisition))
        ->assertSessionHas('success', fn ($m) => str_contains($m, 'not in the Item Master'));

    $this->actingAs($store)
        ->get(route('store.stock.requisitions.show', $requisition))
        ->assertOk()
        ->assertSee('Add to Item Master')
        ->assertSee(route('store.stock.items.index', ['requisition_item' => $line->id]), false);

    // The Item Master's Add form opens seeded with what was typed.
    $this->actingAs($store)
        ->get(route('store.stock.items.index', ['requisition_item' => $line->id]))
        ->assertOk()
        ->assertSee('value="Laser Pointer"', false)
        ->assertSee('value="Green"', false)
        ->assertSee('name="requisition_item" value="'.$line->id.'"', false);

    // Saving creates the item and links the line.
    $this->actingAs($store)
        ->post(route('store.stock.items.store'), [
            'form' => 'add',
            'name' => 'Laser Pointer',
            'brand' => 'Green',
            'uom' => 'Pcs',
            'requisition_item' => $line->id,
        ])
        ->assertRedirect(route('store.stock.requisitions.show', $requisition));

    $line->refresh();
    $item = StockItem::where('name', 'Laser Pointer')->firstOrFail();

    expect($line->stock_item_id)->toBe($item->id)
        ->and($line->custom_item_name)->toBeNull()
        ->and($line->uom_snapshot)->toBe('Pcs')
        ->and($line->isCustomItem())->toBeFalse();

    // The notice is gone now.
    $this->actingAs($store)
        ->get(route('store.stock.requisitions.show', $requisition))
        ->assertDontSee('Add to Item Master');
});

it('never offers the promotion action to someone without the item-create permission', function () {
    $user = formDeptUser('production', cuttingSection());

    $requisition = PurchaseRequisition::create([
        'requisition_no' => 'X-'.uniqid(),
        'requisition_date' => '2026-09-22',
        'mode' => PurchaseRequisition::MODE_MULTI,
        'level' => PurchaseRequisition::LEVEL_DEPARTMENT,
        'status' => PurchaseRequisition::STATUS_STORE_REVIEWED,
        'created_by' => $user->id,
    ]);
    PurchaseRequisitionItem::create([
        'purchase_requisition_id' => $requisition->id, 'custom_item_name' => 'Widget', 'sort_order' => 1, 'qty_requested' => 1,
    ]);

    // Management reads every requisition but was not given store.items.create here.
    $management = User::factory()->create()->assignRole(Role::findOrCreate('management', 'web'));

    $this->actingAs($management)
        ->get(route('store.stock.requisitions.show', $requisition))
        ->assertOk()
        ->assertDontSee('Add to Item Master');
});
