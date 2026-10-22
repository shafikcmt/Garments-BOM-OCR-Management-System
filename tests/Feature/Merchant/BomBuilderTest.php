<?php

use App\Models\Buyer;
use App\Models\BuyerSeason;
use App\Models\BomApproval;
use App\Models\BomItem;
use App\Models\ChildStyle;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\MotherStyle;
use App\Models\OrderLine;
use App\Models\User;
use App\Services\BomApprovals;
use Spatie\Permission\Models\Role;

/**
 * The BOM builder: a style's BOM made on a screen, written as workspace rows so
 * everything downstream (calculations, approval, PO) works on it unchanged.
 */
function bbHeaders(?Role $owner = null, array $skip = []): array
{
    $owner ??= Role::findOrCreate('merchant', 'web');
    $defs = [
        'season_name' => 'Season Name', 'style_name' => 'Style', 'gmts_color_name' => 'GMTS Color Name',
        'po_number' => 'PO Number', 'contract_shipment_date' => 'Contract Shipment Date',
        'material_type' => 'Material Type', 'material_description' => 'Material Description', 'sap_code' => 'SAP Code',
        'material_color' => 'Material Color', 'uom' => 'UOM', 'bom_quantity' => 'BOM Quantity',
        'initial_consumption' => 'Initial Consumption', 'wastage_for_ordering_percent' => '% Wastage for ordering',
        'consumption_incl_yy' => 'Consumption based on which materials order including YY',
        'materials_to_be_ordered' => 'Materials to be Ordered',
    ];
    $headers = [];
    $pos = 1;
    foreach ($defs as $key => $name) {
        if (in_array($key, $skip, true)) {
            continue;
        }
        $headers[$key] = ExcelHeader::create(['header_name' => $name, 'header_key' => $key, 'owner_role_id' => $owner->id, 'position' => $pos++]);
    }

    return $headers;
}

function bbStyle(?Buyer $buyer = null): MotherStyle
{
    $buyer ??= Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    $season = BuyerSeason::create(['buyer_id' => $buyer->id, 'code' => 'SP27']);

    return MotherStyle::create(['buyer_id' => $buyer->id, 'buyer_season_id' => $season->id, 'style_no' => '748268']);
}

function bbUser(string $role = 'merchant', array $attrs = []): User
{
    Role::findOrCreate($role, 'web');

    return User::factory()->create($attrs + ['status' => 1])->assignRole($role);
}

function bbPayload(array $over = []): array
{
    return $over + [
        'lines' => [
            ['color' => 'WHITE', 'po' => 'C1', 'ship_date' => '2026-12-01', 'qty' => 1200, 'child' => ''],
            ['color' => 'BLACK', 'po' => 'C2', 'ship_date' => '2026-12-15', 'qty' => 800, 'child' => ''],
        ],
        'materials' => [
            ['type' => 'SHELL', 'description' => 'Denim 98% cotton', 'sap_code' => 'D1', 'color' => 'INDIGO', 'uom' => 'YDS', 'consumption' => 1.43, 'wastage' => 5],
            ['type' => 'TRIMS', 'description' => 'Zipper', 'sap_code' => 'Z1', 'color' => '', 'uom' => 'PCS', 'consumption' => 1, 'wastage' => ''],
        ],
    ];
}

function bbCell(ExcelRow $row, ExcelHeader $header): ?string
{
    return ExcelCell::where('row_id', $row->id)->where('header_id', $header->id)->value('value');
}

