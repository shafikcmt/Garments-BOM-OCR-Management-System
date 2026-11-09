<?php

use App\Models\Buyer;
use App\Models\BuyerSeason;
use App\Models\Costing;
use App\Models\MotherStyle;
use App\Models\OrderSizeQuantity;
use App\Models\SizeSet;
use App\Models\StyleMaterialSize;
use App\Models\User;
use App\Services\SizeWiseCosting;
use Spatie\Permission\Models\Role;

/** Cost by size (M-4 slice 2): the costing recalculated with the Size-wise BOM consumption. */
beforeEach(function () {
    $this->merchant = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('merchant', 'web'));
    $buyer = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    $season = BuyerSeason::create(['buyer_id' => $buyer->id, 'code' => 'SP27']);
    $set = SizeSet::create(['buyer_id' => $buyer->id, 'name' => 'Letters', 'sizes' => ['S', 'M', 'L']]);
    $this->mother = MotherStyle::create(['buyer_id' => $buyer->id, 'buyer_season_id' => $season->id, 'style_no' => '748268', 'size_set_id' => $set->id]);

    // Fabric 1.0 yd + 5% waste at 4.00 = 4.20; trims 0.50; CM 2.00  => total 6.70, price 8.00
    $this->costing = Costing::create(['mother_style_id' => $this->mother->id, 'mode' => 'buyer', 'revision' => 1, 'currency' => 'USD', 'basis' => 'pc',
        'quoted_price' => 8, 'total_cost' => 6.70, 'created_by' => $this->merchant->id]);
    foreach ([['fabric', 'Shell fabric', 1, 5, 4, 4.2], ['trims', 'Trims', 1, 0, 0.5, 0.5], ['cm', 'CM', 1, 0, 2, 2]] as $i => [$head, $d, $q, $w, $r, $a]) {
        $this->costing->lines()->create(['head' => $head, 'description' => $d, 'qty' => $q, 'wastage_pct' => $w, 'rate' => $r, 'amount' => $a, 'position' => $i]);
    }
    foreach (['S' => 200, 'M' => 600, 'L' => 200] as $size => $qty) {
        OrderSizeQuantity::create(['mother_style_id' => $this->mother->id, 'gmts_color' => 'WHITE', 'customer_po_no' => 'P', 'size' => $size, 'qty' => $qty]);
    }
});

it('has nothing to show until a size has its own consumption', function () {
    expect(app(SizeWiseCosting::class)->forCosting($this->costing))->toBeNull();
    $this->actingAs($this->merchant)->get(route('costing.show', $this->mother))->assertOk()->assertDontSee('Cost by size');
});

it('recalculates only the matched line for the sizes that differ and averages by pieces', function () {
    // Fabric for L is 1.2 yd: 1.2 x 1.05 x 4 = 5.04 instead of 4.20 => L costs 7.54.
    StyleMaterialSize::create(['mother_style_id' => $this->mother->id, 'material_key' => 'shell fabric', 'material_name' => 'Shell fabric', 'size' => 'L', 'consumption' => 1.2]);

    $r = app(SizeWiseCosting::class)->forCosting($this->costing);
    $by = collect($r['sizes'])->keyBy('size');
    expect(array_keys($by->all()))->toBe(['S', 'M', 'L'])
        ->and($by['S']['cost'])->toBe(6.7)->and($by['S']['changed'])->toBe(0)
        ->and($by['L']['cost'])->toBe(7.54)->and($by['L']['difference'])->toBe(0.84)->and($by['L']['margin'])->toBe(5.8)
        // (200 x 6.70 + 600 x 6.70 + 200 x 7.54) / 1000 = 6.868
        ->and($r['average'])->toBe(6.868)->and($r['average_margin'])->toBe(14.2)
        ->and($r['unmatched'])->toBe([]);

    $this->actingAs($this->merchant)->get(route('costing.show', $this->mother))->assertOk()
        ->assertSee('Cost by size')->assertSee('Average by pieces')->assertSee('7.54');
});

it('uses 12 pieces for a dozen sheet and names a material no costing line matches', function () {
    $this->costing->update(['basis' => 'dz', 'total_cost' => 80.4]);
    $this->costing->lines()->where('head', 'fabric')->update(['qty' => 12, 'amount' => 50.4]);
    $this->costing->lines()->where('head', 'trims')->update(['qty' => 12, 'amount' => 6]);
    $this->costing->lines()->where('head', 'cm')->update(['qty' => 12, 'amount' => 24]);
    StyleMaterialSize::create(['mother_style_id' => $this->mother->id, 'material_key' => 'shell fabric', 'material_name' => 'Shell fabric', 'size' => 'L', 'consumption' => 1.2]);
    StyleMaterialSize::create(['mother_style_id' => $this->mother->id, 'material_key' => 'zipper', 'material_name' => 'Zipper', 'size' => 'M', 'consumption' => 1]);

    $r = app(SizeWiseCosting::class)->forCosting($this->costing->fresh());
    // 14.4 yd x 1.05 x 4 = 60.48; +10.08 over 50.40.
    expect(collect($r['sizes'])->firstWhere('size', 'L')['difference'])->toBe(10.08)->and($r['unmatched'])->toBe(['Zipper']);
});

it('is visible to management on the costing page', function () {
    StyleMaterialSize::create(['mother_style_id' => $this->mother->id, 'material_key' => 'shell fabric', 'material_name' => 'Shell fabric', 'size' => 'L', 'consumption' => 1.2]);
    $mgmt = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('management', 'web'));
    $this->actingAs($mgmt)->get(route('costing.show', $this->mother))->assertOk()->assertSee('Cost by size');
});
