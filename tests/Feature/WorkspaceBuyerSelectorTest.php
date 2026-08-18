<?php

use App\Models\Buyer;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * The upload panel's buyer field, which has three shapes.
 *
 * The one that must not change is the middle of the three by population and
 * the first by importance: a merchant with exactly one buyer still sees a
 * read-only chip and is still asked nothing. Multi-buyer is a new state added
 * beside it, not a redesign of it.
 */
function workspaceSelectorUser(array $attributes = [], string $role = 'merchant'): User
{
    Role::findOrCreate($role, 'web');

    return User::factory()->create($attributes)->assignRole($role);
}

beforeEach(function () {
    $this->buyerA = Buyer::create(['buyer_name' => 'Alpha Buyer', 'is_active' => true]);
    $this->buyerB = Buyer::create(['buyer_name' => 'Beta Buyer', 'is_active' => true]);
    $this->other = Buyer::create(['buyer_name' => 'Not Mine Buyer', 'is_active' => true]);
});

it('shows a read-only chip and no chooser for a single-buyer merchant', function () {
    $user = workspaceSelectorUser(['buyer_id' => $this->buyerA->id, 'can_upload' => true]);
    $user->buyers()->attach($this->buyerA->id);

    $this->actingAs($user->fresh())
        ->get(route('merchant.workspace'))
        ->assertOk()
        ->assertSee('Tagged to your assigned buyer automatically')
        ->assertSee('Alpha Buyer')
        ->assertDontSee('merchantUploadBuyer', false);
});

it('shows a chooser limited to their own buyers for a multi-buyer merchant', function () {
    $user = workspaceSelectorUser(['buyer_id' => $this->buyerA->id, 'can_upload' => true]);
    $user->buyers()->attach([$this->buyerA->id, $this->buyerB->id]);

    $response = $this->actingAs($user->fresh())
        ->get(route('merchant.workspace'))
        ->assertOk()
        ->assertSee('merchantUploadBuyer', false)
        ->assertSee('You are assigned to 2 buyers')
        ->assertDontSee('Tagged to your assigned buyer automatically');

    // Their own, and nobody else's, in the list.
    $response->assertSee('Alpha Buyer')->assertSee('Beta Buyer');
    expect($response->getContent())->not->toContain('value="'.$this->other->id.'"');
});

it('offers no pre-selection, so no file is tagged to a buyer nobody picked', function () {
    $user = workspaceSelectorUser(['buyer_id' => $this->buyerA->id, 'can_upload' => true]);
    $user->buyers()->attach([$this->buyerA->id, $this->buyerB->id]);

    $html = $this->actingAs($user->fresh())->get(route('merchant.workspace'))->getContent();

    // Just the buyer field — "selected" appears elsewhere on the page.
    preg_match('/<select[^>]*id="merchantUploadBuyer".*?<\/select>/s', $html, $m);
    $select = $m[0] ?? '';

    expect($select)->toContain('<option value="">Select buyer</option>')
        ->and($select)->not->toContain('selected');
});

it('shows a department admin a chooser of the buyers they own', function () {
    $admin = workspaceSelectorUser(['is_department_admin' => true]);
    $this->buyerA->update(['department_admin_id' => $admin->id]);
    $this->buyerB->update(['department_admin_id' => $admin->id]);

    $response = $this->actingAs($admin->fresh())
        ->get(route('merchant.workspace'))
        ->assertOk()
        ->assertSee('merchantUploadBuyer', false);

    expect($response->getContent())->not->toContain('value="'.$this->other->id.'"');
});

it('leaves an unscoped merchant the full list, exactly as before', function () {
    $this->actingAs(workspaceSelectorUser())
        ->get(route('merchant.workspace'))
        ->assertOk()
        ->assertSee('merchantUploadBuyer', false)
        ->assertSee('Not Mine Buyer')
        ->assertDontSee('Tagged to your assigned buyer automatically')
        ->assertDontSee('You are assigned to');
});

/**
 * A buyer deactivated after the assignment was made. The general dropdown
 * drops inactive buyers, but a merchant scoped to one still has to be able to
 * upload for it — otherwise deactivating a buyer silently stops their team
 * working.
 */
it('keeps an assigned buyer available even once it is deactivated', function () {
    $this->buyerB->update(['is_active' => false]);

    $user = workspaceSelectorUser(['buyer_id' => $this->buyerA->id, 'can_upload' => true]);
    $user->buyers()->attach([$this->buyerA->id, $this->buyerB->id]);

    $this->actingAs($user->fresh())
        ->get(route('merchant.workspace'))
        ->assertOk()
        ->assertSee('Beta Buyer');
});