it('creates the BOM as workspace rows, calculates it, and feeds the standard tables', function () {
    $headers = bbHeaders();
    $mother = bbStyle();
    $child = ChildStyle::create(['mother_style_id' => $mother->id, 'style_no' => '866317', 'kind' => 'linked']);
    $merchant = bbUser();

    $payload = bbPayload();
    $payload['lines'][1]['child'] = $child->id;

    $this->actingAs($merchant)->get(route('merchant.bom.create', $mother))->assertOk()->assertSee('Create BOM')->assertSee('Order lines');
    $this->actingAs($merchant)->post(route('merchant.bom.store', $mother), $payload)
        ->assertRedirect(route('merchant.styles.mothers.show', $mother))->assertSessionHas('success', '4 BOM line(s) created.');

    $file = ExcelFile::sole();
    expect($file->file_path)->toBe('manual-bom')->and($file->buyer_id)->toBe($mother->buyer_id)->and($file->buyer_season_id)->toBe($mother->buyer_season_id)->and($file->total_rows)->toBe(4);
    expect($file->rows()->pluck('origin')->unique()->all())->toBe(['added']);

    $row = $file->rows()->orderBy('row_number')->first();
    expect(bbCell($row, $headers['style_name']))->toBe('748268')
        ->and(bbCell($row, $headers['gmts_color_name']))->toBe('WHITE')
        ->and(bbCell($row, $headers['po_number']))->toBe('C1')
        ->and(bbCell($row, $headers['season_name']))->toBe('SP27')
        ->and(bbCell($row, $headers['bom_quantity']))->toBe('1200')
        ->and(bbCell($row, $headers['initial_consumption']))->toBe('1.43')
        ->and(bbCell($row, $headers['wastage_for_ordering_percent']))->toBe('0.05');

    // The workspace's own formulas accepted the written values: 1.43 x 1.05 x 1200.
    expect((int) bbCell($row, $headers['materials_to_be_ordered']))->toBe(1802);

    // Standard tables, linked to the Style Library; the child line carries "mother (child)".
    expect(OrderLine::count())->toBe(2)->and(BomItem::count())->toBe(4);
    $childLine = OrderLine::where('gmts_color', 'BLACK')->first();
    expect($childLine->style_no)->toBe('748268 (866317)')->and($childLine->mother_style_id)->toBe($mother->id)->and($childLine->child_style_id)->toBe($child->id);
    expect((float) BomItem::where('sap_code', 'D1')->orderBy('id')->value('bom_qty'))->toBe(1200.0);
    expect(MotherStyle::count())->toBe(1);
});

it('adds to the same file later and never adds a material to an order line twice', function () {
    bbHeaders();
    $mother = bbStyle();
    $merchant = bbUser();

    $this->actingAs($merchant)->post(route('merchant.bom.store', $mother), bbPayload());

    $again = $this->actingAs($merchant)->post(route('merchant.bom.store', $mother), bbPayload());
    $again->assertSessionHas('warning', fn ($m) => str_contains($m, 'Nothing was added') && str_contains($m, '4 skipped'));
    expect(BomItem::count())->toBe(4);

    $more = bbPayload();
    $more['materials'][] = ['type' => 'LABEL', 'description' => 'Main label', 'sap_code' => 'L1', 'color' => '', 'uom' => 'PCS', 'consumption' => 1, 'wastage' => 2];
    $this->actingAs($merchant)->post(route('merchant.bom.store', $mother), $more)
        ->assertSessionHas('success', '2 BOM line(s) created, 4 skipped because they already exist.');

    expect(ExcelFile::count())->toBe(1)->and(ExcelFile::first()->total_rows)->toBe(6)->and(BomItem::count())->toBe(6);
    expect(ExcelRow::pluck('row_number')->sort()->values()->all())->toBe([1, 2, 3, 4, 5, 6]);

    // The page offers the existing order lines again, already filled in.
    $page = $this->actingAs($merchant)->get(route('merchant.bom.create', $mother))->assertOk();
    expect(collect($page->viewData('prefill'))->pluck('color')->sort()->values()->all())->toBe(['BLACK', 'WHITE']);
    expect((float) $page->viewData('prefill')[0]['qty'])->toBe(1200.0)->and($page->viewData('prefill')[0]['color'])->toBe('WHITE');
});

it('puts an approved BOM back to "changed" when material is added', function () {
    bbHeaders();
    $mother = bbStyle();
    $merchant = bbUser();
    $head = bbUser('merchant', ['is_department_admin' => true]);
    $service = app(BomApprovals::class);

    $this->actingAs($merchant)->post(route('merchant.bom.store', $mother), bbPayload());
    $service->submit($mother->fresh(), $merchant);
    $service->approve(BomApproval::sole(), $head, null);
    expect($service->state($mother->fresh()))->toBe('approved');

    $more = bbPayload();
    $more['materials'] = [['type' => 'LABEL', 'description' => 'Main label', 'sap_code' => 'L1', 'color' => '', 'uom' => 'PCS', 'consumption' => 1, 'wastage' => '']];
    $this->actingAs($merchant)->post(route('merchant.bom.store', $mother), $more);

    expect($service->state($mother->fresh()))->toBe('changed');
});

