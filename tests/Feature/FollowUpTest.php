<?php

use App\Models\AppNotification;
use App\Models\Buyer;
use App\Models\BuyerSeason;
use App\Models\FollowUpTask;
use App\Models\MotherStyle;
use App\Models\User;
use App\Services\FollowUps;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/**
 * Follow-ups (Time and Action): a person is asked to chase a style by a date,
 * sees it in a to-do list with a traffic light, and is reminded daily.
 */
function fuUser(string $role, array $attrs = []): User
{
    Role::findOrCreate($role, 'web');

    return User::factory()->create($attrs + ['status' => 1])->assignRole($role);
}

function fuStyle(?Buyer $buyer = null): MotherStyle
{
    $buyer ??= Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    $season = BuyerSeason::create(['buyer_id' => $buyer->id, 'code' => 'SP27']);

    return MotherStyle::create(['buyer_id' => $buyer->id, 'buyer_season_id' => $season->id, 'style_no' => '748268']);
}

it('creates a follow-up from the style page and tells the person', function () {
    $mother = fuStyle();
    $merchant = fuUser('merchant');
    $supply = fuUser('supply_chain', ['name' => 'Sara Supply']);

    $this->actingAs($merchant)->get(route('merchant.styles.mothers.show', $mother))
        ->assertOk()->assertSee('Create Follow-up')->assertSee('Sara Supply');

    $this->actingAs($merchant)->post(route('follow_ups.store'), [
        'mother_style_id' => $mother->id, 'assigned_to' => $supply->id,
        'due_date' => now()->addDays(3)->toDateString(), 'note' => 'Call the vendor',
    ])->assertRedirect()->assertSessionHas('success');

    $task = FollowUpTask::sole();
    expect($task->title)->toBe('Follow up style 748268')->and($task->assigned_to)->toBe($supply->id)
        ->and($task->created_by)->toBe($merchant->id)->and($task->buyer_id)->toBe($mother->buyer_id);

    $note = AppNotification::where('user_id', $supply->id)->where('type', 'followup_assigned')->sole();
    expect($note->message)->toContain($merchant->name)->toContain('748268');

    // And the same person cannot be given a second open one for the same style.
    $this->actingAs($merchant)->post(route('follow_ups.store'), [
        'mother_style_id' => $mother->id, 'assigned_to' => $supply->id, 'due_date' => now()->addDays(5)->toDateString(),
    ])->assertSessionHas('warning');
    expect(FollowUpTask::count())->toBe(1);
});

it('shows the task in the person\'s to-do list in the right group and lets them finish it', function () {
    $mother = fuStyle();
    $merchant = fuUser('merchant');
    $supply = fuUser('supply_chain');

    $make = fn (string $title, string $due) => FollowUpTask::create([
        'title' => $title, 'assigned_to' => $supply->id, 'created_by' => $merchant->id,
        'due_date' => $due, 'mother_style_id' => $mother->id, 'buyer_id' => $mother->buyer_id,
    ])->fresh();
    $late = $make('Late one', now()->subDay()->toDateString());
    $make('Today one', now()->toDateString());
    $make('Later one', now()->addDays(9)->toDateString());

    $page = $this->actingAs($supply)->get(route('follow_ups.index'))->assertOk()
        ->assertSee('Overdue')->assertSee('Due today')->assertSee('Coming up');
    expect($page->viewData('groups')['Overdue']->pluck('title')->all())->toBe(['Late one']);

    expect($late->light())->toBe('red');
    expect($make('Soon', now()->addDays(2)->toDateString())->light())->toBe('amber');
    expect($make('Far', now()->addDays(20)->toDateString())->light())->toBe('green');

    $this->actingAs($supply)->post(route('follow_ups.done', $late))->assertSessionHas('success');
    expect($late->fresh()->status)->toBe('done');
    expect(AppNotification::where('user_id', $merchant->id)->where('type', 'followup_done')->count())->toBe(1);

    $this->actingAs($supply)->post(route('follow_ups.reopen', $late))->assertSessionHas('success');
    expect($late->fresh()->isOpen())->toBeTrue();
});

