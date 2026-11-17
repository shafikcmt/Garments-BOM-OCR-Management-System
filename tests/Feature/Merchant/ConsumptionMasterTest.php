<?php

use App\Models\BomItem;
use App\Models\Buyer;
use App\Models\BuyerSeason;
use App\Models\ConsumptionMaster;
use App\Models\ExcelFile;
use App\Models\ExcelRow;
use App\Models\MotherStyle;
use App\Models\OrderLine;
use App\Models\User;
use Spatie\Permission\Models\Role;

/** Consumption master (M-5 slice 1): standard materials per product group, used by Create BOM. */
beforeEach(function () {
    $this->merchant = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('merchant', 'web'));
    $this->admin = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('admin', 'web'));
    $this->buyer = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    $this->season = BuyerSeason::create(['buyer_id' => $this->buyer->id, 'code' => 'SP27']);
    $this->mother = MotherStyle::create(['buyer_id' => $this->buyer->id, 'buyer_season_id' => $this->season->id, 'style_no' => '748268', 'product_group' => 'Jacket']);
});

it('adds, edits and deletes a buyer material; common rows are Admin only', function () {
    $this->actingAs($this->merchant)->post(route('merchant.consumption.store'), [
        'buyer_id' => $this->buyer->id, 'product_group' => 'Jacket', 'material_description' => 'Shell fabric', 'consumption' => '1.35', 'wastage_percent' => 5, 'uom' => 'Yds',
    ])->assertSessionHas('success');
    $row = ConsumptionMaster::firstOrFail();
    expect((float) $row->consumption)->toBe(1.35);

    $this->put(route('merchant.consumption.update', $row), ['buyer_id' => $this->buyer->id, 'product_group' => 'Jacket', 'material_description' => 'Shell fabric', 'consumption' => '1.4'])
        ->assertSessionHas('success');
    expect((float) $row->fresh()->consumption)->toBe(1.4);

    // A common (all buyers) row is not the merchant's to add.
    $this->post(route('merchant.consumption.store'), ['product_group' => 'Jacket', 'material_description' => 'Thread', 'consumption' => 1])->assertForbidden();
    $this->actingAs($this->admin)->post(route('merchant.consumption.store'), ['product_group' => 'Jacket', 'material_description' => 'Thread', 'consumption' => 1])->assertSessionHas('success');

    $this->actingAs($this->merchant)->delete(route('merchant.consumption.destroy', $row))->assertSessionHas('success');
    expect(ConsumptionMaster::count())->toBe(1);
    $common = ConsumptionMaster::first();
    $this->delete(route('merchant.consumption.destroy', $common))->assertForbidden();
});

it('picks the rows for the style group, buyer first, and rejects bad input', function () {
    ConsumptionMaster::create(['buyer_id' => null, 'product_group' => 'Jacket', 'material_description' => 'Shell fabric', 'consumption' => 1.0]);
    ConsumptionMaster::create(['buyer_id' => $this->buyer->id, 'product_group' => 'Jacket', 'material_description' => 'Shell fabric', 'consumption' => 1.5, 'wastage_percent' => 4]);
    ConsumptionMaster::create(['buyer_id' => $this->buyer->id, 'product_group' => null, 'material_description' => 'Poly bag', 'consumption' => 1]);
    ConsumptionMaster::create(['buyer_id' => $this->buyer->id, 'product_group' => 'Trouser', 'material_description' => 'Zipper', 'consumption' => 1]);

    $rows = ConsumptionMaster::rowsForStyle($this->mother);
    expect(collect($rows)->pluck('description')->all())->toEqualCanonicalizing(['Shell fabric', 'Poly bag'])
        ->and(collect($rows)->firstWhere('description', 'Shell fabric')['consumption'])->toBe(1.5);

    $this->actingAs($this->merchant)->post(route('merchant.consumption.store'), ['buyer_id' => $this->buyer->id, 'material_description' => '', 'consumption' => -1])
        ->assertSessionHasErrors(['material_description', 'consumption']);
});

