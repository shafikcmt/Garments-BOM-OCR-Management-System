<?php

use App\Models\Buyer;
use App\Models\BuyerSeason;
use App\Models\Costing;
use App\Models\MotherStyle;
use App\Models\User;
use App\Services\Costings;
use Spatie\Permission\Models\Role;

/**
 * Pre-costing: cost sheet by cost head, buyer and in-house modes, numbered
 * revisions that are locked once final.
 */
function pcUser(string $role, array $attrs = []): User
{
    Role::findOrCreate($role, 'web');

    return User::factory()->create($attrs + ['status' => 1])->assignRole($role);
}

function pcStyle(?Buyer $buyer = null): MotherStyle
{
    $buyer ??= Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    $season = BuyerSeason::create(['buyer_id' => $buyer->id, 'code' => 'SP27']);

    return MotherStyle::create(['buyer_id' => $buyer->id, 'buyer_season_id' => $season->id, 'style_no' => '748268']);
}

function pcLines(): array
{
    return [
        ['head' => 'fabric', 'description' => 'Denim', 'qty' => 1.43, 'unit' => 'yds', 'wastage_pct' => 5, 'rate' => 3.2],
        ['head' => 'trims', 'description' => 'Zipper', 'qty' => 1, 'unit' => 'pcs', 'wastage_pct' => 0, 'rate' => 0.45],
        ['head' => 'cm', 'description' => 'CM', 'qty' => 1, 'unit' => '', 'wastage_pct' => 0, 'rate' => 1.6],
        ['head' => 'overhead', 'description' => '', 'qty' => 1, 'unit' => '', 'wastage_pct' => 0, 'rate' => 0],
    ];
}

it('works out an amount as qty x (1 + wastage) x rate', function () {
    expect(Costings::amount(1.43, 5, 3.2))->toBe(4.8048)->and(Costings::amount(1, 0, 0.45))->toBe(0.45)->and(Costings::amount(0, 10, 5))->toBe(0.0);
});

it('starts a costing with starter rows and saves lines, skipping blank ones', function () {
    $mother = pcStyle();
    $user = pcUser('merchant');
    $service = app(Costings::class);

    $costing = $service->start($mother, 'buyer', $user);
    expect($costing->revision)->toBe(1)->and($costing->isDraft())->toBeTrue()->and($costing->lines()->count())->toBe(5);

    $costing = $service->save($costing, ['currency' => 'USD', 'basis' => 'pc', 'quoted_price' => '9.5', 'note' => 'first'], pcLines());

    // The blank overhead row is not a cost line.
    expect($costing->lines()->count())->toBe(3);
    expect((float) $costing->total_cost)->toBe(round(4.8048 + 0.45 + 1.6, 4));

    $summary = $service->summary($costing);
    expect($summary['groups'])->toMatchArray(['material' => round(4.8048 + 0.45, 4), 'conversion' => 1.6, 'overhead' => 0.0, 'commission' => 0.0]);
    expect($summary['total'])->toBe(6.8548)->and($summary['profit'])->toBe(round(9.5 - 6.8548, 4))->and($summary['margin'])->toBe(27.8);
    expect($costing->marginPct())->toBe(27.8);

    expect(fn () => $service->start($mother, 'buyer', $user))->toThrow(DomainException::class, 'already has');
    expect($service->start($mother, 'inhouse', $user)->mode)->toBe('inhouse');
});

it('locks a final revision and makes a change a new revision that starts as a copy', function () {
    $mother = pcStyle();
    $user = pcUser('merchant');
    $service = app(Costings::class);

    $r1 = $service->start($mother, 'buyer', $user);
    expect(fn () => $service->finalize($r1, $user))->toThrow(DomainException::class, 'at least one cost');

    $r1 = $service->save($r1, ['currency' => 'USD', 'basis' => 'pc', 'quoted_price' => '9'], pcLines());
    $r1 = $service->finalize($r1, $user);
    expect($r1->status)->toBe('final')->and($r1->finalized_by)->toBe($user->id);

    expect(fn () => $service->save($r1, ['currency' => 'USD', 'basis' => 'pc'], pcLines()))->toThrow(DomainException::class, 'final');

    $r2 = $service->revise($mother, 'buyer', $user);
    expect($r2->revision)->toBe(2)->and($r2->isDraft())->toBeTrue()->and((float) $r2->total_cost)->toBe((float) $r1->total_cost)->and($r2->lines()->count())->toBe(3);
    expect(fn () => $service->revise($mother, 'buyer', $user))->toThrow(DomainException::class, 'still a draft');

    // Editing revision 2 leaves revision 1 exactly as it was.
    $service->save($r2, ['currency' => 'USD', 'basis' => 'pc', 'quoted_price' => '10'], [['head' => 'cm', 'description' => 'CM', 'qty' => 1, 'wastage_pct' => 0, 'rate' => 2]]);
    expect((float) $r1->fresh()->total_cost)->toBe(6.8548)->and($r1->fresh()->lines()->count())->toBe(3);
    expect((float) $r2->fresh()->total_cost)->toBe(2.0);
});

