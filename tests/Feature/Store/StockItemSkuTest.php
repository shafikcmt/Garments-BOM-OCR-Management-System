<?php

use App\Models\AppSetting;
use App\Models\ItemCategory;
use App\Models\StockItem;
use App\Models\User;
use App\Services\StockItemSku;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Item SKU (owner, 2026-09-29).
 *
 *   - Format from tokens, default {CAT:2}-{NAME:3}-{SEQ:4}; letters only,
 *     uppercase, padded with X; serial per prefix; no category → GN.
 *   - Given on create, race-safe, unique; never changes afterwards (rename,
 *     category move, format change); only Admin corrects one, with a reason,
 *     logged.
 *   - Existing items: a dry-run preview first; the real run in the same order.
 *
 * In-memory sqlite. No real record touched.
 */
function skuCategory(string $name, ?string $code = null): ItemCategory
{
    $category = ItemCategory::create(['name' => $name, 'is_active' => true]);
    $category->forceFill(['code' => $code])->save();

    return $category;
}

function skuItem(string $name, ?ItemCategory $category = null, ?string $brand = null): StockItem
{
    return StockItem::create([
        'name' => $name, 'brand' => $brand, 'uom' => 'Pcs', 'is_active' => true, 'opening_qty' => 0,
        'item_category_id' => $category?->id, 'category' => $category?->name,
    ]);
}

/** An item as it stood before SKUs existed: no SKU, no event. */
function skuLegacyItem(string $name, ?ItemCategory $category = null, ?string $brand = null): StockItem
{
    return StockItem::withoutEvents(fn () => skuItem($name, $category, $brand));
}

function skuAdmin(): User
{
    return User::factory()->create()->assignRole(Role::findOrCreate('admin', 'web'));
}

// --- The format ----------------------------------------------------------------------

it('gives the owner\'s example: Ball Pen-Black ST-BAL-0001, Ball Pen-Red ST-BAL-0002', function () {
    $stationery = skuCategory('Stationery & Printing', 'ST');

    expect(skuItem('Ball Pen-Black', $stationery)->sku)->toBe('ST-BAL-0001')
        ->and(skuItem('Ball Pen-Red', $stationery)->sku)->toBe('ST-BAL-0002')
        // Another prefix starts its own serial.
        ->and(skuItem('Stapler', $stationery)->sku)->toBe('ST-STA-0001');
});

it('uses letters only, uppercase, pads with X, and GN for no category', function () {
    $needle = skuCategory('Machine Needles', 'ND');

    expect(skuItem('1st Output Report', $needle)->sku)->toBe('ND-STO-0001')
        ->and(skuItem('X', $needle)->sku)->toBe('ND-XXX-0001')
        ->and(skuItem('3 Phase Bar-63 Amp')->sku)->toBe('GN-PHA-0001')
        // A category without a code: its first two letters.
        ->and(skuItem('Glue Stick', skuCategory('Office Admin'))->sku)->toBe('OF-GLU-0001');

    AppSetting::put(StockItemSku::SETTING_PATTERN, '{CAT:2}{BRAND:3}/{NAME:2}{SEQ:3}');
    expect(skuItem('Needle DBx1', $needle, 'Organ')->sku)->toBe('NDORG/NE001')
        ->and(skuItem('Needle DBx1', $needle)->sku)->toBe('NDXXX/NE001');
});

it('checks a format before it is used', function (string $pattern, ?string $problem) {
    expect(StockItemSku::patternProblem($pattern))->toBe($problem);
})->with([
    ['{CAT:2}-{NAME:3}-{SEQ:4}', null],
    ['GS-{CAT:2}-{SEQ:5}', null],
    ['{CAT:2}-{NAME:3}', 'The format needs exactly one {SEQ:n}.'],
    ['{CAT:2}-{SEQ:4}-{SEQ:4}', 'The format needs exactly one {SEQ:n}.'],
    ['{COLOUR:2}-{SEQ:4}', 'Use only {CAT:n}, {NAME:n}, {BRAND:n} and {SEQ:n} as tokens.'],
    ['{CAT:2}#{SEQ:4}', 'Fixed text may hold letters, digits, spaces and - _ / . only.'],
    ['{CAT:2}-{SEQ:1}', '{SEQ} takes a width from 2 to 8.'],
]);

// --- Never changes ------------------------------------------------------------------