it('fills Create BOM from the master without saving anything', function () {
    ConsumptionMaster::create(['buyer_id' => $this->buyer->id, 'product_group' => 'Jacket', 'material_description' => 'Shell fabric', 'consumption' => 1.5, 'wastage_percent' => 4, 'uom' => 'Yds']);

    $page = $this->actingAs($this->merchant)->get(route('merchant.bom.create', ['mother' => $this->mother, 'master' => 1]))->assertOk()
        ->assertSee('Filled 1 material(s) from the Consumption Master');
    expect($page->viewData('materials')[0])->toMatchArray(['description' => 'Shell fabric', 'consumption' => 1.5, 'wastage' => 4.0]);
    expect(BomItem::count())->toBe(0);

    $this->get(route('merchant.bom.create', $this->mother))->assertOk()->assertSee('1 standard material(s)');
});

it('learns the master from a style BOM once, and keeps other roles out', function () {
    $file = ExcelFile::create(['file_name' => 'c.xlsx', 'original_file_name' => 'c.xlsx', 'file_path' => 'c', 'status' => 'processing', 'uploaded_by' => $this->merchant->id, 'buyer_id' => $this->buyer->id]);
    $line = OrderLine::create(['excel_file_id' => $file->id, 'line_key' => 'k', 'buyer_id' => $this->buyer->id, 'mother_style_id' => $this->mother->id, 'style_no' => '748268', 'gmts_color' => 'WHITE', 'order_qty' => 100]);
    $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => 1]);
    BomItem::create(['order_line_id' => $line->id, 'excel_row_id' => $row->id, 'material_description' => 'Shell fabric', 'uom' => 'Yds', 'initial_consumption' => 1.2, 'wastage_percent' => 0.05]);

    $this->actingAs($this->merchant)->post(route('merchant.consumption.from_style'), ['mother_style_id' => $this->mother->id])->assertSessionHas('success');
    $m = ConsumptionMaster::firstOrFail();
    expect($m->product_group)->toBe('Jacket')->and((float) $m->consumption)->toBe(1.2)->and((float) $m->wastage_percent)->toBe(5.0);

    $this->post(route('merchant.consumption.from_style'), ['mother_style_id' => $this->mother->id])->assertSessionHas('warning');
    expect(ConsumptionMaster::count())->toBe(1);

    $store = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('store', 'web'));
    $this->actingAs($store)->get(route('merchant.consumption.index'))->assertForbidden();
    $mgmt = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('management', 'web'));
    $this->actingAs($mgmt)->get(route('merchant.consumption.index'))->assertOk()->assertSee('Shell fabric');
});

it('copies a style BOM even when some lines have no description or very long text', function () {
    $file = ExcelFile::create(['file_name' => 'c.xlsx', 'original_file_name' => 'c.xlsx', 'file_path' => 'c', 'status' => 'processing', 'uploaded_by' => $this->merchant->id, 'buyer_id' => $this->buyer->id]);
    $line = OrderLine::create(['excel_file_id' => $file->id, 'line_key' => 'k', 'buyer_id' => $this->buyer->id, 'mother_style_id' => $this->mother->id, 'style_no' => '748268', 'gmts_color' => 'WHITE', 'order_qty' => 100]);
    $bom = fn (int $n, array $over) => BomItem::create($over + ['order_line_id' => $line->id, 'excel_row_id' => ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => $n])->id, 'initial_consumption' => 1.0]);

    $bom(1, ['material_description' => null]);                                   // no description: skipped, not a crash
    $bom(2, ['material_description' => 'Shell fabric', 'material_type' => str_repeat('T', 90), 'uom' => 'Yards of fabric roll', 'sap_code' => str_repeat('9', 80)]);
    $bom(3, ['material_description' => 'Thread', 'initial_consumption' => 2.5]);

    $this->actingAs($this->merchant)->post(route('merchant.consumption.from_style'), ['mother_style_id' => $this->mother->id])
        ->assertSessionHas('success');

    expect(ConsumptionMaster::count())->toBe(2);
    $shell = ConsumptionMaster::where('material_description', 'Shell fabric')->firstOrFail();
    expect(mb_strlen($shell->material_type))->toBe(60)->and(mb_strlen($shell->uom))->toBe(20)->and(mb_strlen($shell->sap_code))->toBe(60);
});
