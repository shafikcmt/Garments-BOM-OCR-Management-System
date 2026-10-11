<?php

use App\Models\IndentSection;
use App\Models\PurchaseRequisition;
use App\Models\StockItem;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The requisition screens and printed documents show the Dhaka clock; storage
 * stays UTC. The midnight case is the one that matters: an action at 00:30 in
 * Dhaka is 18:30 UTC the day before, and must read as the Dhaka day.
 *
 * In-memory sqlite. No real record touched.
 */
beforeEach(function () {
    // 30 Sep 18:30 UTC = 1 Oct 00:30 Dhaka.
    Carbon::setTestNow(Carbon::parse('2026-09-30 18:30:00', 'UTC'));
    $this->approvers = requisitionApprovers();
});

afterEach(fn () => Carbon::setTestNow());

function rltUser(string $role, array $attributes = [], array $permissions = ['store.requisition.view']): User
{
    $user = User::factory()->create($attributes)->assignRole(Role::findOrCreate($role, 'web'));

    foreach ($permissions as $p) {
        $user->givePermissionTo(Permission::findOrCreate($p, 'web'));
    }

    return $user;
}

/** A department sheet submitted and approved by its head at 00:30 Dhaka. */
function rltSheet(): array
{
    $head = rltUser('production', ['name' => 'Cutting Head']);
    $section = IndentSection::create(['name' => 'Cutting', 'department_key' => 'production', 'head_user_id' => $head->id, 'is_active' => true]);
    $raiser = rltUser('production', ['name' => 'Cutting Raiser', 'requisition_section_id' => $section->id], ['store.requisition.view', 'store.requisition.create']);
    $stock = StockItem::firstOrCreate(['name' => 'Machine Oil'], ['uom' => 'Pcs', 'is_active' => true]);

    test()->actingAs($raiser)->post(route('store.stock.requisitions.store'), [
        'action' => 'submit', 'mode' => PurchaseRequisition::MODE_MULTI, 'requisition_date' => '2026-10-01',
        'items' => [['stock_item_id' => $stock->id, 'qty_requested' => 5]],
    ])->assertSessionHasNoErrors();

    $sheet = PurchaseRequisition::latest('id')->firstOrFail();
    test()->actingAs($head)->post(route('store.stock.requisitions.stage-approve', $sheet))->assertSessionHasNoErrors();

    return [$sheet, $head, $raiser];
}

it('stores UTC and shows the Dhaka date in the history, the tracker and the header', function () {
    [$sheet, $head, $raiser] = rltSheet();

    // Storage: untouched, still the UTC instant.
    expect($sheet->fresh()->getRawOriginal('submitted_at'))->toBe('2026-09-30 18:30:00');

    $html = $this->actingAs($raiser)->get(route('store.stock.requisitions.show', $sheet))->assertOk()->getContent();

    expect($html)->toContain('01 Oct 2026, 12:30 AM')       // history + tracker
        ->toContain('01-Oct-2026 00:30')                      // header "Submitted"
        ->not->toContain('30 Sep 2026, 06:30 PM');
});

it('prints the Dhaka date as the signature date on the screen', function () {
    [$sheet, $head, $raiser] = rltSheet();

    $this->actingAs($raiser)->get(route('store.stock.requisitions.show', $sheet))
        ->assertOk()
        ->assertSee('Cutting Head · 01-Oct-2026', false)
        ->assertDontSee('30-Sep-2026');
});

it('prints the Dhaka date as the signature date on the PDF, and stamps Generated on the Dhaka clock', function () {
    [$sheet, $head, $raiser] = rltSheet();

    $captured = null;
    $fake = Mockery::mock(\Barryvdh\DomPDF\PDF::class);
    $fake->shouldReceive('setPaper')->andReturnSelf();
    $fake->shouldReceive('download')->andReturn(response('pdf'));
    Pdf::shouldReceive('loadView')->once()->andReturnUsing(function ($view, $data) use (&$captured, $fake) {
        $captured = view($view, $data)->render();

        return $fake;
    });

    $this->actingAs($raiser)->get(route('store.stock.requisitions.pdf', $sheet))->assertOk();

    expect($captured)->toContain('Signature &amp; Date: 01-Oct-2026')
        ->not->toContain('30-Sep-2026')
        ->toContain('Generated 01 Oct 2026')
        ->toContain('01 Oct 2026, 12:30 AM');
});

it('reads the same instant as the Dhaka day after 06:00 UTC too', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:00', 'UTC')); // 16:00 Dhaka, same day
    [$sheet, , $raiser] = rltSheet();

    $this->actingAs($raiser)->get(route('store.stock.requisitions.show', $sheet))
        ->assertOk()->assertSee('01 Oct 2026, 04:00 PM');
});
