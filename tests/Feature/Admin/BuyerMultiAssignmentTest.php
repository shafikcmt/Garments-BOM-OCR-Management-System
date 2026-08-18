<?php

use App\Models\Buyer;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Admin › Buyers, and the data-loss bug it used to carry.
 *
 * BuyerController ran releaseAdminFromOtherBuyers() after every create and
 * every update: naming an admin on a buyer nulled department_admin_id on
 * every OTHER buyer they held. A super admin giving one merchant admin two
 * buyers destroyed the first assignment by making the second, with no warning
 * and nothing on the screen to say so.
 *
 * The regression these tests exist for is that assignment surviving. Ordinary
 * CRUD is asserted alongside it only far enough to prove the hook's removal
 * did not take the validation with it.
 */
beforeEach(function () {
    foreach (['admin', 'merchant'] as $role) {
        Role::findOrCreate($role, 'web');
    }

    $this->superAdmin = User::factory()->create(['status' => 1])->assignRole('admin');

    $this->merchantAdmin = User::factory()
        ->create(['status' => 1, 'is_department_admin' => true])
        ->assignRole('merchant');
});

/** The fields the buyer form always posts, so each test varies only its subject. */
function buyerFormPayload(array $overrides = []): array
{
    return array_merge([
        'buyer_name' => 'Buyer '.uniqid(),
        'is_active' => 1,
        // The hidden marker that tells the controller the ownership field was
        // on the form at all; without it department_admin_id is ignored.
        'department_admin_control' => 1,
    ], $overrides);
}

it('keeps the first buyer when the same admin is assigned a second', function () {
    $first = Buyer::create(['buyer_name' => 'First', 'department_admin_id' => $this->merchantAdmin->id]);
    $second = Buyer::create(['buyer_name' => 'Second']);

    $this->actingAs($this->superAdmin)
        ->put(route('admin.buyers.update', $second), buyerFormPayload([
            'buyer_name' => 'Second',
            'department_admin_id' => $this->merchantAdmin->id,
        ]))
        ->assertRedirect(route('admin.buyers.index'));

    expect($first->fresh()->department_admin_id)->toBe($this->merchantAdmin->id)
        ->and($second->fresh()->department_admin_id)->toBe($this->merchantAdmin->id)
        ->and($this->merchantAdmin->fresh()->merchantBuyerIds())
            ->toBe([$first->id, $second->id]);
});

it('keeps existing buyers when a newly created one names the same admin', function () {
    $existing = Buyer::create(['buyer_name' => 'Existing', 'department_admin_id' => $this->merchantAdmin->id]);

    $this->actingAs($this->superAdmin)
        ->post(route('admin.buyers.store'), buyerFormPayload([
            'buyer_name' => 'Brand New',
            'department_admin_id' => $this->merchantAdmin->id,
        ]))
        ->assertRedirect(route('admin.buyers.index'));

    expect($existing->fresh()->department_admin_id)->toBe($this->merchantAdmin->id)
        ->and(Buyer::where('department_admin_id', $this->merchantAdmin->id)->count())->toBe(2);
});

it('still lets an admin be released from a buyer by clearing the field', function () {
    $buyer = Buyer::create(['buyer_name' => 'Held', 'department_admin_id' => $this->merchantAdmin->id]);

    $this->actingAs($this->superAdmin)
        ->put(route('admin.buyers.update', $buyer), buyerFormPayload([
            'buyer_name' => 'Held',
            'department_admin_id' => '',
        ]));

    expect($buyer->fresh()->department_admin_id)->toBeNull();
});

/**
 * The validation the removed hook sat next to. Deleting one must not have
 * loosened the other — only a Merchant department-admin may own a buyer.
 */
it('still refuses to make a non-department-admin the owner', function () {
    $ordinary = User::factory()->create(['status' => 1])->assignRole('merchant');
    $buyer = Buyer::create(['buyer_name' => 'Unowned']);

    $this->actingAs($this->superAdmin)
        ->put(route('admin.buyers.update', $buyer), buyerFormPayload([
            'buyer_name' => 'Unowned',
            'department_admin_id' => $ordinary->id,
        ]));

    expect($buyer->fresh()->department_admin_id)->toBeNull();
});

it('still leaves ownership alone when the form did not carry the field', function () {
    $buyer = Buyer::create(['buyer_name' => 'Kept', 'department_admin_id' => $this->merchantAdmin->id]);

    $this->actingAs($this->superAdmin)
        ->put(route('admin.buyers.update', $buyer), [
            'buyer_name' => 'Kept Renamed',
            'is_active' => 1,
            // no department_admin_control
        ]);

    expect($buyer->fresh()->department_admin_id)->toBe($this->merchantAdmin->id);
});

// --- What the admin sees --------------------------------------------------

it('shows every owned buyer on the user edit screen', function () {
    Buyer::create(['buyer_name' => 'Hugo Boss', 'department_admin_id' => $this->merchantAdmin->id]);
    Buyer::create(['buyer_name' => 'Zara Group', 'department_admin_id' => $this->merchantAdmin->id]);

    $this->actingAs($this->superAdmin)
        ->get(route('admin.users.edit', $this->merchantAdmin))
        ->assertOk()
        ->assertSee('Assigned Buyers')
        ->assertSee('Hugo Boss')
        ->assertSee('Zara Group')
        // The caveat is gone: every owned buyer now carries full editing and
        // upload rights, so there is nothing left to warn about.
        ->assertDontSee('File editing and upload tagging currently use');
});

it('keeps the singular label and no caveat for a one-buyer admin', function () {
    Buyer::create(['buyer_name' => 'Hugo Boss', 'department_admin_id' => $this->merchantAdmin->id]);

    $this->actingAs($this->superAdmin)
        ->get(route('admin.users.edit', $this->merchantAdmin))
        ->assertOk()
        ->assertSee('Assigned Buyer')
        ->assertDontSee('Assigned Buyers')
        ->assertDontSee('File editing and upload tagging currently use');
});
