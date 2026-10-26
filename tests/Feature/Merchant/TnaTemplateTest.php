<?php

use App\Models\AppNotification;
use App\Models\Buyer;
use App\Models\BuyerSeason;
use App\Models\ExcelFile;
use App\Models\FollowUpTask;
use App\Models\MotherStyle;
use App\Models\OrderLine;
use App\Models\TnaTemplate;
use App\Models\User;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/**
 * TnA templates: tasks due relative to an order date, given to a role, made
 * as follow-up tasks; applying twice adds nothing new.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-20 09:00:00');
    $this->admin = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('admin', 'web'));
    $this->merchant = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('merchant', 'web'));
    $this->sc = User::factory()->create(['status' => 1, 'name' => 'SC Person'])->assignRole(Role::findOrCreate('supply_chain', 'web'));
    $this->buyer = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    $season = BuyerSeason::create(['buyer_id' => $this->buyer->id, 'code' => 'SP27']);
    $this->mother = MotherStyle::create(['buyer_id' => $this->buyer->id, 'buyer_season_id' => $season->id, 'style_no' => '748268']);
    $file = ExcelFile::create(['file_name' => 't.xlsx', 'original_file_name' => 't.xlsx', 'file_path' => 't', 'uploaded_by' => $this->merchant->id, 'buyer_id' => $this->buyer->id]);
    OrderLine::create(['excel_file_id' => $file->id, 'line_key' => 'a', 'buyer_id' => $this->buyer->id, 'mother_style_id' => $this->mother->id,
        'style_no' => '748268', 'gmts_color' => 'WHITE', 'customer_po_no' => 'C1', 'ship_date' => '2026-12-31', 'order_qty' => 1000]);
});
afterEach(fn () => Carbon::setTestNow());

function tnaSave($test, array $extra = [])
{
    return $test->actingAs($test->admin)->post(route('admin.tna.store'), $extra + [
        'name' => 'Standard 90 days', 'is_active' => 1,
        'tasks' => [
            ['title' => 'Fabric booking', 'offset_days' => -60, 'anchor' => 'ship_date', 'assignee_role' => 'supply_chain'],
            ['title' => 'PP sample approval', 'offset_days' => -30, 'anchor' => 'ship_date', 'assignee_role' => 'merchant'],
        ],
    ]);
}

it('saves a template with its tasks from the admin screen', function () {
    tnaSave($this)->assertRedirect(route('admin.tna.index'));

    $t = TnaTemplate::with('tasks')->first();
    expect($t->tasks)->toHaveCount(2)->and($t->tasks[0]->whenLabel())->toBe('60 days before ship date');

    $this->get(route('admin.tna.index'))->assertOk()->assertSee('Standard 90 days');
});

it('makes follow-up tasks from the template once, with due dates and roles', function () {
    tnaSave($this);
    $template = TnaTemplate::first();

    $this->actingAs($this->merchant)->post(route('merchant.tna.apply', $this->mother), ['tna_template_id' => $template->id])
        ->assertSessionHas('success', fn ($m) => str_contains($m, '2 TnA task(s)'));

    $tasks = FollowUpTask::where('source', 'tna')->orderBy('due_date')->get();
    expect($tasks)->toHaveCount(2)
        ->and($tasks[0]->due_date->toDateString())->toBe('2026-11-01')
        ->and($tasks[0]->assigned_to)->toBe($this->sc->id)
        ->and($tasks[1]->assigned_to)->toBe($this->merchant->id); // the person applying holds the role

    expect(AppNotification::where('user_id', $this->sc->id)->where('title', 'New TnA tasks')->count())->toBe(1);

    // Applying again adds nothing.
    $this->post(route('merchant.tna.apply', $this->mother), ['tna_template_id' => $template->id])->assertSessionHas('info');
    expect(FollowUpTask::where('source', 'tna')->count())->toBe(2);

    $this->get(route('merchant.styles.mothers.show', $this->mother))->assertOk()->assertSee('TnA Plan')->assertSee('0 / 2 done');
});

it('applies auto templates every morning without repeating', function () {
    tnaSave($this, ['auto_apply' => 1]);

    $this->artisan('tna:apply')->assertSuccessful();
    $this->artisan('tna:apply')->assertSuccessful();

    expect(FollowUpTask::where('source', 'tna')->count())->toBe(2);
});

it('reports the tasks it could not make', function () {
    tnaSave($this, ['tasks' => [['title' => 'Inline QC', 'offset_days' => -10, 'anchor' => 'ship_date', 'assignee_role' => 'admin']]]);
    Role::findOrCreate('qc', 'web');
    TnaTemplate::first()->tasks()->first()->update(['assignee_role' => 'qc']);

    $this->actingAs($this->merchant)->post(route('merchant.tna.apply', $this->mother), ['tna_template_id' => TnaTemplate::first()->id])
        ->assertSessionHas('warning', fn ($w) => str_contains($w, 'nobody with the role "qc"'));
});