it('never changes an SKU on rename, category move, format change or a mass update', function () {
    $item = skuItem('Ball Pen-Black', skuCategory('Stationery & Printing', 'ST'));

    $item->update(['name' => 'Gel Pen', 'item_category_id' => skuCategory('Office Admin', 'OF')->id]);
    AppSetting::put(StockItemSku::SETTING_PATTERN, 'GS-{NAME:3}-{SEQ:5}');
    $item->forceFill(['sku' => 'HACKED'])->save();

    expect($item->fresh()->sku)->toBe('ST-BAL-0001')
        // The new format applies to a new item only.
        ->and(skuItem('Ball Pen-Blue')->sku)->toBe('GS-BAL-00001');

    // The Edit Item form cannot carry one either.
    Permission::findOrCreate('store.edit', 'web');
    $editor = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('store', 'web'));
    $editor->givePermissionTo('store.edit');
    $this->actingAs($editor)->put(route('store.stock.items.update', $item), ['name' => 'Gel Pen', 'sku' => 'X-1', 'uom' => 'Pcs']);
    expect($item->fresh()->sku)->toBe('ST-BAL-0001');
});

it('skips a number already taken and keeps SKUs unique', function () {
    $st = skuCategory('Stationery & Printing', 'ST');
    $first = skuItem('Ball Pen-Black', $st);
    app(StockItemSku::class)->correct(skuItem('Pencil', $st), 'ST-BAL-0002', 'Label printed with this code', skuAdmin());

    expect(skuItem('Ball Pen-Red', $st)->sku)->toBe('ST-BAL-0003')
        ->and(StockItem::pluck('sku')->unique()->count())->toBe(StockItem::count());

    // The database backs it up.
    expect(fn () => DB::table('stock_items')->where('id', '<>', $first->id)->limit(1)->update(['sku' => $first->sku]))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

// --- Admin correction -----------------------------------------------------------------

it('lets only Admin correct an SKU, with a reason, and logs it', function () {
    $item = skuItem('Ball Pen-Black', skuCategory('Stationery & Printing', 'ST'));
    $admin = skuAdmin();

    $this->actingAs(User::factory()->create()->assignRole(Role::findOrCreate('store', 'web')))
        ->post(route('admin.item-sku.correct', $item), ['sku' => 'ST-BAL-0099', 'reason' => 'x'])
        ->assertForbidden();

    $this->actingAs($admin)->post(route('admin.item-sku.correct', $item), ['sku' => 'st-bal-0099'])
        ->assertSessionHasErrors('reason');
    skuItem('Ball Pen-Red', StockItem::first()->itemCategory);
    $this->actingAs($admin)->post(route('admin.item-sku.correct', $item), ['sku' => 'ST-BAL-0002', 'reason' => 'x'])
        ->assertSessionHasErrors('sku');

    $this->actingAs($admin)->post(route('admin.item-sku.correct', $item), ['sku' => 'st-bal-0099', 'reason' => 'Matches the printed label'])
        ->assertSessionHasNoErrors()->assertSessionHas('success');

    $log = DB::table('stock_item_sku_changes')->sole();
    expect($item->fresh()->sku)->toBe('ST-BAL-0099')
        ->and($log->old_sku)->toBe('ST-BAL-0001')->and($log->new_sku)->toBe('ST-BAL-0099')
        ->and($log->reason)->toBe('Matches the printed label')->and((int) $log->user_id)->toBe($admin->id);
});

// --- Existing items --------------------------------------------------------------------

it('previews the missing SKUs without writing, then applies them in the same order', function () {
    $st = skuCategory('Stationery & Printing', 'ST');
    $el = skuCategory('Electrical Components', 'EL');
    skuLegacyItem('Ball Pen-Red', $st);
    skuLegacyItem('Ball Pen-Black', $st);
    skuLegacyItem('Cable 1.5 rm', $el, 'BRB');
    skuLegacyItem('Cable 1.5 rm', $el, 'Anik');
    skuLegacyItem('probe');
    skuItem('Ball Pen-Blue', $st); // already ST-BAL-0001

    $service = app(StockItemSku::class);
    $preview = collect($service->preview())->map(fn ($r) => [$r['sku'], $r['item']->name, (string) $r['item']->brand])->all();

    // Order: category code, name, brand, id. Continues ST-BAL after 0001.
    expect($preview)->toBe([
        ['EL-CAB-0001', 'Cable 1.5 rm', 'Anik'],
        ['EL-CAB-0002', 'Cable 1.5 rm', 'BRB'],
        ['GN-PRO-0001', 'probe', ''],
        ['ST-BAL-0002', 'Ball Pen-Black', ''],
        ['ST-BAL-0003', 'Ball Pen-Red', ''],
    ])->and(StockItem::whereNull('sku')->count())->toBe(5);

    // The dry-run command writes the Excel preview and changes nothing.
    $this->artisan('stock:generate-skus', ['--dry-run' => true])->assertSuccessful();
    expect(StockItem::whereNull('sku')->count())->toBe(5)
        ->and(glob(storage_path('app/sku-preview/sku-preview-*.xlsx')))->not->toBeEmpty();

    // The real run gives exactly the previewed SKUs.
    $this->artisan('stock:generate-skus', ['--force' => true])->assertSuccessful();
    foreach ($preview as [$sku, $name, $brand]) {
        expect(StockItem::where('name', $name)->where(fn ($q) => $brand === '' ? $q->whereNull('brand') : $q->where('brand', $brand))->value('sku'))->toBe($sku);
    }
    expect(StockItem::whereNull('sku')->count())->toBe(0);
});

it('asks for the checked-preview box before generating from the Admin screen', function () {
    skuLegacyItem('Ball Pen-Black', skuCategory('Stationery & Printing', 'ST'));
    $admin = skuAdmin();

    $this->actingAs($admin)->post(route('admin.item-sku.apply'))->assertSessionHasErrors('confirm');
    expect(StockItem::whereNull('sku')->count())->toBe(1);

    $this->actingAs($admin)->post(route('admin.item-sku.apply'), ['confirm' => '1'])->assertSessionHas('success');
    expect(StockItem::value('sku'))->toBe('ST-BAL-0001');
});

// --- Settings screen -------------------------------------------------------------------

it('shows the format, the codes with clash warnings, and saves both', function () {
    $needle = skuCategory('Needle', 'ND');
    $machine = skuCategory('Machine Needles', 'ND');
    skuItem('B/S Needle DPX17-09', $needle);
    $admin = skuAdmin();

    $this->actingAs($admin)->get(route('admin.item-sku.edit'))
        ->assertOk()
        ->assertSee('{CAT:2}-{NAME:3}-{SEQ:4}')
        ->assertSee('Codes shared by more than one category')
        ->assertSee('Machine Needles · Needle')
        ->assertSee('Download Preview')
        ->assertSee('Generate Missing SKUs');

    $this->actingAs($admin)->post(route('admin.item-sku.pattern'), ['pattern' => '{CAT:2}-{SEQ:4}-{SEQ:4}'])->assertSessionHasErrors('pattern');
    $this->actingAs($admin)->post(route('admin.item-sku.pattern'), ['pattern' => 'GS-{CAT:2}-{NAME:3}-{SEQ:5}'])->assertSessionHas('success');
    $this->actingAs($admin)->post(route('admin.item-sku.codes'), ['codes' => [$machine->id => 'mn']])->assertSessionHas('success');

    expect(StockItemSku::pattern())->toBe('GS-{CAT:2}-{NAME:3}-{SEQ:5}')
        ->and($machine->fresh()->code)->toBe('MN')
        ->and(skuItem('APW/M Needle', $machine->fresh())->sku)->toBe('GS-MN-APW-00001');

    $this->actingAs(User::factory()->create()->assignRole(Role::findOrCreate('store', 'web')))
        ->get(route('admin.item-sku.edit'))->assertForbidden();
});

// --- Where it shows ---------------------------------------------------------------------

it('shows the SKU in the Items list and its search, the Edit modal, the pickers and the Stock Report', function () {
    $st = skuCategory('Stationery & Printing', 'ST');
    skuItem('Ball Pen-Black', $st);
    skuItem('Stapler', $st);

    foreach (['store.items.view', 'store.issues.view', 'store.issues.create', 'store.receiving.view', 'store.receiving.create', 'store.workspace.view'] as $p) {
        Permission::findOrCreate($p, 'web');
    }
    $user = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('store', 'web'));
    $user->givePermissionTo(Permission::all());

    $this->actingAs($user)->get(route('store.stock.items.index'))
        ->assertOk()->assertSee('<th>SKU</th>', false)->assertSee('ST-BAL-0001')// The Edit modal's read-only SKU box.
        ->assertSee('<span class="gx-edit-locked-value">ST-BAL-0001</span>', false);
    $this->actingAs($user)->get(route('store.stock.items.index', ['search' => 'st-sta']))
        ->assertOk()->assertSee('Stapler')->assertDontSee('Ball Pen-Black');

    $this->actingAs($user)->get(route('store.stock.issues.index'))->assertOk()->assertSee('ST-BAL-0001 — Ball Pen-Black');
    $this->actingAs($user)->get(route('store.stock.purchases.index'))->assertOk()->assertSee('ST-BAL-0001 — Ball Pen-Black');
    $this->actingAs($user)->get(route('store.stock.ledger'))->assertOk()->assertSee('ST-BAL-0001');
});