it('lets Merchandising edit through the screens and Management only read', function () {
    $mother = pcStyle();
    $merchant = pcUser('merchant');

    $this->actingAs($merchant)->get(route('costing.show', $mother))->assertOk()->assertSee('Start Buyer Costing');
    $this->actingAs($merchant)->post(route('costing.start', $mother), ['mode' => 'buyer'])->assertRedirect(route('costing.show', [$mother, 'mode' => 'buyer']));

    $costing = Costing::sole();
    $lines = pcLines();
    $this->actingAs($merchant)->put(route('costing.update', $costing), ['currency' => 'USD', 'basis' => 'pc', 'quoted_price' => '9.5', 'lines' => $lines])->assertSessionHas('success');
    expect((float) $costing->fresh()->total_cost)->toBe(6.8548);

    $this->actingAs($merchant)->get(route('costing.show', $mother))->assertOk()->assertSee('Save Costing')->assertSee('Finalize Revision 1')->assertSee('27.8%');
    $this->actingAs($merchant)->post(route('costing.finalize', $costing))->assertSessionHas('success');
    $this->actingAs($merchant)->put(route('costing.update', $costing), ['currency' => 'USD', 'basis' => 'pc', 'lines' => $lines])->assertSessionHas('warning');
    $this->actingAs($merchant)->get(route('costing.show', $mother))->assertOk()->assertSee('New Revision')->assertDontSee('Save Costing');

    $mgmt = pcUser('management');
    $this->actingAs($mgmt)->get(route('costing.show', $mother))->assertOk()->assertDontSee('Save Costing')->assertDontSee('New Revision')->assertSee('27.8%');
    $this->actingAs($mgmt)->put(route('costing.update', $costing), ['currency' => 'USD', 'basis' => 'pc', 'lines' => $lines])->assertForbidden();
    $this->actingAs($mgmt)->post(route('costing.revise', $mother), ['mode' => 'buyer'])->assertForbidden();
    $this->actingAs(pcUser('store'))->get(route('costing.show', $mother))->assertForbidden();
});

it('validates the lines and keeps another buyer out', function () {
    $mother = pcStyle();
    $merchant = pcUser('merchant');
    $costing = app(Costings::class)->start($mother, 'buyer', $merchant);
    $base = ['currency' => 'USD', 'basis' => 'pc'];

    $this->actingAs($merchant)->put(route('costing.update', $costing), $base + ['lines' => [['head' => 'nonsense', 'rate' => 1]]])->assertSessionHasErrors('lines.0.head');
    $this->actingAs($merchant)->put(route('costing.update', $costing), $base + ['lines' => [['head' => 'fabric', 'rate' => -1]]])->assertSessionHasErrors('lines.0.rate');
    $this->actingAs($merchant)->put(route('costing.update', $costing), $base + ['lines' => [['head' => 'fabric', 'rate' => 1, 'wastage_pct' => 150]]])->assertSessionHasErrors('lines.0.wastage_pct');
    $this->actingAs($merchant)->put(route('costing.update', $costing), ['currency' => 'XXX', 'basis' => 'pc'])->assertSessionHasErrors('currency');

    $mine = Buyer::create(['buyer_name' => 'Mine', 'is_active' => true]);
    $scoped = User::factory()->create(['buyer_id' => $mine->id, 'status' => 1])->assignRole('merchant');
    $scoped->buyers()->attach($mine->id);
    $scoped = $scoped->fresh();

    $this->actingAs($scoped)->get(route('costing.show', $mother))->assertForbidden();
    $this->actingAs($scoped)->put(route('costing.update', $costing), $base)->assertForbidden();
});

it('shows both modes side by side, on the style page, and the margin on the season page', function () {
    $mother = pcStyle();
    $merchant = pcUser('merchant');
    $service = app(Costings::class);

    $buyer = $service->start($mother, 'buyer', $merchant);
    $service->save($buyer, ['currency' => 'USD', 'basis' => 'pc', 'quoted_price' => '10'], [['head' => 'cm', 'description' => 'CM', 'qty' => 1, 'wastage_pct' => 0, 'rate' => 7.5]]);
    $service->finalize($buyer->fresh(), $merchant);

    $inhouse = $service->start($mother, 'inhouse', $merchant);
    $service->save($inhouse, ['currency' => 'USD', 'basis' => 'pc', 'quoted_price' => '10'], [['head' => 'cm', 'description' => 'CM', 'qty' => 1, 'wastage_pct' => 0, 'rate' => 8]]);

    $this->actingAs($merchant)->get(route('costing.show', $mother))->assertOk()->assertSee('Buyer costing margin')->assertSee('25%')->assertSee('20%');

    $this->actingAs($merchant)->get(route('merchant.styles.mothers.show', $mother))->assertOk()->assertSee('Open Pre-costing')->assertSee('In-house');

    // Only the FINAL buyer costing counts on the season page.
    $this->actingAs(pcUser('management'))->get(route('merchant.status.season', $mother->season))->assertOk()->assertSee('25%');
});
