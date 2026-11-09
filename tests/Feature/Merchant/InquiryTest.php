<?php

use App\Models\Buyer;
use App\Models\BuyerSeason;
use App\Models\Costing;
use App\Models\Inquiry;
use App\Models\MotherStyle;
use App\Models\User;
use App\Services\Inquiries;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/** Inquiries and quotations (M-8 slice 1): inquiry > costing > quoted > won / lost. */
beforeEach(function () {
    Carbon::setTestNow('2026-11-09 09:00:00');
    $this->merchant = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('merchant', 'web'));
    $this->buyer = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    $this->season = BuyerSeason::create(['buyer_id' => $this->buyer->id, 'code' => 'SP27']);
});
afterEach(fn () => Carbon::setTestNow());

function newInquiry($test, array $extra = []): Inquiry
{
    $test->actingAs($test->merchant)->post(route('merchant.inquiries.store'), $extra + [
        'buyer_id' => $test->buyer->id, 'style_ref' => '748268(PETITE)', 'currency' => 'USD', 'received_date' => '2026-11-09',
        'quote_due_date' => '2026-11-20', 'est_qty' => 5000, 'target_price' => 8.5, 'product_group' => 'Jacket',
    ])->assertSessionHasNoErrors();

    return Inquiry::latest('id')->firstOrFail();
}

it('adds an inquiry with a reference and shows it on the list', function () {
    $i = newInquiry($this);
    expect($i->reference)->toMatch('/^INQ-26-\d{4}$/')->and($i->status)->toBe('new');

    $this->get(route('merchant.inquiries.index'))->assertOk()->assertSee('748268(PETITE)')->assertSee($i->reference);
    $this->post(route('merchant.inquiries.store'), ['buyer_id' => $this->buyer->id, 'style_ref' => 'X', 'currency' => 'USD', 'received_date' => '2026-11-09', 'quote_due_date' => '2026-11-01'])
        ->assertSessionHasErrors('quote_due_date');
});

it('starts costing: makes the style and the buyer pre-costing, and reuses them', function () {
    $i = newInquiry($this);

    $this->post(route('merchant.inquiries.start_costing', $i), [])->assertSessionHas('warning');   // no season
    $this->post(route('merchant.inquiries.start_costing', $i), ['season_id' => $this->season->id])->assertRedirect();

    $i->refresh();
    $mother = MotherStyle::findOrFail($i->mother_style_id);
    expect($i->status)->toBe('costing')->and($mother->style_no)->toBe('748268')->and($mother->product_group)->toBe('Jacket')
        ->and($mother->costings()->where('mode', 'buyer')->count())->toBe(1);

    // Again: nothing is duplicated.
    $this->post(route('merchant.inquiries.start_costing', $i), [])->assertRedirect();
    expect(MotherStyle::count())->toBe(1)->and(Costing::count())->toBe(1);

    // A new season code can be typed.
    $j = newInquiry($this, ['style_ref' => '900100']);
    $this->post(route('merchant.inquiries.start_costing', $j), ['new_season' => 'fa27'])->assertRedirect();
    expect(BuyerSeason::where('code', 'FA27')->exists())->toBeTrue();
});

it('records the quotation with the costing price suggested, then won or lost with a reason', function () {
    $i = newInquiry($this);
    $this->post(route('merchant.inquiries.start_costing', $i), ['season_id' => $this->season->id]);
    Costing::first()->update(['quoted_price' => 9.25, 'currency' => 'EUR']);

    $this->get(route('merchant.inquiries.show', $i))->assertOk()->assertSee('9.25')->assertSee('Prefilled from pre-costing');

    $this->post(route('merchant.inquiries.won', $i))->assertSessionHas('warning');   // not quoted yet
    $this->post(route('merchant.inquiries.quote', $i), [])->assertSessionHasErrors('quoted_price');
    $this->post(route('merchant.inquiries.quote', $i), ['quoted_price' => 9.25, 'currency' => 'EUR'])->assertSessionHas('success');
    expect($i->fresh())->status->toBe('quoted')->currency->toBe('EUR');
    expect($i->fresh()->quoted_date->toDateString())->toBe('2026-11-09');

    $this->post(route('merchant.inquiries.lost', $i), [])->assertSessionHasErrors('lost_reason');
    $this->post(route('merchant.inquiries.lost', $i), ['lost_reason' => 'Price too high'])->assertSessionHas('success');
    expect($i->fresh()->status)->toBe('lost');

    $this->post(route('merchant.inquiries.reopen', $i))->assertSessionHas('success');
    expect($i->fresh()->status)->toBe('quoted');
    $this->post(route('merchant.inquiries.won', $i))->assertSessionHas('success');
    expect($i->fresh()->status)->toBe('won');
    $this->post(route('merchant.inquiries.cancel', $i))->assertSessionHas('warning');   // a won inquiry is closed
});

it('flags a late quote and works out the win rate', function () {
    $late = newInquiry($this, ['quote_due_date' => '2026-11-09']);
    Carbon::setTestNow('2026-11-12 09:00:00');
    expect($late->fresh()->isQuoteLate())->toBeTrue();

    foreach (['won', 'won', 'lost', 'cancelled', 'quoted'] as $status) {
        Inquiry::create(['buyer_id' => $this->buyer->id, 'style_ref' => 'S', 'received_date' => '2026-11-01', 'status' => $status]);
    }
    $k = app(Inquiries::class)->kpis(Inquiry::all());
    expect($k)->toMatchArray(['won' => 2, 'lost' => 1, 'win_rate' => 67, 'awaiting' => 1, 'late' => 1])->and($k['open'])->toBe(2);
});

it('keeps other buyers, management and other roles in their place', function () {
    $i = newInquiry($this);

    $mgmt = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('management', 'web'));
    $this->actingAs($mgmt)->get(route('merchant.inquiries.show', $i))->assertOk();
    $this->post(route('merchant.inquiries.cancel', $i))->assertForbidden();
    $this->post(route('merchant.inquiries.store'), ['buyer_id' => $this->buyer->id, 'style_ref' => 'X', 'currency' => 'USD', 'received_date' => '2026-11-09'])->assertForbidden();

    $store = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('store', 'web'));
    $this->actingAs($store)->get(route('merchant.inquiries.index'))->assertForbidden();
});
