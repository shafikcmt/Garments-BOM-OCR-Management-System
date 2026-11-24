<?php

use App\Models\PaymentRequest;
use App\Models\PraStep;
use App\Models\User;
use App\Services\PraStageResolver;
use Spatie\Permission\Models\Role;

/**
 * THE CHAIN AS DATA (Settings > PRA Approval Steps).
 *
 * Admin decides how many steps there are, their order, who each is for and which
 * print a signature box. The built-in chain is what the migration seeds.
 */
function stepsAdmin(): User
{
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}

it('starts with exactly the built-in five steps', function () {
    $resolver = app(PraStageResolver::class);

    expect($resolver->sequences())->toBe([2, 3, 4, 5, 6])
        ->and($resolver->firstSequence())->toBe(2)
        ->and($resolver->lastSequence())->toBe(6)
        ->and($resolver->nextSequence(3))->toBe(4)
        ->and($resolver->nextSequence(6))->toBeNull()
        ->and($resolver->lastApproveSequence())->toBe(5);
});

it('lets an admin add a seventh-style step that joins the chain and prints a box', function () {
    $admin = stepsAdmin();

    $this->actingAs($admin)->post(route('admin.pra-steps.store'), [
        'label' => 'Finance Head Approval',
        'department' => '',
        'actor_kind' => 'head',
        'stage' => 'approve',
        'signature_label' => 'Approved By (Finance Head)',
    ])->assertRedirect(route('admin.pra-steps.index'));

    $resolver = app(PraStageResolver::class);
    $new = PraStep::where('label', 'Finance Head Approval')->firstOrFail();

    expect($resolver->sequences())->toContain($new->sequence)
        ->and($resolver->nextSequence(6))->toBe($new->sequence)
        ->and($resolver->definitionFor($new->sequence)['signature_label'])->toBe('Approved By (Finance Head)');
});

it('reorders steps and keeps the chain order', function () {
    $admin = stepsAdmin();
    $second = PraStep::where('sequence', 3)->firstOrFail();

    $this->actingAs($admin)->post(route('admin.pra-steps.move', $second), ['direction' => 'up'])->assertRedirect();

    expect(app(PraStageResolver::class)->sequences())->toBe([3, 2, 4, 5, 6]);
});

it('refuses to switch off a step while a PRA is waiting on it, and allows it otherwise', function () {
    $admin = stepsAdmin();
    $creator = User::factory()->create();

    PaymentRequest::create([
        'request_no' => 'PRA-WAIT', 'status' => 'pending_check', 'created_by' => $creator->id,
        'flow_version' => PaymentRequest::FLOW_DEPARTMENTAL, 'current_sequence' => 4,
    ]);

    $step4 = PraStep::where('sequence', 4)->firstOrFail();
    $this->actingAs($admin)->post(route('admin.pra-steps.toggle', $step4))
        ->assertSessionHas('warning', fn ($m) => str_contains($m, 'waiting'));
    expect($step4->fresh()->is_active)->toBeTrue();

    $step6 = PraStep::where('sequence', 6)->firstOrFail();
    $this->actingAs($admin)->post(route('admin.pra-steps.toggle', $step6))->assertRedirect();
    expect($step6->fresh()->is_active)->toBeFalse()
        ->and(app(PraStageResolver::class)->sequences())->toBe([2, 3, 4, 5]);
});

it('reports a step with no department as unassigned until people are chosen', function () {
    PraStep::create([
        'sequence' => 7, 'position' => 70, 'label' => 'Special Approval', 'department' => null,
        'actor_kind' => 'head', 'stage' => 'approve', 'buyer_scoped' => false, 'is_active' => true,
    ]);

    $actors = app(PraStageResolver::class)->resolveForBuyers(7, []);

    expect($actors->isActionable())->toBeFalse()
        ->and($actors->problem())->toContain('Special Approval');
});

it('only lets an admin reach the steps screen', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.pra-steps.index'))->assertForbidden();
    $this->actingAs(stepsAdmin())->get(route('admin.pra-steps.index'))->assertOk()->assertSee('PRA Approval Steps');
});
