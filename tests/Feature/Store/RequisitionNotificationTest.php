<?php

use App\Models\AppNotification;
use App\Models\IndentSection;
use App\Models\PurchaseRequisition;
use App\Models\StockItem;
use App\Models\User;
use App\Services\RequisitionNotifier;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Requisition notifications in the bell (step 3e): who is told what, never the
 * person who acted, one waiting notice per user per stage, the Store team's
 * notices cleared when one of them acts, and a failure here never stops the
 * workflow action.
 *
 * In-memory sqlite. No real record touched.
 */
beforeEach(fn () => $this->approvers = requisitionApprovers());

function rntUser(string $role, array $attributes = [], array $permissions = ['store.requisition.view']): User
{
    $user = User::factory()->create($attributes)->assignRole(Role::findOrCreate($role, 'web'));

    foreach ($permissions as $p) {
        $user->givePermissionTo(Permission::findOrCreate($p, 'web'));
    }

    return $user;
}

/** @return array{0: User, 1: User} a section head and a raiser */
function rntSection(string $name = 'Cutting'): array
{
    $head = rntUser('production', ['name' => $name.' Head']);
    $section = IndentSection::create(['name' => $name, 'department_key' => 'production', 'head_user_id' => $head->id, 'is_active' => true]);
    $raiser = rntUser('production', ['name' => $name.' Raiser', 'requisition_section_id' => $section->id], ['store.requisition.view', 'store.requisition.create']);

    return [$head, $raiser];
}

function rntSubmit(User $raiser): PurchaseRequisition
{
    $stock = StockItem::firstOrCreate(['name' => 'Machine Oil'], ['uom' => 'Pcs', 'is_active' => true]);

    test()->actingAs($raiser)->post(route('store.stock.requisitions.store'), [
        'action' => 'submit', 'mode' => PurchaseRequisition::MODE_MULTI, 'requisition_date' => '2026-10-05',
        'items' => [['stock_item_id' => $stock->id, 'qty_requested' => 5]],
    ])->assertSessionHasNoErrors();

    return PurchaseRequisition::latest('id')->firstOrFail();
}

function rntApprove(User $user, PurchaseRequisition $sheet): void
{
    test()->actingAs($user)->post(route('store.stock.requisitions.stage-approve', $sheet))->assertSessionHasNoErrors();
}

/** The user's notifications of a type, newest first, as their messages. */
function rntMessages(User $user, ?string $type = null): array
{
    return AppNotification::where('user_id', $user->id)
        ->when($type, fn ($q) => $q->where('type', $type))
        ->orderByDesc('id')->pluck('message')->all();
}

it('tells the head a sheet is waiting, and never the raiser who submitted it', function () {
    [$head, $raiser] = rntSection();
    $sheet = rntSubmit($raiser);

    expect(rntMessages($head))->toBe([$sheet->requisition_no.' is waiting for your approval (Department Head).'])
        ->and(rntMessages($raiser))->toBe([])
        ->and(AppNotification::count())->toBe(1);

    $note = AppNotification::first();
    expect($note->type)->toBe(RequisitionNotifier::WAITING)
        ->and($note->title)->toBe('Waiting for approval')
        ->and($note->url)->toBe(route('store.stock.requisitions.show', $sheet));
});

it('tells every Store user once when a sheet reaches the Store team, and clears theirs when one acts', function () {
    [$head, $raiser] = rntSection();
    $one = rntUser('store', ['name' => 'Store One']);
    $two = rntUser('store', ['name' => 'Store Two']);
    $admin = rntUser('admin');
    $sheet = rntSubmit($raiser);

    rntApprove($head, $sheet);

    // The head's own notice is cleared; the Store team is told, once each; Admin and the raiser are not.
    expect(AppNotification::where('user_id', $head->id)->whereNull('read_at')->count())->toBe(0)
        ->and(rntMessages($one, 'requisition_waiting'))->toHaveCount(1)
        ->and(rntMessages($two, 'requisition_waiting'))->toHaveCount(1)
        ->and(rntMessages($admin))->toBe([])
        ->and(rntMessages($raiser))->toBe([]);

    // One Store user receives it: the other's notice is read, the actor gets nothing, the raiser hears.
    rntApprove($one, $sheet);

    expect(AppNotification::where('user_id', $two->id)->whereNull('read_at')->count())->toBe(0)
        ->and(rntMessages($one, 'requisition_approved'))->toBe([])
        ->and(rntMessages($raiser))->toBe([$sheet->requisition_no.' was received by the Store.']);
});