it('only lets the assignee, the giver or an admin change a task, and only Management sees everyone', function () {
    $mother = fuStyle();
    $merchant = fuUser('merchant');
    $supply = fuUser('supply_chain');
    $stranger = fuUser('merchant');
    $task = FollowUpTask::create(['title' => 'T', 'assigned_to' => $supply->id, 'created_by' => $merchant->id, 'due_date' => now()->toDateString(), 'mother_style_id' => $mother->id]);

    $this->actingAs($stranger)->post(route('follow_ups.done', $task))->assertForbidden();
    $this->actingAs($merchant)->post(route('follow_ups.done', $task))->assertSessionHas('success');
    $this->actingAs(fuUser('admin'))->post(route('follow_ups.reopen', $task))->assertSessionHas('success');

    expect($this->actingAs($stranger)->get(route('follow_ups.index', ['tab' => 'all']))->viewData('tab'))->toBe('mine');
    expect($this->actingAs(fuUser('management'))->get(route('follow_ups.index', ['tab' => 'all']))->viewData('tab'))->toBe('all');
    $this->actingAs(fuUser('store'))->get(route('follow_ups.index'))->assertForbidden();
});

it('refuses someone who cannot see the buyer and a date in the past', function () {
    $mine = Buyer::create(['buyer_name' => 'Mine', 'is_active' => true]);
    $mother = fuStyle();
    $outsider = fuUser('supply_chain', ['buyer_id' => $mine->id]);
    $outsider->buyers()->attach($mine->id);

    $merchant = fuUser('merchant');
    $this->actingAs($merchant)->post(route('follow_ups.store'), [
        'mother_style_id' => $mother->id, 'assigned_to' => $outsider->id, 'due_date' => now()->addDay()->toDateString(),
    ])->assertSessionHas('warning');

    $supply = fuUser('supply_chain');
    $this->actingAs($merchant)->post(route('follow_ups.store'), [
        'mother_style_id' => $mother->id, 'assigned_to' => $supply->id, 'due_date' => now()->subDay()->toDateString(),
    ])->assertSessionHasErrors('due_date');

    expect(FollowUpTask::count())->toBe(0);
});

it('names the late materials in the default title', function () {
    $mother = fuStyle();
    $merchant = fuUser('merchant');
    $supply = fuUser('supply_chain');

    $service = app(FollowUps::class);
    $rollup = Mockery::mock(\App\Services\StyleRollup::class);
    $rollup->shouldReceive('forMother')->andReturn(['kpis' => ['late' => 4, 'due_soon' => 1, 'materials' => 9]]);
    $service = new FollowUps($rollup);

    $task = $service->create($mother, $supply, $merchant, now()->addDays(2)->toDateString(), null, null);

    expect($task->title)->toBe('Order 4 late material(s) for style 748268')->and($task->source)->toBe('late_material');
    expect($task->meta)->toMatchArray(['late' => 4, 'due_soon' => 1]);
});

it('sends one reminder a day to people with tasks due today or overdue', function () {
    $mother = fuStyle();
    $supply = fuUser('supply_chain');
    $quiet = fuUser('merchant');
    $today = Carbon::today();

    foreach ([$today->copy()->subDays(2), $today->copy()->subDay(), $today->copy()] as $i => $due) {
        FollowUpTask::create(['title' => "T$i", 'assigned_to' => $supply->id, 'due_date' => $due->toDateString(), 'mother_style_id' => $mother->id]);
    }
    FollowUpTask::create(['title' => 'future', 'assigned_to' => $quiet->id, 'due_date' => $today->copy()->addDays(5)->toDateString()]);
    FollowUpTask::create(['title' => 'done', 'assigned_to' => $quiet->id, 'due_date' => $today->copy()->subDay()->toDateString(), 'status' => 'done']);

    $this->artisan('followups:remind')->expectsOutputToContain('1 person(s) reminded')->assertSuccessful();

    $note = AppNotification::where('type', 'followup_reminder')->sole();
    expect($note->user_id)->toBe($supply->id)->and($note->message)->toContain('2 overdue')->toContain('1 due today');

    // Run again the same day: nothing new.
    $this->artisan('followups:remind')->expectsOutputToContain('0 person(s) reminded');
    expect(AppNotification::where('type', 'followup_reminder')->count())->toBe(1);
});

it('shows open follow-ups per style on the season page', function () {
    $mother = fuStyle();
    $supply = fuUser('supply_chain');
    FollowUpTask::create(['title' => 'T', 'assigned_to' => $supply->id, 'due_date' => now()->toDateString(), 'mother_style_id' => $mother->id, 'buyer_id' => $mother->buyer_id]);

    $this->actingAs(fuUser('management'))->get(route('merchant.status.season', $mother->season))
        ->assertOk()->assertSee('1 open');
});
