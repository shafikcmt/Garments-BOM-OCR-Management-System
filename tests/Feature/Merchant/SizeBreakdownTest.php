<?php

use App\Models\Buyer;
use App\Models\BuyerSeason;
use App\Models\ExcelFile;
use App\Models\MotherStyle;
use App\Models\OrderLine;
use App\Models\OrderSizeQuantity;
use App\Models\SizeSet;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Colour x size breakdown: size sets are data per buyer, quantities are kept
 * per style + colour + PO and checked against the order quantity.
 */
beforeEach(function () {
    $this->merchant = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('merchant', 'web'));
    $this->buyer = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    $season = BuyerSeason::create(['buyer_id' => $this->buyer->id, 'code' => 'SP27']);
    $this->mother = MotherStyle::create(['buyer_id' => $this->buyer->id, 'buyer_season_id' => $season->id, 'style_no' => '748268']);
    $this->file = ExcelFile::create(['file_name' => 's.xlsx', 'original_file_name' => 's.xlsx', 'file_path' => 's', 'uploaded_by' => $this->merchant->id, 'buyer_id' => $this->buyer->id]);
    foreach ([['BLACK', 'C1', 300], ['NAVY', 'C2', 200]] as [$color, $po, $qty]) {
        OrderLine::create(['excel_file_id' => $this->file->id, 'line_key' => md5($color), 'buyer_id' => $this->buyer->id, 'buyer_season_id' => $season->id,
            'mother_style_id' => $this->mother->id, 'style_no' => '748268', 'gmts_color' => $color, 'customer_po_no' => $po, 'order_qty' => $qty]);
    }
});

it('makes a size set for the buyer from typed sizes and uses it on the style', function () {
    $this->actingAs($this->merchant)->post(route('merchant.sizes.sets.store', $this->mother), ['name' => 'Missy', 'sizes' => 'xs, s, m, l'])
        ->assertRedirect(route('merchant.sizes.show', $this->mother));

    $set = SizeSet::first();
    expect($set->sizes)->toBe(['XS', 'S', 'M', 'L'])->and($set->buyer_id)->toBe($this->buyer->id)
        ->and($this->mother->fresh()->size_set_id)->toBe($set->id);
});

it('saves quantities per colour and PO and warns when the total differs from the order', function () {
    $set = SizeSet::create(['buyer_id' => $this->buyer->id, 'name' => 'Missy', 'sizes' => ['S', 'M', '2.5']]);
    $this->mother->update(['size_set_id' => $set->id]);

    $this->actingAs($this->merchant)->put(route('merchant.sizes.update', $this->mother), [
        'sizes' => ['S', 'M', '2.5'],
        'rows' => [
            ['color' => 'BLACK', 'po' => 'C1', 'qty' => [100, 150, 50]],   // 300 = order
            ['color' => 'NAVY', 'po' => 'C2', 'qty' => [80, '', 0]],        // 80 vs 200
        ],
    ])->assertRedirect()->assertSessionHas('success')->assertSessionHas('warning', fn ($w) => str_contains($w, 'NAVY C2') && ! str_contains($w, 'BLACK'));

    expect(OrderSizeQuantity::where('gmts_color', 'BLACK')->pluck('qty', 'size')->map(fn ($q) => (float) $q)->all())
        ->toBe(['S' => 100.0, 'M' => 150.0, '2.5' => 50.0])
        ->and(OrderSizeQuantity::where('gmts_color', 'NAVY')->count())->toBe(1);

    $this->get(route('merchant.sizes.show', $this->mother))->assertOk()
        ->assertViewHas('rows', fn ($rows) => $rows[0]['total'] === 300.0)
        ->assertSee('Save Size Breakdown');
});

it('keeps the figures when the workspace re-creates the order line', function () {
    OrderSizeQuantity::create(['mother_style_id' => $this->mother->id, 'gmts_color' => 'BLACK', 'customer_po_no' => 'C1', 'size' => 'S', 'qty' => 10]);
    OrderLine::where('gmts_color', 'BLACK')->delete();
    OrderLine::create(['excel_file_id' => $this->file->id, 'line_key' => 'new', 'buyer_id' => $this->buyer->id, 'mother_style_id' => $this->mother->id,
        'style_no' => '748268', 'gmts_color' => 'Black', 'customer_po_no' => 'C1', 'order_qty' => 300]);

    $this->actingAs($this->merchant)->get(route('merchant.sizes.show', $this->mother))
        ->assertViewHas('rows', fn ($rows) => collect($rows)->firstWhere('po', 'C1')['sizes'] === ['S' => 10.0]);
});

it('lets management read but not change the breakdown', function () {
    $manager = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('management', 'web'));

    $this->actingAs($manager)->get(route('merchant.sizes.show', $this->mother))->assertOk()->assertDontSee('Save Size Breakdown');
    $this->actingAs($manager)->put(route('merchant.sizes.update', $this->mother), ['rows' => []])->assertForbidden();
});
