<?php

use App\Models\Buyer;
use App\Models\BuyerSeason;
use App\Models\Costing;
use App\Models\CostingTemplate;
use App\Models\MotherStyle;
use App\Models\User;
use App\Services\CostingTemplates;
use Spatie\Permission\Models\Role;

/** Costing templates (M-10 slice 1): a ready cost sheet per product group. */
beforeEach(function () {
    $this->merchant = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('merchant', 'web'));
    $this->admin = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('admin', 'web'));
    $this->buyer = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    $season = BuyerSeason::create(['buyer_id' => $this->buyer->id, 'code' => 'SP27']);
    $this->mother = MotherStyle::create(['buyer_id' => $this->buyer->id, 'buyer_season_id' => $season->id, 'style_no' => '748268', 'product_group' => 'Jacket']);
});

function tplPayload(array $extra = []): array
{
    return $extra + ['name' => 'Jacket standard', 'buyer_id' => test()->buyer->id, 'product_group' => 'Jacket', 'currency' => 'EUR', 'basis' => 'pc', 'is_active' => 1, 'lines' => [
        ['head' => 'fabric', 'description' => 'Shell fabric', 'qty' => 1.5, 'unit' => 'yds', 'wastage_pct' => 5, 'rate' => 4],
        ['head' => 'cm', 'description' => 'CM', 'qty' => 1, 'unit' => '', 'wastage_pct' => 0, 'rate' => 2],
    ]];
}

it('saves a template with its lines and edits it', function () {
    $this->actingAs($this->merchant)->post(route('merchant.costing_templates.store'), tplPayload())->assertRedirect(route('merchant.costing_templates.index'));
    $t = CostingTemplate::with('lines')->firstOrFail();
    expect($t->lines)->toHaveCount(2)->and($t->currency)->toBe('EUR');

    $this->get(route('merchant.costing_templates.index'))->assertOk()->assertSee('Jacket standard');
    $this->get(route('merchant.costing_templates.edit', $t))->assertOk()->assertSee('Shell fabric');

    $this->put(route('merchant.costing_templates.update', $t), tplPayload(['name' => 'Jacket v2', 'lines' => [['head' => 'trims', 'description' => 'Zip', 'qty' => 1, 'rate' => 0.5]]]))->assertRedirect();
    expect($t->fresh()->name)->toBe('Jacket v2')->and($t->fresh()->lines)->toHaveCount(1);

    $this->post(route('merchant.costing_templates.store'), tplPayload(['lines' => [['head' => 'bogus', 'rate' => -1]]]))->assertSessionHasErrors(['lines.0.head', 'lines.0.rate']);
});

it('starts a costing from a template, with amounts and total worked out', function () {
    $this->actingAs($this->merchant)->post(route('merchant.costing_templates.store'), tplPayload());
    $t = CostingTemplate::firstOrFail();

    $this->post(route('costing.start', $this->mother), ['mode' => 'buyer', 'template_id' => $t->id])->assertRedirect();
    $c = Costing::with('lines')->firstOrFail();
    // 1.5 x 1.05 x 4 = 6.30; CM 2.00
    expect($c->currency)->toBe('EUR')->and($c->lines)->toHaveCount(2)->and((float) $c->total_cost)->toBe(8.3)
        ->and((float) $c->lines->firstWhere('head', 'fabric')->amount)->toBe(6.3);

    // Changing the template later does not touch the costing.
    $t->lines()->delete();
    expect($c->fresh()->lines)->toHaveCount(2);
});

it('offers the best fitting template first and refuses one that does not fit', function () {
    $other = Buyer::create(['buyer_name' => 'Macys', 'is_active' => true]);
    $make = fn ($name, $buyer, $group) => CostingTemplate::create(['name' => $name, 'buyer_id' => $buyer, 'product_group' => $group, 'currency' => 'USD', 'basis' => 'pc', 'is_active' => true]);
    $general = $make('General', null, null);
    $groupAll = $make('Jacket all buyers', null, 'Jacket');
    $buyerOnly = $make('Ann general', $this->buyer->id, null);
    $best = $make('Ann Jacket', $this->buyer->id, 'Jacket');
    $make('Trouser', $this->buyer->id, 'Trouser');
    $make('Macys Jacket', $other->id, 'Jacket');
    $inactive = $make('Off', $this->buyer->id, 'Jacket');
    $inactive->update(['is_active' => false]);

    $names = app(CostingTemplates::class)->forStyle($this->mother)->pluck('name')->all();
    expect($names)->toBe(['Ann Jacket', 'Jacket all buyers', 'Ann general', 'General']);

    $trouser = CostingTemplate::where('name', 'Trouser')->first();
    $this->actingAs($this->merchant)->post(route('costing.start', $this->mother), ['mode' => 'buyer', 'template_id' => $trouser->id])->assertSessionHas('warning');
    expect(Costing::count())->toBe(0);
});

it('saves a costing as a template and keeps common templates for Admin', function () {
    $this->actingAs($this->merchant)->post(route('costing.start', $this->mother), ['mode' => 'buyer']);
    $c = Costing::firstOrFail();

    $this->post(route('merchant.costing_templates.from_costing', $c), ['name' => 'From sheet', 'for_this_buyer' => 1])->assertSessionHas('success');
    $t = CostingTemplate::firstOrFail();
    expect($t->buyer_id)->toBe($this->buyer->id)->and($t->product_group)->toBe('Jacket')->and($t->lines()->count())->toBe($c->lines()->count());

    // A template for all buyers is Admin's.
    $this->post(route('merchant.costing_templates.store'), tplPayload(['buyer_id' => '']))->assertForbidden();
    $this->actingAs($this->admin)->post(route('merchant.costing_templates.store'), tplPayload(['buyer_id' => '']))->assertRedirect();

    $mgmt = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('management', 'web'));
    $this->actingAs($mgmt)->get(route('merchant.costing_templates.index'))->assertOk();
    $this->post(route('merchant.costing_templates.store'), tplPayload())->assertForbidden();
});