it('says which columns it could not fill and refuses when the workspace has no Style column', function () {
    $store = Role::findOrCreate('store', 'web');
    $headers = bbHeaders();
    $headers['sap_code']->update(['owner_role_id' => $store->id]);   // not this role's column
    $mother = bbStyle();
    $merchant = bbUser();

    $page = $this->actingAs($merchant)->get(route('merchant.bom.create', $mother))->assertOk()->assertSee('Your role cannot edit these workspace columns');
    expect($page->viewData('columns')['locked'])->toBe(['SAP code']);

    $this->actingAs($merchant)->post(route('merchant.bom.store', $mother), bbPayload())
        ->assertSessionHas('success')->assertSessionHas('info', fn ($m) => str_contains($m, 'SAP code'));
    expect(ExcelCell::where('header_id', $headers['sap_code']->id)->whereNotNull('value')->count())->toBe(0);

    ExcelHeader::where('header_key', 'style_name')->delete();
    $this->actingAs($merchant)->post(route('merchant.bom.store', $mother), bbPayload(['materials' => [['description' => 'Other', 'consumption' => 1]]]))
        ->assertSessionHas('warning', fn ($m) => str_contains($m, 'no usable "Style" column'));
});

it('validates the input and a child style of another style', function () {
    bbHeaders();
    $mother = bbStyle();
    $other = bbStyle(Buyer::create(['buyer_name' => 'Other', 'is_active' => true]));
    $foreignChild = ChildStyle::create(['mother_style_id' => $other->id, 'style_no' => 'X', 'kind' => 'variant']);
    $merchant = bbUser();
    $url = route('merchant.bom.store', $mother);

    $this->actingAs($merchant)->post($url, bbPayload(['lines' => [['color' => 'A', 'qty' => '']]]))->assertSessionHasErrors('lines.0.qty');
    $this->actingAs($merchant)->post($url, bbPayload(['materials' => [['description' => '', 'consumption' => 1]]]))->assertSessionHasErrors('materials.0.description');
    $this->actingAs($merchant)->post($url, bbPayload(['materials' => [['description' => 'X', 'consumption' => '']]]))->assertSessionHasErrors('materials.0.consumption');
    $this->actingAs($merchant)->post($url, bbPayload(['materials' => [['description' => 'X', 'consumption' => 1, 'wastage' => 150]]]))->assertSessionHasErrors('materials.0.wastage');
    $this->actingAs($merchant)->post($url, [])->assertSessionHasErrors(['lines', 'materials']);

    $bad = bbPayload();
    $bad['lines'][0]['child'] = $foreignChild->id;
    $this->actingAs($merchant)->post($url, $bad)->assertSessionHas('warning', fn ($m) => str_contains($m, 'does not belong'));

    // 30 lines x 20 materials is over the 500-row limit.
    $many = ['lines' => array_fill(0, 30, ['color' => 'C', 'qty' => 10]), 'materials' => array_map(fn ($i) => ['description' => "M$i", 'consumption' => 1], range(1, 20))];
    $this->actingAs($merchant)->post($url, $many)->assertSessionHas('warning', fn ($m) => str_contains($m, 'more than 500'));

    expect(ExcelRow::count())->toBe(0);
});

it('is for Merchandising and Admin with access to the buyer, and offers itself on the style page', function () {
    bbHeaders();
    $mother = bbStyle();
    $merchant = bbUser();

    $this->actingAs($merchant)->get(route('merchant.styles.mothers.show', $mother))->assertOk()->assertSee('Create BOM');
    $this->actingAs(bbUser('admin'))->get(route('merchant.bom.create', $mother))->assertOk();

    foreach (['management', 'store', 'supply_chain'] as $role) {
        $this->actingAs(bbUser($role))->get(route('merchant.bom.create', $mother))->assertForbidden();
        $this->actingAs(bbUser($role))->post(route('merchant.bom.store', $mother), bbPayload())->assertForbidden();
    }
    $this->actingAs(bbUser('management'))->get(route('merchant.status.style', $mother))->assertOk()->assertDontSee('Create BOM');

    $mine = Buyer::create(['buyer_name' => 'Mine', 'is_active' => true]);
    $scoped = User::factory()->create(['buyer_id' => $mine->id, 'status' => 1])->assignRole('merchant');
    $scoped->buyers()->attach($mine->id);
    $this->actingAs($scoped->fresh())->get(route('merchant.bom.create', $mother))->assertForbidden();
    $this->actingAs($scoped->fresh())->post(route('merchant.bom.store', $mother), bbPayload())->assertForbidden();

    // After the BOM exists the button says "Add to BOM".
    $this->actingAs($merchant)->post(route('merchant.bom.store', $mother), bbPayload());
    $this->actingAs($merchant)->get(route('merchant.styles.mothers.show', $mother))->assertOk()->assertSee('Add to BOM');
});

