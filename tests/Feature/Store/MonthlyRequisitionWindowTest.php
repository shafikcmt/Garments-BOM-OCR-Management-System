<?php

use App\Models\AppSetting;
use App\Models\IndentSection;
use App\Models\PurchaseRequisition;
use App\Models\StockItem;
use App\Models\User;
use App\Support\MonthlyRequisitionWindow as Window;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * A Monthly requisition may be SUBMITTED only inside the window (20th to month
 * end by default, set by Admin / Store). Individual has no window; a draft can
 * be saved any day. In-memory sqlite.
 */
beforeEach(function () {
    $this->approvers = requisitionApprovers();
    AppSetting::put(Window::KEY_START_DAY, '20'); // the real default
});

function winRaiser(): User
{
    $head = User::factory()->create()->assignRole(Role::findOrCreate('production', 'web'));
    $section = IndentSection::create(['name' => 'Cutting', 'department_key' => 'production', 'head_user_id' => $head->id, 'is_active' => true]);
    $user = User::factory()->create(['requisition_section_id' => $section->id])->assignRole(Role::findOrCreate('production', 'web'));
    foreach (['store.requisition.view', 'store.requisition.create'] as $p) {
        $user->givePermissionTo(Permission::findOrCreate($p, 'web'));
    }

    return $user;
}

function winPost(User $user, string $type, string $action = 'submit')
{
    $item = StockItem::firstOrCreate(['name' => 'Machine Oil'], ['uom' => 'Ltr', 'is_active' => true]);

    return test()->actingAs($user)->post(route('store.stock.requisitions.store'), [
        'action' => $action, 'mode' => PurchaseRequisition::MODE_MULTI, 'requisition_date' => '2026-10-05',
        'requisition_type' => $type,
        'items' => [['stock_item_id' => $item->id, 'qty_requested' => 5]],
    ]);
}

it('refuses a monthly submit before the 20th with the window message', function () {
    $this->travelTo(now('Asia/Dhaka')->setDate(2026, 11, 19)->setTime(12, 0));

    winPost(winRaiser(), 'monthly')->assertSessionHasErrors(['requisition_type' => Window::message()]);

    expect(PurchaseRequisition::count())->toBe(0)
        ->and(Window::message())->toContain('20th to the end of the month')->toContain('Individual');
});

it('accepts a monthly submit on the 20th and on the last day', function () {
    $user = winRaiser();

    foreach ([[2026, 11, 20], [2026, 11, 30]] as [$y, $m, $d]) {
        $this->travelTo(now('Asia/Dhaka')->setDate($y, $m, $d)->setTime(12, 0));
        winPost($user, 'monthly')->assertSessionHasNoErrors();
    }

    expect(PurchaseRequisition::count())->toBe(2);
});

it('never holds back an individual submit', function () {
    $this->travelTo(now('Asia/Dhaka')->setDate(2026, 11, 3)->setTime(12, 0));

    winPost(winRaiser(), 'individual')->assertSessionHasNoErrors();

    expect(PurchaseRequisition::count())->toBe(1);
});

it('lets a monthly draft be saved on any day', function () {
    $this->travelTo(now('Asia/Dhaka')->setDate(2026, 11, 3)->setTime(12, 0));

    winPost(winRaiser(), 'monthly', 'draft')->assertSessionHasNoErrors();

    expect(PurchaseRequisition::count())->toBe(1);
});

it('refuses submitting a monthly draft from the edit form outside the window', function () {
    $user = winRaiser();
    $this->travelTo(now('Asia/Dhaka')->setDate(2026, 11, 3)->setTime(12, 0));
    winPost($user, 'monthly', 'draft');
    $draft = PurchaseRequisition::firstOrFail();
    $item = StockItem::firstOrFail();

    $this->actingAs($user)->put(route('store.stock.requisitions.update', $draft), [
        'action' => 'submit', 'mode' => PurchaseRequisition::MODE_MULTI, 'requisition_date' => '2026-11-03',
        'items' => [['stock_item_id' => $item->id, 'qty_requested' => 5]],
    ])->assertSessionHasErrors('requisition_type');

    expect($draft->fresh()->status)->toBe(PurchaseRequisition::STATUS_DRAFT);
});

it('follows what Admin sets: closed switch and custom days', function () {
    $user = winRaiser();
    $this->travelTo(now('Asia/Dhaka')->setDate(2026, 11, 25)->setTime(12, 0));

    AppSetting::put(Window::KEY_ENABLED, '0');
    winPost($user, 'monthly')->assertSessionHasErrors('requisition_type');
    expect(Window::message())->toContain('currently closed');

    AppSetting::put(Window::KEY_ENABLED, '1');
    AppSetting::put(Window::KEY_START_DAY, '10');
    AppSetting::put(Window::KEY_END_DAY, '24');
    winPost($user, 'monthly')->assertSessionHasErrors('requisition_type'); // 25th is after 24th
    expect(Window::rangeText())->toBe('10th to 24th');

    AppSetting::put(Window::KEY_END_DAY, '28');
    winPost($user, 'monthly')->assertSessionHasNoErrors();
});

it('lets Admin and Store change the window, and nobody else', function () {
    $this->withoutVite();
    $store = User::factory()->create()->assignRole(Role::findOrCreate('store', 'web'));
    $admin = User::factory()->create()->assignRole(Role::findOrCreate('admin', 'web'));

    $this->actingAs(winRaiser())->get(route('store.stock.requisitions.window.edit'))->assertForbidden();

    foreach ([$store, $admin] as $user) {
        $this->actingAs($user)->get(route('store.stock.requisitions.window.edit'))->assertOk();
    }

    $this->actingAs($store)->post(route('store.stock.requisitions.window.update'), [
        'enabled' => '1', 'start_day' => 15, 'end_day' => 0, 'message' => '',
    ])->assertSessionHasNoErrors();

    expect(Window::startDay())->toBe(15)->and(Window::endDay())->toBe(0);

    $this->actingAs($store)->post(route('store.stock.requisitions.window.update'), [
        'enabled' => '1', 'start_day' => 20, 'end_day' => 10,
    ])->assertSessionHasErrors('end_day');
});
