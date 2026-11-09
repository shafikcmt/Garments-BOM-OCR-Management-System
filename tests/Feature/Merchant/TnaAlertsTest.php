<?php

use App\Mail\FollowUpDigestMail;
use App\Models\AppNotification;
use App\Models\Buyer;
use App\Models\BuyerSeason;
use App\Models\ExcelFile;
use App\Models\FollowUpTask;
use App\Models\MotherStyle;
use App\Models\OrderLine;
use App\Models\TnaTemplate;
use App\Models\User;
use App\Services\FollowUps;
use App\Services\TnaPlans;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

/** TnA depth (M-6 slice 1): critical path, overdue e-mail, escalation to the supervisor. */
beforeEach(function () {
    Carbon::setTestNow('2026-10-20 09:00:00');
    $this->admin = User::factory()->create(['status' => 1, 'email' => 'admin@example.test'])->assignRole(Role::findOrCreate('admin', 'web'));
    $this->merchant = User::factory()->create(['status' => 1, 'email' => 'm@example.test'])->assignRole(Role::findOrCreate('merchant', 'web'));
    $this->buyer = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    $season = BuyerSeason::create(['buyer_id' => $this->buyer->id, 'code' => 'SP27']);
    $this->mother = MotherStyle::create(['buyer_id' => $this->buyer->id, 'buyer_season_id' => $season->id, 'style_no' => '748268']);
    $file = ExcelFile::create(['file_name' => 't.xlsx', 'original_file_name' => 't.xlsx', 'file_path' => 't', 'uploaded_by' => $this->merchant->id, 'buyer_id' => $this->buyer->id]);
    OrderLine::create(['excel_file_id' => $file->id, 'line_key' => 'a', 'buyer_id' => $this->buyer->id, 'mother_style_id' => $this->mother->id,
        'style_no' => '748268', 'gmts_color' => 'WHITE', 'customer_po_no' => 'C1', 'ship_date' => '2026-12-31', 'order_qty' => 1000]);
});
afterEach(fn () => Carbon::setTestNow());

function lateTask($test, string $due, array $extra = []): FollowUpTask
{
    return FollowUpTask::create($extra + ['title' => 'Book fabric', 'buyer_id' => $test->buyer->id, 'mother_style_id' => $test->mother->id,
        'assigned_to' => $test->merchant->id, 'due_date' => $due, 'status' => 'open', 'source' => 'manual']);
}

it('marks template tasks critical and carries the flag to the made tasks', function () {
    $this->actingAs($this->admin)->post(route('admin.tna.store'), ['name' => 'Std', 'is_active' => 1, 'tasks' => [
        ['title' => 'Fabric booking', 'offset_days' => -60, 'anchor' => 'ship_date', 'assignee_role' => 'merchant', 'is_critical' => '1'],
        ['title' => 'Trims', 'offset_days' => -50, 'anchor' => 'ship_date', 'assignee_role' => 'merchant', 'is_critical' => '0'],
    ]])->assertRedirect(route('admin.tna.index'));

    app(TnaPlans::class)->apply($this->mother, TnaTemplate::first(), $this->merchant);
    $tasks = FollowUpTask::where('source', 'tna')->orderBy('id')->get();
    expect($tasks[0]->isCritical())->toBeTrue()->and($tasks[1]->isCritical())->toBeFalse();
});

it('reads the critical path state of a style', function () {
    $plans = app(TnaPlans::class);
    expect($plans->criticalPath(collect([lateTask($this, '2026-10-01')])))->toBeNull();

    $late = lateTask($this, '2026-10-10', ['meta' => ['critical' => true]]);
    $later = lateTask($this, '2026-12-01', ['meta' => ['critical' => true], 'title' => 'PP']);
    $path = $plans->criticalPath(collect([$late, $later]));
    expect($path)->toMatchArray(['total' => 2, 'done' => 0, 'overdue' => 1, 'state' => 'at_risk']);

    $late->update(['status' => 'done']);
    expect($plans->criticalPath(collect([$late->fresh(), $later]))['state'])->toBe('on_track');
    $later->update(['status' => 'done']);
    expect($plans->criticalPath(collect([$late->fresh(), $later->fresh()]))['state'])->toBe('complete');

    // The style page shows it.
    lateTask($this, '2026-10-10', ['meta' => ['critical' => true], 'source' => 'tna']);
    $this->actingAs($this->merchant)->get(route('merchant.styles.mothers.show', $this->mother))->assertOk()->assertSee('Critical path:')->assertSee('At risk');
});

it('escalates a long-overdue task to the supervisor once, not to its owner', function () {
    $old = lateTask($this, '2026-10-10');             // 10 days late
    $recent = lateTask($this, '2026-10-19', ['title' => 'Recent']);  // 1 day late

    expect(app(FollowUps::class)->escalateOverdue())->toBe(1);
    expect($old->fresh()->escalated_at)->not->toBeNull()->and($recent->fresh()->escalated_at)->toBeNull();

    $n = AppNotification::where('type', 'followup_escalation')->get();
    expect($n)->toHaveCount(1)->and($n[0]->user_id)->toBe($this->admin->id);

    // Once only.
    expect(app(FollowUps::class)->escalateOverdue())->toBe(0);
    expect(AppNotification::where('type', 'followup_escalation')->count())->toBe(1);
});

it('does not escalate when Admin sets the days to 0', function () {
    \App\Models\AppSetting::put(FollowUps::KEY_ESCALATE_DAYS, 0);
    lateTask($this, '2026-09-01');
    expect(app(FollowUps::class)->escalateOverdue())->toBe(0);
});

it('sends the overdue e-mail only when Admin switched it on, and survives a mail failure', function () {
    Mail::fake();
    lateTask($this, '2026-10-18');

    app(FollowUps::class)->sendDailyReminders();
    Mail::assertNothingSent();

    \App\Models\AppSetting::put(FollowUps::KEY_EMAIL, 1);
    AppNotification::query()->delete();
    app(FollowUps::class)->sendDailyReminders();
    Mail::assertSent(FollowUpDigestMail::class, fn ($m) => $m->hasTo('m@example.test') && count($m->lines) === 1);

    // A broken mail server does not stop the in-app reminder.
    Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));
    AppNotification::query()->delete();
    expect(app(FollowUps::class)->sendDailyReminders())->toBe(1);
    expect(AppNotification::where('type', 'followup_reminder')->count())->toBe(1);
});

it('saves the alert settings from the admin screen and keeps others out', function () {
    $this->actingAs($this->admin)->put(route('admin.tna.settings'), ['email_enabled' => 1, 'escalate_after_days' => 5])->assertSessionHas('success');
    expect(FollowUps::emailEnabled())->toBeTrue()->and(FollowUps::escalateAfterDays())->toBe(5);
    $this->get(route('admin.tna.index'))->assertOk()->assertSee('Tell the supervisor after');

    $this->put(route('admin.tna.settings'), ['escalate_after_days' => 'x'])->assertSessionHasErrors('escalate_after_days');
    $this->actingAs($this->merchant)->put(route('admin.tna.settings'), ['escalate_after_days' => 2])->assertForbidden();
});