// ---------------------------------------------------------------------------
// Material library (learned from earlier BOMs) and copying a BOM
// ---------------------------------------------------------------------------

/** One order line + one material through the builder. */
function bbBuild(User $user, MotherStyle $mother, string $color, array $material, ?string $po = null): void
{
    test()->actingAs($user)->post(route('merchant.bom.store', $mother), [
        'lines' => [['color' => $color, 'po' => $po ?? $color . '-PO', 'qty' => 100, 'child' => '']],
        'materials' => [$material],
    ])->assertSessionHas('success');
}

function bbMaterial(string $description, string $sap, float $consumption = 1.0, $wastage = 5, string $type = 'TRIMS'): array
{
    return ['type' => $type, 'description' => $description, 'sap_code' => $sap, 'color' => 'RED', 'uom' => 'PCS', 'consumption' => $consumption, 'wastage' => $wastage];
}

it('copies the materials of an earlier style, merging duplicates and honouring the chosen order line', function () {
    bbHeaders();
    $source = bbStyle();
    $target = MotherStyle::create(['buyer_id' => $source->buyer_id, 'buyer_season_id' => $source->buyer_season_id, 'style_no' => '999111']);
    $merchant = bbUser();

    bbBuild($merchant, $source, 'WHITE', bbMaterial('Zipper', 'Z1', 1.0, 5));
    bbBuild($merchant, $source, 'BLACK', bbMaterial('Zipper', 'Z1', 1.0, 5));          // the same material on a second colour
    bbBuild($merchant, $source, 'BLACK', bbMaterial('Main label', 'L1', 1.0, 2));
    $whiteLine = OrderLine::where('mother_style_id', $source->id)->where('gmts_color', 'WHITE')->value('id');

    $all = $this->actingAs($merchant)->get(route('merchant.bom.create', [$target, 'from' => $source->id]))->assertOk()->assertSee('Copied 2 material(s) from style 748268');
    expect(collect($all->viewData('materials'))->pluck('description')->sort()->values()->all())->toBe(['Main label', 'Zipper']);

    $row = collect($all->viewData('materials'))->firstWhere('description', 'Zipper');
    expect($row)->toMatchArray(['type' => 'TRIMS', 'sap_code' => 'Z1', 'color' => 'RED', 'uom' => 'PCS'])->and((float) $row['consumption'])->toBe(1.0)->and((float) $row['wastage'])->toBe(5.0);

    $one = $this->actingAs($merchant)->get(route('merchant.bom.create', [$target, 'from' => $source->id, 'line' => $whiteLine]))->assertOk()->assertSee('Copied 1 material(s)');
    expect(collect($one->viewData('materials'))->pluck('description')->all())->toBe(['Zipper']);

    // The copy card lists this buyer's styles with a BOM; copying from the style itself is allowed.
    expect($all->viewData('sources')->pluck('style_no')->all())->toBe(['748268'])->and($all->viewData('sources')->first()->orderLines)->toHaveCount(2);
    $this->actingAs($merchant)->get(route('merchant.bom.create', $source))->assertOk()->assertSee('(this style)')->assertSee('Copy Materials');

    // Nothing but the copy is written until Create BOM is pressed; then the copied rows go in as usual.
    expect(OrderLine::where('mother_style_id', $target->id)->count())->toBe(0);
    $this->actingAs($merchant)->post(route('merchant.bom.store', $target), [
        'lines' => [['color' => 'GREEN', 'po' => 'G1', 'qty' => 300, 'child' => '']],
        'materials' => collect($all->viewData('materials'))->map(fn ($m) => array_map(fn ($v) => $v ?? '', $m))->all(),
    ])->assertSessionHas('success', '2 BOM line(s) created.');
    expect(BomItem::whereHas('orderLine', fn ($q) => $q->where('mother_style_id', $target->id))->count())->toBe(2);
});

