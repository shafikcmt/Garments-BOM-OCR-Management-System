<?php

use App\Models\Buyer;
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
