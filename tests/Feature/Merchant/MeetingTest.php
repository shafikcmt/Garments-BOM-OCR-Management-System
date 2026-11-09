<?php

use App\Models\AppNotification;
use App\Models\Buyer;
use App\Models\BuyerSeason;
use App\Models\FollowUpTask;
use App\Models\Meeting;
use App\Models\MotherStyle;
use App\Models\User;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/** Order progress meetings (M-11): minutes and action items that become follow-up tasks. */
beforeEach(function () {
    Carbon::setTestNow('2026-11-09 09:00:00');
    $this->merchant = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('merchant', 'web'));
    $this->sc = User::factory()->create(['status' => 1, 'name' => 'SC Person'])->assignRole(Role::findOrCreate('supply_chain', 'web'));
    $this->buyer = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    $season = BuyerSeason::create(['buyer_id' => $this->buyer->id, 'code' => 'SP27']);
    $this->mother = MotherStyle::create(['buyer_id' => $this->buyer->id, 'buyer_season_id' => $season->id, 'style_no' => '748268']);
});
afterEach(fn () => Carbon::setTestNow());

function meetingPost($test, array $extra = [])
{
    return $test->actingAs($test->merchant)->post(route('merchant.meetings.store'), $extra + [
        'buyer_id' => $test->buyer->id, 'title' => 'SP27 progress', 'meeting_date' => '2026-11-09', 'style_no' => '748268',
        'minutes' => 'Trims late', 'next_meeting_date' => '2026-11-16',
        'actions' => [
            ['title' => 'Confirm trims date', 'assigned_to' => $test->sc->id, 'due_date' => '2026-11-12'],
            ['title' => '', 'assigned_to' => '', 'due_date' => ''],
        ],
    ]);
}

it('records a meeting and turns each action into a follow-up task with a message', function () {
    meetingPost($this)->assertRedirect();
    $m = Meeting::firstOrFail();
    expect($m->mother_style_id)->toBe($this->mother->id);

    $t = FollowUpTask::where('meeting_id', $m->id)->get();
    expect($t)->toHaveCount(1)->and($t[0])->source->toBe('meeting')->assigned_to->toBe($this->sc->id)->buyer_id->toBe($this->buyer->id)
        ->and($t[0]->due_date->toDateString())->toBe('2026-11-12');
    expect(AppNotification::where('user_id', $this->sc->id)->where('title', 'Action from a meeting')->count())->toBe(1);

    $this->get(route('merchant.meetings.index'))->assertOk()->assertSee('SP27 progress')->assertSee('1 open');
    $this->get(route('merchant.meetings.show', $m))->assertOk()->assertSee('Confirm trims date')->assertSee('Trims late');

    // The assignee finishes it from the to-do list and the meeting shows it done.
    $this->actingAs($this->sc)->post(route('follow_ups.done', $t[0]))->assertSessionHasNoErrors();
    $this->actingAs($this->merchant)->get(route('merchant.meetings.show', $m))->assertSee('1 / 1 done');
});

it('validates: unknown style, an action without a person, a next date before the meeting', function () {
    meetingPost($this, ['style_no' => 'NOPE'])->assertSessionHasErrors('style_no');
    meetingPost($this, ['actions' => [['title' => 'Chase', 'assigned_to' => '', 'due_date' => '2026-11-12']]])->assertSessionHasErrors('actions');
    meetingPost($this, ['next_meeting_date' => '2026-11-01'])->assertSessionHasErrors('next_meeting_date');
    expect(Meeting::count())->toBe(0);
});

it('refuses an action for someone who cannot see the buyer and saves nothing', function () {
    $other = Buyer::create(['buyer_name' => 'Macys', 'is_active' => true]);
    $confined = User::factory()->create(['status' => 1, 'name' => 'Confined'])->assignRole(Role::findOrCreate('merchant', 'web'));
    $confined->buyers()->sync([$other->id]);

    meetingPost($this, ['actions' => [['title' => 'Chase', 'assigned_to' => $confined->id, 'due_date' => '2026-11-12']]])->assertSessionHas('warning');
    expect(Meeting::count())->toBe(0)->and(FollowUpTask::count())->toBe(0);
});

it('adds actions later, edits the minutes, deletes without losing the tasks, and limits roles', function () {
    meetingPost($this);
    $m = Meeting::firstOrFail();

    $this->post(route('merchant.meetings.actions', $m), ['actions' => [['title' => 'Send PP sample', 'assigned_to' => $this->merchant->id, 'due_date' => '2026-11-14']]])->assertSessionHas('success');
    expect(FollowUpTask::where('meeting_id', $m->id)->count())->toBe(2);
    $this->post(route('merchant.meetings.actions', $m), ['actions' => []])->assertSessionHas('warning');

    $this->put(route('merchant.meetings.update', $m), ['title' => 'SP27 progress 2', 'meeting_date' => '2026-11-09', 'minutes' => 'Updated'])->assertSessionHas('success');
    expect($m->fresh()->minutes)->toBe('Updated');

    $mgmt = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('management', 'web'));
    $this->actingAs($mgmt)->get(route('merchant.meetings.show', $m))->assertOk();
    $this->delete(route('merchant.meetings.destroy', $m))->assertForbidden();
    $store = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('store', 'web'));
    $this->actingAs($store)->get(route('merchant.meetings.index'))->assertForbidden();

    $this->actingAs($this->merchant)->delete(route('merchant.meetings.destroy', $m))->assertRedirect(route('merchant.meetings.index'));
    expect(Meeting::count())->toBe(0)->and(FollowUpTask::count())->toBe(2)->and(FollowUpTask::whereNotNull('meeting_id')->count())->toBe(0);
});