it('sends one waiting notice per user per stage while it is unread', function () {
    [$head, $raiser] = rntSection();
    $sheet = rntSubmit($raiser);
    $stage = app(\App\Services\RequisitionWorkflow::class)->currentStage($sheet->fresh());

    app(RequisitionNotifier::class)->stageReached($sheet->fresh(), $stage, $raiser);
    app(RequisitionNotifier::class)->stageReached($sheet->fresh(), $stage, $raiser);

    expect(rntMessages($head, 'requisition_waiting'))->toHaveCount(1);

    // Once read, the same stage can notify again (e.g. it is sent back to it).
    AppNotification::where('user_id', $head->id)->update(['read_at' => now()]);
    app(RequisitionNotifier::class)->stageReached($sheet->fresh(), $stage, $raiser);

    expect(rntMessages($head, 'requisition_waiting'))->toHaveCount(2);
});

it('tells the raiser when a sheet is returned to them, with the reason, and not the one who returned it', function () {
    [$head, $raiser] = rntSection();
    $sheet = rntSubmit($raiser);

    $this->actingAs($head)->post(route('store.stock.requisitions.stage-return', $sheet), ['comment' => 'Qty looks wrong'])->assertSessionHasNoErrors();

    expect(rntMessages($raiser))->toBe([$sheet->requisition_no.' was returned to you by Cutting Head: Qty looks wrong'])
        ->and(rntMessages($head, 'requisition_returned'))->toBe([])
        ->and(AppNotification::where('user_id', $head->id)->whereNull('read_at')->count())->toBe(0)
        ->and(AppNotification::where('user_id', $raiser->id)->first()->title)->toBe('Requisition returned');
});

/** A final sheet submitted by a Store clerk, waiting at Store Head. */
function rntFinalSheet(User $clerk, PurchaseRequisition $department): PurchaseRequisition
{
    test()->actingAs($clerk)->post(route('store.stock.requisitions.merge'), ['ids' => [$department->id]])->assertSessionHasNoErrors();
    $final = PurchaseRequisition::where('level', PurchaseRequisition::LEVEL_FINAL)->firstOrFail();
    $line = $final->items()->first();

    test()->actingAs($clerk)->put(route('store.stock.requisitions.update', $final), [
        'action' => 'submit', 'mode' => PurchaseRequisition::MODE_MULTI, 'requisition_date' => $final->requisition_date->toDateString(),
        'items' => [['id' => $line->id, 'stock_item_id' => $line->stock_item_id, 'qty_requested' => 5, 'user_dept' => $line->user_dept]],
    ])->assertSessionHasNoErrors();

    return $final->fresh();
}

it('walks a final sheet: each approver is told in turn, a return reaches the previous approver, the raiser hears the approval', function () {
    $clerk = rntUser('store', ['name' => 'Store Clerk'], ['store.requisition.view', 'store.requisition.create']);
    [$head, $raiser] = rntSection();
    $department = rntSubmit($raiser);
    rntApprove($head, $department);
    rntApprove($clerk, $department);

    $final = rntFinalSheet($clerk, $department);
    $a = $this->approvers;

    // Store Head is told; the clerk who submitted is not.
    $forFinal = fn (User $u, string $type) => array_values(array_filter(rntMessages($u, $type), fn ($m) => str_starts_with($m, $final->requisition_no)));

    expect($forFinal($a['store_head'], 'requisition_waiting'))->toBe([$final->requisition_no.' is waiting for your approval (Store Head).'])
        ->and($forFinal($clerk, 'requisition_waiting'))->toBe([]);

    rntApprove($a['store_head'], $final);
    expect($forFinal($a['hr'], 'requisition_waiting'))->toHaveCount(1);

    // HR returns it: the Store Head (previous approver) is told, HR is not.
    $this->actingAs($a['hr'])->post(route('store.stock.requisitions.stage-return', $final), ['comment' => 'Check the rate'])->assertSessionHasNoErrors();
    expect(rntMessages($a['store_head'], 'requisition_returned'))->toBe([$final->requisition_no.' was returned to you by HR Audit Approver: Check the rate'])
        ->and(rntMessages($a['hr'], 'requisition_returned'))->toBe([]);

    // Through to the end: the Store clerk who raised the final sheet is told it was approved.
    foreach (['store_head', 'hr', 'finance', 'operation'] as $who) {
        rntApprove($a[$who], $final);
    }

    expect(rntMessages($clerk, 'requisition_approved'))->toBe([$final->requisition_no.' was approved.'])
        ->and(rntMessages($a['operation'], 'requisition_approved'))->toBe([]);
});

