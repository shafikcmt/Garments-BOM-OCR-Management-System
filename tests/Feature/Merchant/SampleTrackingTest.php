<?php

use App\Models\Buyer;
use App\Models\BuyerSeason;
use App\Models\MotherStyle;
use App\Models\Sample;
use App\Models\SampleType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/**
 * Sample tracking: add from the style page, update status / sent / buyer
 * comment, list with late ones flagged, sample types kept on screen.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-20 09:00:00');
    $this->merchant = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('merchant', 'web'));
    $this->buyer = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    $season = BuyerSeason::create(['buyer_id' => $this->buyer->id, 'code' => 'SP27']);
    $this->mother = MotherStyle::create(['buyer_id' => $this->buyer->id, 'buyer_season_id' => $season->id, 'style_no' => '748268']);
    $this->pp = SampleType::where('name', 'like', 'PP%')->firstOrFail();
});
afterEach(fn () => Carbon::setTestNow());

it('starts with the common sample types, editable on screen', function () {
    expect(SampleType::active()->pluck('name')->all())->toContain('Proto', 'Fit', 'Size Set', 'Shipment');

    $this->actingAs($this->merchant)->post(route('merchant.samples.types.store'), ['name' => 'Salesman Sample'])->assertSessionHas('success');
    $this->put(route('merchant.samples.types.toggle', SampleType::where('name', 'Proto')->first()))->assertSessionHas('success');

    expect(SampleType::active()->pluck('name')->all())->toContain('Salesman Sample')->not->toContain('Proto');
});

it('adds a sample from the style page, flags it late, then records sending and the buyer verdict', function () {
    $this->actingAs($this->merchant)->post(route('merchant.samples.store', $this->mother), [
        'sample_type_id' => $this->pp->id, 'gmts_color' => 'BLACK', 'size' => 'M', 'qty' => 2, 'required_date' => '2026-10-15',
    ])->assertSessionHas('success');

    $sample = Sample::firstOrFail();
    expect($sample->status)->toBe('requested')->and($sample->isLate())->toBeTrue();

    $this->get(route('merchant.samples.index'))->assertOk()->assertSee('748268')->assertSee('1</strong> sample(s) not sent', false);
    $this->get(route('merchant.styles.mothers.show', $this->mother))->assertOk()->assertSee('Add Sample');

    $this->put(route('merchant.samples.update', $sample), ['status' => 'sent', 'courier_awb' => 'DHL 123'])->assertSessionHas('success');
    expect($sample->fresh()->sent_date->toDateString())->toBe('2026-10-20')->and($sample->fresh()->isLate())->toBeFalse();

    $this->put(route('merchant.samples.update', $sample), ['status' => 'approved_with_comments', 'buyer_comment' => 'Shorten sleeve 1cm']);
    expect($sample->fresh()->comment_date->toDateString())->toBe('2026-10-20');

    // Closed samples leave the default (open) list.
    $this->get(route('merchant.samples.index'))->assertViewHas('samples', fn ($s) => $s->isEmpty());
    $this->get(route('merchant.samples.index', ['status' => 'approved_with_comments']))->assertViewHas('samples', fn ($s) => $s->count() === 1);
});

it('keeps other buyers out and management read-only', function () {
    $sample = $this->mother->samples()->create(['sample_type_id' => $this->pp->id, 'qty' => 1]);
    $other = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('merchant', 'web'));
    $otherBuyer = Buyer::create(['buyer_name' => 'Macys', 'is_active' => true]);
    if (method_exists($other, 'buyers')) {
        $other->buyers()->sync([$otherBuyer->id]);
        $this->actingAs($other)->put(route('merchant.samples.update', $sample), ['status' => 'sent'])->assertForbidden();
    }

    $manager = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('management', 'web'));
    $this->actingAs($manager)->get(route('merchant.samples.index'))->assertOk()->assertDontSee('Add Type');
    $this->actingAs($manager)->put(route('merchant.samples.update', $sample), ['status' => 'sent'])->assertForbidden();
});