it('will not copy from another buyer, an unknown style, or a style with no materials', function () {
    bbHeaders();
    $mine = bbStyle();
    $theirs = bbStyle(Buyer::create(['buyer_name' => 'Other', 'is_active' => true]));
    $merchant = bbUser();
    bbBuild($merchant, $theirs, 'WHITE', bbMaterial('Secret trim', 'S1'));

    $foreign = $this->actingAs($merchant)->get(route('merchant.bom.create', [$mine, 'from' => $theirs->id]))->assertOk()->assertSee('not available to copy from')->assertDontSee('Secret trim');
    expect(collect($foreign->viewData('materials'))->pluck('description')->filter()->all())->toBe([]);

    $this->actingAs($merchant)->get(route('merchant.bom.create', [$mine, 'from' => 99999]))->assertOk()->assertSee('not available to copy from');

    $emptySource = MotherStyle::create(['buyer_id' => $mine->buyer_id, 'buyer_season_id' => $mine->buyer_season_id, 'style_no' => 'EMPTY']);
    $this->actingAs($merchant)->get(route('merchant.bom.create', [$mine, 'from' => $emptySource->id]))->assertOk()->assertSee('has no materials to copy');
});

it('suggests materials from past BOMs of the same buyer only, newest first, one per material', function () {
    bbHeaders();
    $style = bbStyle();
    $other = bbStyle(Buyer::create(['buyer_name' => 'Other', 'is_active' => true]));
    $merchant = bbUser();

    bbBuild($merchant, $style, 'WHITE', bbMaterial('Metal Zipper', 'Z1', 1.0, 5));
    bbBuild($merchant, $style, 'BLACK', bbMaterial('Metal Zipper', 'Z1', 1.2, 8));        // newer figures for the same material
    bbBuild($merchant, $style, 'RED', bbMaterial('Plastic Zipper', 'Z2', 1.0, 0));
    bbBuild($merchant, $other, 'WHITE', bbMaterial('Zipper of another buyer', 'ZX'));

    $url = route('merchant.bom.suggest', $style);

    $hits = $this->actingAs($merchant)->getJson($url . '?q=zip')->assertOk()->json();
    expect(collect($hits)->pluck('description')->all())->toBe(['Plastic Zipper', 'Metal Zipper']);
    $metal = collect($hits)->firstWhere('description', 'Metal Zipper');
    expect($metal)->toMatchArray(['sap_code' => 'Z1', 'type' => 'TRIMS', 'uom' => 'PCS'])->and((float) $metal['consumption'])->toBe(1.2)->and((float) $metal['wastage'])->toBe(8.0);

    expect(collect($this->actingAs($merchant)->getJson($url . '?q=ZIP')->json())->count())->toBe(2);                 // any capitalisation
    expect(collect($this->actingAs($merchant)->getJson($url . '?q=Z2')->json())->pluck('description')->all())->toBe(['Plastic Zipper']);  // by SAP code
    expect($this->actingAs($merchant)->getJson($url . '?q=z')->json())->toBe([]);                                         // too short
    expect($this->actingAs($merchant)->getJson($url . '?q=%25%25')->json())->toBe([]);                                    // a typed "%%" is not a wildcard
    expect($this->actingAs($merchant)->getJson($url . '?q=another')->json())->toBe([]);                                   // other buyer's material
});

it('keeps the suggestions to Merchandising and Admin of that buyer', function () {
    bbHeaders();
    $style = bbStyle();
    $url = route('merchant.bom.suggest', $style);

    $this->actingAs(bbUser('admin'))->getJson($url . '?q=zip')->assertOk();
    foreach (['management', 'store', 'supply_chain'] as $role) {
        $this->actingAs(bbUser($role))->getJson($url . '?q=zip')->assertForbidden();
    }

    $mine = Buyer::create(['buyer_name' => 'Mine', 'is_active' => true]);
    $scoped = User::factory()->create(['buyer_id' => $mine->id, 'status' => 1])->assignRole('merchant');
    $scoped->buyers()->attach($mine->id);
    $this->actingAs($scoped->fresh())->getJson($url . '?q=zip')->assertForbidden();
});

it('wires the suggestion fields into the material rows', function () {
    bbHeaders();
    $style = bbStyle();

    $this->actingAs(bbUser())->get(route('merchant.bom.create', $style))->assertOk()
        ->assertSee('data-suggest="description"', false)->assertSee('data-suggest="sap"', false)->assertSee('id="matSuggest"', false)
        ->assertDontSee('Start from an earlier BOM');   // nothing to copy from yet
});
