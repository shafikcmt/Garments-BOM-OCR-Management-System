<?php

use App\Models\Buyer;
use App\Models\PraStep;
use App\Models\PraStepApprover;
use App\Models\User;
use App\Services\PraStageResolver;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * ADMIN-CHOSEN APPROVERS (Settings > PRA Approval Routing).
 *
 * A step with people named for it is routed to exactly them; a buyer rule beats
 * the all-buyers rule; a step with nobody named keeps the automatic routing.
 */
beforeEach(function () {
    Permission::findOrCreate('pra.departmental.act', 'web');
    Role::findOrCreate('admin', 'web');
});

function routingUser(string $name, int $status = 1): User
{
    return User::factory()->create(['name' => $name, 'status' => $status]);
}

it('routes a step to the people an admin named', function () {
    $named = routingUser('Named Approver');
    PraStepApprover::replaceFor(2, null, [$named->id]);

    $actors = app(PraStageResolver::class)->resolveForBuyers(2, []);

    expect($actors->includes($named))->toBeTrue()
        ->and($actors->isActionable())->toBeTrue()
        ->and($actors->problem())->toBeNull();
});

it('lets a buyer rule win over the all-buyers rule', function () {
    $buyer = Buyer::create(['buyer_name' => 'Macys']);
    $everyone = routingUser('For Everyone');
    $macys = routingUser('For Macys');

    PraStepApprover::replaceFor(5, null, [$everyone->id]);
    PraStepApprover::replaceFor(5, $buyer->id, [$macys->id]);

    $resolver = app(PraStageResolver::class);

    $forMacys = $resolver->resolveForBuyers(5, [], [$buyer->id]);
    $forOthers = $resolver->resolveForBuyers(5, [], []);

    expect($forMacys->includes($macys))->toBeTrue()
        ->and($forMacys->includes($everyone))->toBeFalse()
        ->and($forOthers->includes($everyone))->toBeTrue();
});

it('ignores deactivated people and reports the step unrouted', function () {
    $gone = routingUser('Gone', 0);
    PraStepApprover::replaceFor(3, null, [$gone->id]);

    $actors = app(PraStageResolver::class)->resolveForBuyers(3, []);

    expect($actors->isActionable())->toBeFalse()
        ->and($actors->problem())->not->toBeNull();
});

it('keeps the automatic routing when nobody is named', function () {
    $actors = app(PraStageResolver::class)->resolveForBuyers(4, []);

    // Nobody holds the Accounts Team post in this empty database: the old message.
    expect($actors->isActionable())->toBeFalse()
        ->and($actors->postIsFilled)->toBeFalse();
});

it('grants the PRA permission to a named person and clears the rule on an empty save', function () {
    $named = routingUser('Needs Permission');

    PraStepApprover::replaceFor(6, null, [$named->id]);
    expect($named->fresh()->hasPermissionTo('pra.departmental.act'))->toBeTrue();

    PraStepApprover::replaceFor(6, null, []);
    expect(PraStepApprover::where('sequence', 6)->count())->toBe(0);
});

it('shows the routing screen to an admin and saves a step', function () {
    $admin = routingUser('Boss');
    $admin->assignRole('admin');
    $named = routingUser('Chosen One');

    $this->actingAs($admin)->get(route('admin.pra-routing.index'))
        ->assertOk()->assertSee('PRA Approval Routing')->assertSee('Chosen One');

    $this->actingAs($admin)
        ->post(route('admin.pra-routing.save', 2), ['user_ids' => [$named->id]])
        ->assertRedirect(route('admin.pra-routing.index'));

    expect(PraStepApprover::where('sequence', 2)->whereNull('buyer_id')->pluck('user_id')->all())->toBe([$named->id]);
});

it('saves the same approvers for several specific buyers and drops one that is unticked', function () {
    $admin = routingUser('Boss Two');
    $admin->assignRole('admin');
    $one = Buyer::create(['buyer_name' => 'Macys']);
    $two = Buyer::create(['buyer_name' => 'Hugo Boss']);
    $three = Buyer::create(['buyer_name' => 'Marco Polo']);
    $person = routingUser('Shared Approver');

    $this->actingAs($admin)
        ->post(route('admin.pra-routing.save_buyer', 3), ['buyer_ids' => [$one->id, $two->id], 'user_ids' => [$person->id]])
        ->assertRedirect(route('admin.pra-routing.index'));

    $buyers = fn () => PraStepApprover::where('sequence', 3)->whereNotNull('buyer_id')->pluck('buyer_id')->sort()->values()->all();
    expect($buyers())->toBe([$one->id, $two->id]);

    // Edit the rule: add a third buyer, untick the first.
    $this->actingAs($admin)
        ->post(route('admin.pra-routing.save_buyer', 3), [
            'buyer_ids' => [$two->id, $three->id], 'original_buyer_ids' => [$one->id, $two->id], 'user_ids' => [$person->id],
        ])->assertRedirect();

    expect($buyers())->toBe([$two->id, $three->id]);

    // Nothing ticked for the people = the rule is removed for those buyers.
    $this->actingAs($admin)->post(route('admin.pra-routing.save_buyer', 3), ['buyer_ids' => [$two->id, $three->id]])->assertRedirect();

    expect($buyers())->toBe([]);

    // At least one buyer is required.
    $this->actingAs($admin)->post(route('admin.pra-routing.save_buyer', 3), ['user_ids' => [$person->id]])->assertSessionHasErrors('buyer_ids');
});

it('numbers the steps by their place in the chain, not their id', function () {
    PraStep::where('sequence', 3)->update(['position' => 5]);

    expect(app(PraStageResolver::class)->ordinal(3))->toBe(1)
        ->and(app(PraStageResolver::class)->ordinal(2))->toBe(2);
});
