<?php

use App\Models\BomItem;
use App\Models\Buyer;
use App\Models\BuyerSeason;
use App\Models\Costing;
use App\Models\ExcelFile;
use App\Models\ExcelRow;
use App\Models\MotherStyle;
use App\Models\OrderLine;
use App\Models\Sample;
use App\Models\SampleType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/** The Style Library list as a standard style master: colours, POs, quantity, ship date, materials, costing, samples. */
beforeEach(function () {
    Carbon::setTestNow('2026-11-10 09:00:00');
    $this->merchant = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('merchant', 'web'));
    $this->buyer = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    $this->season = BuyerSeason::create(['buyer_id' => $this->buyer->id, 'code' => 'SP27']);
    $this->mother = MotherStyle::create(['buyer_id' => $this->buyer->id, 'buyer_season_id' => $this->season->id, 'style_no' => '748268', 'product_group' => 'DENIM']);
    $this->empty = MotherStyle::create(['buyer_id' => $this->buyer->id, 'buyer_season_id' => $this->season->id, 'style_no' => '999000']);
    $this->file = ExcelFile::create(['file_name' => 'a.xlsx', 'original_file_name' => 'a.xlsx', 'file_path' => 'a', 'status' => 'processing', 'uploaded_by' => $this->merchant->id, 'buyer_id' => $this->buyer->id]);
});
afterEach(fn () => Carbon::setTestNow());

function slLine($test, string $key, string $color, string $po, float $qty, string $ship): OrderLine
{
    return OrderLine::create(['excel_file_id' => $test->file->id, 'line_key' => $key, 'buyer_id' => $test->buyer->id, 'mother_style_id' => $test->mother->id,
        'style_no' => '748268', 'gmts_color' => $color, 'customer_po_no' => $po, 'ship_date' => $ship, 'order_qty' => $qty]);
}

it('shows each style with its colours, POs, quantity, next ship date and materials', function () {
    $a = slLine($this, 'a', 'WHITE', 'P1', 1000, '2026-12-10');
    slLine($this, 'b', 'BLACK', 'P1', 500, '2026-11-20');
    slLine($this, 'c', 'white', 'p1', 1000, '2026-12-10');      // the same colour / PO in a second file: counted once
    slLine($this, 'd', 'WHITE', 'P2', 200, '2026-10-01');       // a passed date does not win while a later one is ahead
    foreach (['Zipper', 'Button'] as $i => $name) {
        $row = ExcelRow::create(['excel_file_id' => $this->file->id, 'row_number' => $i + 1]);
        BomItem::create(['order_line_id' => $a->id, 'excel_row_id' => $row->id, 'material_description' => $name]);
    }

    $stats = $this->actingAs($this->merchant)->get(route('merchant.styles.index', ['buyer' => $this->buyer->id, 'season' => $this->season->id]))
        ->assertOk()->viewData('stats');

    expect($stats[$this->mother->id])->toMatchArray(['colours' => 2, 'pos' => 2, 'qty' => 1700.0, 'materials' => 2, 'ship_passed' => false, 'has_costing' => false])
        ->and($stats[$this->mother->id]['ship']->toDateString())->toBe('2026-11-20')
        ->and($stats[$this->empty->id])->toMatchArray(['colours' => 0, 'pos' => 0, 'qty' => 0.0, 'materials' => 0, 'ship' => null]);
});

it('shows the final costing margin and the open and late samples of a style', function () {
    slLine($this, 'a', 'WHITE', 'P1', 1000, '2026-12-10');
    Costing::create(['mother_style_id' => $this->mother->id, 'mode' => 'buyer', 'revision' => 1, 'status' => 'final', 'quoted_price' => 8, 'total_cost' => 6, 'created_by' => $this->merchant->id]);
    $type = SampleType::first();
    Sample::create(['mother_style_id' => $this->mother->id, 'sample_type_id' => $type->id, 'qty' => 1, 'status' => 'requested', 'required_date' => '2026-11-01']);   // late
    Sample::create(['mother_style_id' => $this->mother->id, 'sample_type_id' => $type->id, 'qty' => 1, 'status' => 'sent', 'required_date' => '2026-12-01']);        // open
    Sample::create(['mother_style_id' => $this->mother->id, 'sample_type_id' => $type->id, 'qty' => 1, 'status' => 'approved', 'required_date' => '2026-10-01']);    // closed

    $page = $this->actingAs($this->merchant)->get(route('merchant.styles.index', ['buyer' => $this->buyer->id, 'season' => $this->season->id]))->assertOk();
    $s = $page->viewData('stats')[$this->mother->id];
    expect($s)->toMatchArray(['has_costing' => true, 'margin' => 25.0, 'open_samples' => 2, 'late_samples' => 1]);
    $page->assertSee('25% margin')->assertSee('2 open')->assertSee('1 late')->assertSee('1,000')->assertSee('10 Dec 2026');
});

it('describes the library as one standard style master, not as the buyer folders', function () {
    $this->actingAs($this->merchant)->get(route('merchant.styles.index'))->assertOk()
        ->assertSee('Your style master')->assertDontSee('buyer folders')->assertSee('Colours')->assertSee('Order qty')->assertSee('Ship date');
});
