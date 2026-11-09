<?php

use App\Models\Buyer;
use App\Models\BuyerSeason;
use App\Models\Costing;
use App\Models\ExcelFile;
use App\Models\Inquiry;
use App\Models\MotherStyle;
use App\Models\OrderLine;
use App\Models\User;
use App\Services\MerchantAnalytics;
use Spatie\Permission\Models\Role;

/** Merchandising analytics (M-9 slice 1): order value, margin by buyer / season, inquiry win rate. */
beforeEach(function () {
    $this->merchant = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('merchant', 'web'));
    $this->buyer = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    $this->season = BuyerSeason::create(['buyer_id' => $this->buyer->id, 'code' => 'SP27']);
    $this->file = ExcelFile::create(['file_name' => 'c.xlsx', 'original_file_name' => 'c.xlsx', 'file_path' => 'c', 'status' => 'processing', 'uploaded_by' => $this->merchant->id, 'buyer_id' => $this->buyer->id]);
});

function costedStyle($test, string $no, int $qty, float $price, float $cost, string $basis = 'pc', string $currency = 'USD', bool $final = true)
{
    $m = MotherStyle::create(['buyer_id' => $test->buyer->id, 'buyer_season_id' => $test->season->id, 'style_no' => $no]);
    OrderLine::create(['excel_file_id' => $test->file->id, 'line_key' => $no, 'buyer_id' => $test->buyer->id, 'mother_style_id' => $m->id,
        'style_no' => $no, 'gmts_color' => 'WHITE', 'customer_po_no' => 'P1', 'order_qty' => $qty]);
    Costing::create(['mother_style_id' => $m->id, 'mode' => 'buyer', 'revision' => 1, 'status' => $final ? 'final' : 'draft', 'basis' => $basis,
        'currency' => $currency, 'quoted_price' => $price, 'total_cost' => $cost, 'created_by' => $test->merchant->id]);

    return $m;
}

it('works out order value, cost and margin per buyer, per piece, and keeps currencies apart', function () {
    costedStyle($this, 'A', 1000, 8, 6);                        // value 8000, cost 6000
    costedStyle($this, 'B', 500, 96, 72, 'dz');                 // per dozen => 8 / 6 per piece: 4000 / 3000
    costedStyle($this, 'C', 100, 10, 9, 'pc', 'EUR');

    $d = app(MerchantAnalytics::class)->build([$this->buyer->id]);
    $usd = collect($d['rows'])->firstWhere('currency', 'USD');
    expect($usd)->toMatchArray(['group' => 'Ann Taylor', 'styles' => 2, 'qty' => 1500.0, 'revenue' => 12000.0, 'cost' => 9000.0, 'profit' => 3000.0, 'margin' => 25.0])
        ->and(collect($d['rows'])->firstWhere('currency', 'EUR')['revenue'])->toBe(1000.0)
        ->and($d['group_by'])->toBe('buyer');
});

it('leaves out styles with no final costing and counts them, and groups one buyer by season', function () {
    costedStyle($this, 'A', 1000, 8, 6);
    costedStyle($this, 'D', 400, 8, 6, 'pc', 'USD', false);     // draft only
    $fa = BuyerSeason::create(['buyer_id' => $this->buyer->id, 'code' => 'FA27']);
    $m = costedStyle($this, 'E', 200, 5, 4);
    $m->update(['buyer_season_id' => $fa->id]);

    $d = app(MerchantAnalytics::class)->build([$this->buyer->id], $this->buyer->id);
    expect($d['uncosted'])->toBe(1)->and($d['uncosted_qty'])->toBe(400.0)->and($d['group_by'])->toBe('season')
        ->and(collect($d['rows'])->pluck('group')->all())->toBe(['FA27', 'SP27'])
        ->and($d['styles'][0]['margin'])->toBe(20.0);          // lowest margin first
});

it('counts a colour / PO repeated in two files once', function () {
    $m = costedStyle($this, 'A', 1000, 8, 6);
    $f2 = ExcelFile::create(['file_name' => 'd.xlsx', 'original_file_name' => 'd.xlsx', 'file_path' => 'd', 'status' => 'processing', 'uploaded_by' => $this->merchant->id, 'buyer_id' => $this->buyer->id]);
    OrderLine::create(['excel_file_id' => $f2->id, 'line_key' => 'z', 'buyer_id' => $this->buyer->id, 'mother_style_id' => $m->id, 'style_no' => 'A', 'gmts_color' => 'white', 'customer_po_no' => 'p1', 'order_qty' => 1000]);

    expect(app(MerchantAnalytics::class)->build([$this->buyer->id])['rows'][0]['qty'])->toBe(1000.0);
});

it('shows the win rate and the page to merchant and management only for their buyers', function () {
    foreach (['won', 'won', 'lost', 'new'] as $s) {
        Inquiry::create(['buyer_id' => $this->buyer->id, 'style_ref' => 'S', 'received_date' => '2026-11-01', 'status' => $s]);
    }
    costedStyle($this, 'A', 1000, 8, 6);

    $this->actingAs($this->merchant)->get(route('merchant.analytics.index'))->assertOk()->assertSee('Ann Taylor')->assertSee('67%')->assertSee('25%');

    $mgmt = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('management', 'web'));
    $this->actingAs($mgmt)->get(route('merchant.analytics.index', ['buyer' => $this->buyer->id]))->assertOk()->assertSee('SP27');

    $store = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('store', 'web'));
    $this->actingAs($store)->get(route('merchant.analytics.index'))->assertForbidden();
});
