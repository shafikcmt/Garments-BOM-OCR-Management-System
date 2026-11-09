<?php

use App\Models\BomItem;
use App\Models\Buyer;
use App\Models\BuyerSeason;
use App\Models\Costing;
use App\Models\ExcelFile;
use App\Models\ExcelRow;
use App\Models\MaterialBulkIssue;
use App\Models\MaterialReceiving;
use App\Models\MotherStyle;
use App\Models\OrderLine;
use App\Models\User;
use App\Services\PostCosting;
use Spatie\Permission\Models\Role;

/** Material post-costing (final costing vs what Store received and issued). */
beforeEach(function () {
    $this->merchant = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('merchant', 'web'));
    $this->buyer = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    $season = BuyerSeason::create(['buyer_id' => $this->buyer->id, 'code' => 'SP27']);
    $this->mother = MotherStyle::create(['buyer_id' => $this->buyer->id, 'buyer_season_id' => $season->id, 'style_no' => '748268']);
    $this->file = ExcelFile::create(['file_name' => 'c.xlsx', 'original_file_name' => 'c.xlsx', 'file_path' => 'c', 'status' => 'processing', 'uploaded_by' => $this->merchant->id, 'buyer_id' => $this->buyer->id]);
    $this->line = OrderLine::create(['excel_file_id' => $this->file->id, 'line_key' => 'k', 'buyer_id' => $this->buyer->id, 'mother_style_id' => $this->mother->id,
        'style_no' => '748268', 'gmts_color' => 'WHITE', 'customer_po_no' => 'P1', 'order_qty' => 1000]);
    $this->rows = [];
    foreach (['Zipper', 'Button', 'Label'] as $i => $name) {
        $row = ExcelRow::create(['excel_file_id' => $this->file->id, 'row_number' => $i + 1]);
        BomItem::create(['order_line_id' => $this->line->id, 'excel_row_id' => $row->id, 'material_description' => $name, 'uom' => 'Pcs']);
        $this->rows[$name] = $row;
    }
});

function grn($test, string $material, float $qty, ?float $price, string $no)
{
    return MaterialReceiving::create(['excel_file_id' => $test->file->id, 'excel_row_id' => $test->rows[$material]->id, 'grn_no' => $no,
        'receive_date' => '2026-11-01', 'grn_date' => '2026-11-01', 'source_type' => MaterialReceiving::SOURCE_BOOKING, 'qty' => $qty,
        'unit_price' => $price, 'invoice_value' => $price === null ? null : round($qty * $price, 4)]);
}

function issue($test, string $material, float $qty, string $no)
{
    return MaterialBulkIssue::create(['excel_file_id' => $test->file->id, 'excel_row_id' => $test->rows[$material]->id, 'issue_no' => $no,
        'issue_date' => '2026-11-05', 'bulk_qty' => $qty, 'sample_qty' => 0, 'liability_qty' => 0, 'dead_qty' => 0]);
}

function finalCosting($test, string $basis = 'pc', float $fabric = 4.2, float $trims = 0.5)
{
    $c = Costing::create(['mother_style_id' => $test->mother->id, 'mode' => 'buyer', 'revision' => 1, 'status' => 'final', 'basis' => $basis, 'currency' => 'USD',
        'quoted_price' => 8, 'total_cost' => $fabric + $trims, 'created_by' => $test->merchant->id]);
    $c->lines()->create(['head' => 'fabric', 'description' => 'Shell', 'qty' => 1, 'rate' => $fabric, 'amount' => $fabric]);
    $c->lines()->create(['head' => 'trims', 'description' => 'Trims', 'qty' => 1, 'rate' => $trims, 'amount' => $trims]);

    return $c;
}

it('compares the material budget with what was received and issued, valued at the booked price', function () {
    finalCosting($this);                                  // material 4.70 per piece x 1000 = 4700
    grn($this, 'Zipper', 1000, 0.5, 'G1');                // 500
    grn($this, 'Zipper', 200, 0.6, 'G2');                 // 120  => 1200 pcs, 620, average 0.5167
    grn($this, 'Button', 500, null, 'G3');                // no price
    issue($this, 'Zipper', 600, 'I1');                    // 600 x 0.5167 = 310
    issue($this, 'Button', 100, 'I2');
    issue($this, 'Label', 50, 'I3');                      // issued, never received

    $d = app(PostCosting::class)->forStyle($this->mother);
    expect($d['order_qty'])->toBe(1000.0)
        ->and(round($d['budget'], 2))->toBe(4700.0)
        ->and(round($d['received_value'], 2))->toBe(620.0)
        ->and(round($d['issued_value'], 2))->toBe(310.0)
        ->and(round($d['in_stock_value'], 2))->toBe(310.0)
        ->and(round($d['variance'], 2))->toBe(-4080.0)
        ->and($d['received_pct'])->toBe(13.2)
        ->and($d['unpriced'])->toBe(1)->and($d['issued_without_receipt'])->toBe(1);

    $zip = collect($d['materials'])->firstWhere('name', 'Zipper');
    expect($zip['received_qty'])->toBe(1200.0)->and(round($zip['received_value'], 2))->toBe(620.0)->and($zip['issued_qty'])->toBe(600.0);

    $this->actingAs($this->merchant)->get(route('merchant.postcosting.show', $this->mother))->assertOk()
        ->assertSee('Material Post-costing')->assertSee('No price')->assertSee('Issued, not received')->assertSee('below')->assertSee('Not included yet');
});

it('divides a dozen costing by 12 and still shows receipts when there is no final costing', function () {
    finalCosting($this, 'dz', 50.4, 6);                   // per piece 4.70 => budget 4700
    expect(round(app(PostCosting::class)->forStyle($this->mother)['budget'], 2))->toBe(4700.0);

    Costing::query()->delete();
    grn($this, 'Zipper', 100, 2, 'G9');
    $d = app(PostCosting::class)->forStyle($this->mother);
    expect($d['costing'])->toBeNull()->and($d['budget'])->toBeNull()->and($d['variance'])->toBeNull()->and(round($d['received_value'], 2))->toBe(200.0);

    $this->actingAs($this->merchant)->get(route('merchant.postcosting.show', $this->mother))->assertOk()->assertSee('final buyer pre-costing')->assertSee('has no');
});

it('says so for a style with no BOM and limits the page to people who may see the buyer', function () {
    $empty = MotherStyle::create(['buyer_id' => $this->buyer->id, 'buyer_season_id' => $this->mother->buyer_season_id, 'style_no' => '999']);
    $this->actingAs($this->merchant)->get(route('merchant.postcosting.show', $empty))->assertOk()->assertSee('nothing to compare');

    $mgmt = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('management', 'web'));
    $this->actingAs($mgmt)->get(route('merchant.postcosting.show', $this->mother))->assertOk();

    $other = Buyer::create(['buyer_name' => 'Macys', 'is_active' => true]);
    $confined = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('merchant', 'web'));
    $confined->buyers()->sync([$other->id]);
    $this->actingAs($confined)->get(route('merchant.postcosting.show', $this->mother))->assertForbidden();

    $store = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('store', 'web'));
    $this->actingAs($store)->get(route('merchant.postcosting.show', $this->mother))->assertForbidden();
});
