<?php

use App\Models\IndentPerson;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Step 0 of the requisition review: display fixes and the self-approval block.
 *
 *   - Requested By falls back to the account that raised the sheet.
 *   - A 0.00 total reads "—", with the reason on hover.
 *   - Stock Summary says "To Purchase" and "Total" (screen only).
 *   - Month is a select of named months, not the browser's empty month box.
 *   - Whoever raised a sheet cannot mark it Received or Approve it — Admin
 *     included — and is told why instead of being shown the button.
 *
 * In-memory sqlite. No real record touched.
 */
function step0User(string $role, array $permissions = []): User
{
    $r = Role::findOrCreate($role, 'web');

    foreach (array_merge(['store.requisition.view'], $permissions) as $p) {
        $r->givePermissionTo(Permission::findOrCreate($p, 'web'));
    }

    return User::factory()->create()->assignRole($role);
}

function step0Requisition(User $by, string $status, array $attributes = [], float $amount = 0): PurchaseRequisition
{
    $requisition = PurchaseRequisition::create($attributes + [
        'requisition_no' => 'S0-'.$by->id.'-'.uniqid(),
        'requisition_date' => now()->toDateString(),
        'mode' => PurchaseRequisition::MODE_MULTI,
        'level' => PurchaseRequisition::LEVEL_FINAL,
        'status' => $status,
        'submitted_at' => now(),
        'created_by' => $by->id,
    ]);

    PurchaseRequisitionItem::create([
        'purchase_requisition_id' => $requisition->id,
        'custom_item_name' => 'Hand Sanitiser 5L',
        'sort_order' => 1,
        'qty_requested' => 5,
        'amount' => $amount,
    ]);

    return $requisition;
}

it('shows the raiser as Requested By when no Indent Person was picked', function () {
    $store = step0User('store');
    $raiser = User::factory()->create(['name' => 'Md Robeul']);
    step0Requisition($raiser, PurchaseRequisition::STATUS_SUBMITTED);

    $this->actingAs($store)->get(route('store.stock.requisitions.index'))
        ->assertOk()
        ->assertSeeInOrder(['Requested By', 'Md Robeul']);
});

it('still prefers the Indent Person when one was picked', function () {
    $store = step0User('store');
    $person = IndentPerson::create(['name' => 'Paper Requester']);
    step0Requisition($store, PurchaseRequisition::STATUS_SUBMITTED, ['indent_person_id' => $person->id]);

    expect(PurchaseRequisition::first()->requesterName())->toBe('Paper Requester');
});

it('prints a zero total as a dash with the reason, and a real total as money', function () {
    $store = step0User('store');
    step0Requisition($store, PurchaseRequisition::STATUS_SUBMITTED, [], 0);
    step0Requisition($store, PurchaseRequisition::STATUS_SUBMITTED, [], 1234.5);

    $this->actingAs($store)->get(route('store.stock.requisitions.index'))
        ->assertOk()
        ->assertSee('title="Nothing to purchase, or no rate yet"', false)
        ->assertSee('1,234.50')
        ->assertDontSee('<td class="text-end fw-semibold">0.00</td>', false);
});

it('labels the Stock Summary "To Purchase" and its footer "Total"', function () {
    $store = step0User('store', [\App\Services\RequirementVsIssueService::PERMISSION]);
    step0Requisition($store, PurchaseRequisition::STATUS_SUBMITTED);

    $this->actingAs($store)->get(route('store.stock.requisitions.index'))
        ->assertOk()
        ->assertSee('To Purchase')
        ->assertSee('>Total</td>', false)
        ->assertDontSee('Total-');
});

it('offers the month filter as named months, keeping an older selected month', function () {
    Carbon::setTestNow('2026-09-27');
    $store = step0User('store');

    $html = $this->actingAs($store)
        ->get(route('store.stock.requisitions.index', ['month' => '2023-02']))
        ->assertOk()
        ->getContent();

    expect($html)
        ->not->toContain('type="month"')
        ->toContain('All months')
        ->toContain('<option value="2026-09"')
        ->toContain('September 2026')
        // Older than the 24-month window, so appended — and not rolled into
        // March by the 29th-31st overflow that "Y-m" parsing has.
        ->toMatch('/<option value="2023-02" selected>February 2023<\/option>/');

    Carbon::setTestNow();
});

it('parses the month filter without rolling into the next month at month end', function () {
    Carbon::setTestNow('2026-01-31');
    $store = step0User('store');

    $this->actingAs($store)
        ->get(route('store.stock.requisitions.index', ['month' => '2026-02']))
        ->assertOk()
        ->assertSee('February 2026')
        ->assertDontSee('March 2026');

    Carbon::setTestNow();
});

it('refuses Received from the person who raised the sheet, and allows another Store user', function () {
    $raiser = step0User('store', ['store.requisition.review']);
    $colleague = User::factory()->create()->assignRole('store');
    $requisition = step0Requisition($raiser, PurchaseRequisition::STATUS_SUBMITTED);

    $this->actingAs($raiser)
        ->post(route('store.stock.requisitions.received', $requisition))
        ->assertRedirect()
        ->assertSessionHas('error', 'You raised this requisition, so another person must mark it received.');

    expect($requisition->fresh()->status)->toBe(PurchaseRequisition::STATUS_SUBMITTED);

    $this->actingAs($colleague)
        ->post(route('store.stock.requisitions.received', $requisition))
        ->assertRedirect();

    expect($requisition->fresh())
        ->status->toBe(PurchaseRequisition::STATUS_STORE_REVIEWED)
        ->store_ack_by->toBe($colleague->id);
});

it('refuses Approve from Admin on a sheet Admin raised, and allows another approver', function () {
    $admin = step0User('admin', ['store.requisition.review', 'store.requisition.approve']);
    $manager = step0User('management', ['store.requisition.review', 'store.requisition.approve']);
    $requisition = step0Requisition($admin, PurchaseRequisition::STATUS_STORE_REVIEWED);

    $this->actingAs($admin)
        ->post(route('store.stock.requisitions.approve', $requisition))
        ->assertRedirect()
        ->assertSessionHas('error', 'You raised this requisition, so another person must approve it.');

    expect($requisition->fresh()->status)->toBe(PurchaseRequisition::STATUS_STORE_REVIEWED);

    $this->actingAs($manager)
        ->post(route('store.stock.requisitions.approve', $requisition))
        ->assertRedirect();

    expect($requisition->fresh())
        ->status->toBe(PurchaseRequisition::STATUS_APPROVED)
        ->approved_by->toBe($manager->id);
});

it('shows the raiser a note instead of the Received and Approve buttons', function () {
    $raiser = step0User('management', ['store.requisition.review', 'store.requisition.approve']);
    $received = step0Requisition($raiser, PurchaseRequisition::STATUS_SUBMITTED);
    $reviewed = step0Requisition($raiser, PurchaseRequisition::STATUS_STORE_REVIEWED);

    $this->actingAs($raiser)->get(route('store.stock.requisitions.show', $received))
        ->assertOk()
        ->assertDontSee(route('store.stock.requisitions.received', $received))
        ->assertSee('another person must receive it');

    $this->actingAs($raiser)->get(route('store.stock.requisitions.show', $reviewed))
        ->assertOk()
        ->assertDontSee(route('store.stock.requisitions.approve', $reviewed))
        ->assertSee('another person must approve it');
});