it('tells the raiser and the approver it waited for when a sheet is cancelled, but not the one who cancelled', function () {
    [$head, $raiser] = rntSection();
    $admin = rntUser('admin', ['name' => 'Karim']);
    $sheet = rntSubmit($raiser);

    $this->actingAs($admin)->post(route('store.stock.requisitions.cancel', $sheet), ['comment' => 'Not needed'])->assertRedirect();

    expect(rntMessages($raiser, 'requisition_cancelled'))->toBe([$sheet->requisition_no.' was cancelled by Karim.'])
        ->and(rntMessages($head, 'requisition_cancelled'))->toBe([$sheet->requisition_no.' was cancelled by Karim.'])
        ->and(rntMessages($admin))->toBe([])
        ->and(AppNotification::where('type', 'requisition_waiting')->whereNull('read_at')->count())->toBe(0);

    // A raiser cancelling their own returned sheet tells nobody: it waits for no one, and they did it.
    $second = rntSubmit($raiser);
    $this->actingAs($head)->post(route('store.stock.requisitions.stage-return', $second), ['comment' => 'Fix it'])->assertSessionHasNoErrors();
    $this->actingAs($raiser)->post(route('store.stock.requisitions.cancel', $second), ['comment' => 'Mistake'])->assertRedirect();

    expect(rntMessages($head, 'requisition_cancelled'))->toHaveCount(1)
        ->and(rntMessages($raiser, 'requisition_cancelled'))->toHaveCount(1);
});

it('tells the raiser of each department sheet it was merged, linking to their own sheet', function () {
    $clerk = rntUser('store', [], ['store.requisition.view', 'store.requisition.create']);
    [$head, $raiser] = rntSection();
    $department = rntSubmit($raiser);
    rntApprove($head, $department);
    rntApprove($clerk, $department);

    $this->actingAs($clerk)->post(route('store.stock.requisitions.merge'), ['ids' => [$department->id]])->assertSessionHasNoErrors();
    $final = PurchaseRequisition::where('level', PurchaseRequisition::LEVEL_FINAL)->firstOrFail();

    expect(rntMessages($raiser, 'requisition_merged'))->toBe([$department->requisition_no.' was merged into final sheet '.$final->requisition_no.'.'])
        ->and(AppNotification::where('type', 'requisition_merged')->first()->url)->toBe(route('store.stock.requisitions.show', $department))
        ->and(rntMessages($clerk, 'requisition_merged'))->toBe([]);
});

it('opens a notification to the sheet and marks it read', function () {
    [$head, $raiser] = rntSection();
    $sheet = rntSubmit($raiser);
    $note = AppNotification::where('user_id', $head->id)->firstOrFail();

    $this->actingAs($head)->get(route('notifications.open', $note))->assertRedirect(route('store.stock.requisitions.show', $sheet));

    expect($note->fresh()->read_at)->not->toBeNull();
});

it('shows the notice in the bell with its heading and sentence', function () {
    [$head, $raiser] = rntSection();
    $sheet = rntSubmit($raiser);

    $this->actingAs($head)->get(route('store.stock.requisitions.my-approvals'))
        ->assertOk()
        ->assertSee('Waiting for approval')
        ->assertSee($sheet->requisition_no.' is waiting for your approval (Department Head).')
        ->assertSee('bi-hourglass-split', false);
});

it('never lets a notification failure stop the action', function () {
    [$head, $raiser] = rntSection();
    $sheet = rntSubmit($raiser);

    Log::spy();
    AppNotification::creating(fn () => throw new RuntimeException('bell is broken'));

    $this->actingAs($head)->post(route('store.stock.requisitions.stage-approve', $sheet))->assertSessionHasNoErrors();

    expect($sheet->fresh()->current_stage)->toBe(3)
        ->and($sheet->fresh()->actions()->where('action', 'head_approved')->exists())->toBeTrue();

    Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains($message, 'Requisition notification failed'))->atLeast()->once();

    AppNotification::flushEventListeners();
});

it('sends nothing for a sheet that never used the stage engine', function () {
    [$head, $raiser] = rntSection();
    $sheet = rntSubmit($raiser);
    AppNotification::query()->delete();
    $stage = app(\App\Services\RequisitionWorkflow::class)->currentStage($sheet->fresh());
    $sheet->forceFill(['workflow_version' => null])->save();

    $notifier = app(RequisitionNotifier::class);
    $notifier->stageReached($sheet->fresh(), $stage, $raiser);
    $notifier->returned($sheet->fresh(), null, $head, 'No');
    $notifier->finished($sheet->fresh(), $head);
    $notifier->cancelled($sheet->fresh(), $stage, $head);
    $notifier->merged($sheet->fresh(), $sheet->fresh(), $head);

    expect(AppNotification::count())->toBe(0);
});

it('sends nothing when the action rolls back', function () {
    [$head, $raiser] = rntSection();
    $sheet = rntSubmit($raiser);
    AppNotification::query()->delete();
    $stage = app(\App\Services\RequisitionWorkflow::class)->currentStage($sheet->fresh());

    try {
        DB::transaction(function () use ($sheet, $stage, $raiser) {
            app(RequisitionNotifier::class)->stageReached($sheet->fresh(), $stage, $raiser);
            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    expect(AppNotification::count())->toBe(0);
});
