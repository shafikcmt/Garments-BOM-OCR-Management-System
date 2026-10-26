<?php

use App\Models\Buyer;
use App\Models\BuyerSeason;
use App\Models\MotherStyle;
use App\Models\User;
use App\Support\StyleCodeParser;
use Spatie\Permission\Models\Role;

function styleMerchant(): User
{
    Role::findOrCreate('merchant', 'web');

    return User::factory()->create()->assignRole('merchant');
}

it('parses real style codes from the lot files', function () {
    expect(StyleCodeParser::parse('720193(PETITE)'))->toBe(['style_no' => '720193', 'child' => 'PETITE', 'kind' => 'variant']);
    expect(StyleCodeParser::parse('748268 (866317 )'))->toBe(['style_no' => '748268', 'child' => '866317', 'kind' => 'linked']);
    expect(StyleCodeParser::parse('882635'))->toBe(['style_no' => '882635', 'child' => null, 'kind' => null]);
    expect(StyleCodeParser::parseList("882635, 789055(CURVY)\n813132 (791366)"))->toHaveCount(3);
});

it('builds buyer, season, mother and child styles', function () {
    $buyer = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    $user = styleMerchant();

    $this->actingAs($user)->post(route('merchant.styles.seasons.store'), ['buyer_id' => $buyer->id, 'code' => 'sp27'])->assertRedirect();
    $season = BuyerSeason::firstOrFail();
    expect($season->code)->toBe('SP27');

    $this->actingAs($user)->post(route('merchant.styles.mothers.store', $season), ['codes' => '748268 (866317), 720193(PETITE)', 'product_group' => 'DENIM'])->assertRedirect();

    expect(MotherStyle::count())->toBe(2);
    expect(MotherStyle::where('style_no', '748268')->first()->children()->pluck('style_no')->all())->toBe(['866317']);

    $this->actingAs($user)->get(route('merchant.styles.index'))->assertOk()->assertSee('748268')->assertSee('PETITE');
});

it('blocks a confined merchant from another buyer', function () {
    $mine = Buyer::create(['buyer_name' => 'Mine', 'is_active' => true]);
    $other = Buyer::create(['buyer_name' => 'Other', 'is_active' => true]);
    $user = styleMerchant();
    $user->forceFill(['buyer_id' => $mine->id])->save();
    $user->buyers()->attach($mine->id);

    $this->actingAs($user->fresh())
        ->post(route('merchant.styles.seasons.store'), ['buyer_id' => $other->id, 'code' => 'SP27'])
        ->assertForbidden();
});
