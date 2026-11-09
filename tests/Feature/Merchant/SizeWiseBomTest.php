<?php

use App\Models\BomItem;
use App\Models\Buyer;
use App\Models\BuyerSeason;
use App\Models\ExcelFile;
use App\Models\ExcelRow;
use App\Models\MotherStyle;
use App\Models\OrderLine;
use App\Models\OrderSizeQuantity;
use App\Models\SizeSet;
use App\Models\StyleMaterialSize;
use App\Models\User;
use Spatie\Permission\Models\Role;

/** Size-wise BOM (M-4 slice 1): consumption per size and the requirement it gives. */
beforeEach(function () {
    $this->merchant = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('merchant', 'web'));
    $buyer = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    $season = BuyerSeason::create(['buyer_id' => $buyer->id, 'code' => 'SP27']);
    $set = SizeSet::create(['buyer_id' => $buyer->id, 'name' => 'Letters', 'sizes' => ['M', 'L']]);
    $this->mother = MotherStyle::create(['buyer_id' => $buyer->id, 'buyer_season_id' => $season->id, 'style_no' => '748268', 'size_set_id' => $set->id]);
    $file = ExcelFile::create(['file_name' => 'c.xlsx', 'original_file_name' => 'c.xlsx', 'file_path' => 'c', 'status' => 'processing',
        'uploaded_by' => $this->merchant->id, 'buyer_id' => $buyer->id]);
    $this->line = OrderLine::create(['excel_file_id' => $file->id, 'line_key' => 'k', 'buyer_id' => $buyer->id, 'mother_style_id' => $this->mother->id,
        'style_no' => '748268', 'gmts_color' => 'WHITE', 'customer_po_no' => 'PO-1', 'order_qty' => 1000]);
    $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => 1]);
    BomItem::create(['order_line_id' => $this->line->id, 'excel_row_id' => $row->id, 'sap_code' => 'S1', 'material_description' => 'Shell fabric', 'uom' => 'Yds',
        'initial_consumption' => 1, 'wastage_percent' => 0.05, 'consumption_incl_yy' => 1.05]);
    foreach (['M' => 600, 'L' => 400] as $size => $qty) {
        OrderSizeQuantity::create(['mother_style_id' => $this->mother->id, 'gmts_color' => 'WHITE', 'customer_po_no' => 'PO-1', 'size' => $size, 'qty' => $qty]);
    }
});

it('matches the workspace need until a size has its own consumption', function () {
    $m = $this->actingAs($this->merchant)->get(route('merchant.sizebom.show', $this->mother))->assertOk()
        ->assertSee('Size-wise BOM')->viewData('materials');

    expect($m)->toHaveCount(1)->and(round($m[0]['workspace_qty'], 2))->toBe(1050.0)->and(round($m[0]['size_wise_qty'], 2))->toBe(1050.0)
        ->and($m[0]['fallback_lines'])->toBe(0);
});

it('saves a size consumption, recalculates the need and clears a blank size', function () {
    $this->actingAs($this->merchant)->put(route('merchant.sizebom.update', $this->mother), [
        'sizes' => ['M', 'L'],
        'rows' => [['key' => 's1', 'name' => 'Shell fabric', 'cons' => [0 => '', 1 => '1.2']]],
    ])->assertSessionHas('success');

    expect(StyleMaterialSize::count())->toBe(1)->and(StyleMaterialSize::first()->size)->toBe('L');

    $m = $this->get(route('merchant.sizebom.show', $this->mother))->viewData('materials');
    // 600 x 1.0 x 1.05 + 400 x 1.2 x 1.05 = 1134 against 1050.
    expect(round($m[0]['size_wise_qty'], 2))->toBe(1134.0)->and(round($m[0]['difference'], 2))->toBe(84.0);

    $this->put(route('merchant.sizebom.update', $this->mother), ['sizes' => ['M', 'L'], 'rows' => [['key' => 's1', 'cons' => [0 => '', 1 => '']]]]);
    expect(StyleMaterialSize::count())->toBe(0);
});

it('falls back to order quantity when a colour has no size breakdown, and rejects bad input', function () {
    OrderSizeQuantity::query()->delete();
    $m = $this->actingAs($this->merchant)->get(route('merchant.sizebom.show', $this->mother))->viewData('materials');
    expect($m[0]['fallback_lines'])->toBe(1)->and(round($m[0]['size_wise_qty'], 2))->toBe(1050.0);

    $this->put(route('merchant.sizebom.update', $this->mother), ['sizes' => ['M'], 'rows' => [['key' => 's1', 'cons' => [0 => '-2']]]])->assertSessionHasErrors('rows.0.cons.0');
});

it('lets management read but not change, and keeps other roles out', function () {
    $mgmt = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('management', 'web'));
    $this->actingAs($mgmt)->get(route('merchant.sizebom.show', $this->mother))->assertOk();
    $this->put(route('merchant.sizebom.update', $this->mother), ['sizes' => ['M'], 'rows' => [['key' => 's1', 'cons' => [0 => '1']]]])->assertForbidden();

    $store = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('store', 'web'));
    $this->actingAs($store)->get(route('merchant.sizebom.show', $this->mother))->assertForbidden();
});
