<?php

use App\Models\Buyer;
use App\Models\BuyerImportProfile;
use App\Models\ExcelHeader;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Admin > Buyers > Import Profiles: rename, set the style rule, copy, delete.
 */
beforeEach(function () {
    $this->admin = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('admin', 'web'));
    $this->buyer = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    $header = ExcelHeader::create(['header_name' => 'Style', 'header_key' => 'style_name', 'owner_role_id' => Role::findOrCreate('merchant', 'web')->id, 'position' => 1]);
    $this->profile = BuyerImportProfile::create(['buyer_id' => $this->buyer->id, 'name' => 'SU27', 'sheet_name' => 'Database', 'header_row' => 3]);
    $this->profile->columns()->create(['source_header' => 'Style', 'source_key' => 'style', 'excel_header_id' => $header->id]);
    $this->profile->columns()->create(['source_header' => 'Vendor', 'source_key' => 'vendor', 'excel_header_id' => null]);
});

it('lists a buyer\'s profiles with their column matching', function () {
    $this->withoutVite()->actingAs($this->admin)->get(route('admin.buyers.profiles.index', $this->buyer))
        ->assertOk()->assertSee('SU27')->assertSee('1 of 2 columns matched')->assertSee('Skipped');
});

it('renames a profile and sets its style rule and fit words', function () {
    $this->actingAs($this->admin)->put(route('admin.buyers.profiles.update', [$this->buyer, $this->profile]), [
        'name' => 'SU27 Missy/Petite', 'mother_source' => 'code', 'fit_words' => 'PETITE, TALL',
    ])->assertRedirect();

    $this->profile->refresh();
    expect($this->profile->name)->toBe('SU27 Missy/Petite')->and($this->profile->mother_source)->toBe('code')->and($this->profile->fit_words)->toBe('PETITE, TALL');

    $this->actingAs($this->admin)->put(route('admin.buyers.profiles.update', [$this->buyer, $this->profile]), ['name' => 'SU27 Missy/Petite', 'mother_source' => 'auto'])->assertRedirect();
    expect($this->profile->fresh()->mother_source)->toBeNull();
});

it('copies a profile with its columns, then deletes the copy', function () {
    $this->actingAs($this->admin)->post(route('admin.buyers.profiles.copy', [$this->buyer, $this->profile]))->assertRedirect();

    $copy = BuyerImportProfile::where('name', 'SU27 (copy)')->firstOrFail();
    expect($copy->columns()->count())->toBe(2)->and($this->profile->columns()->count())->toBe(2);

    $this->actingAs($this->admin)->delete(route('admin.buyers.profiles.destroy', [$this->buyer, $copy]))->assertRedirect();
    expect(BuyerImportProfile::count())->toBe(1);
});

it('does not touch another buyer\'s profile', function () {
    $other = Buyer::create(['buyer_name' => 'Macy\'s', 'is_active' => true]);

    $this->actingAs($this->admin)->delete(route('admin.buyers.profiles.destroy', [$other, $this->profile]))->assertNotFound();
    expect(BuyerImportProfile::count())->toBe(1);
});

it('keeps profile management to admins', function () {
    $merchant = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('merchant', 'web'));

    $this->actingAs($merchant)->get(route('admin.buyers.profiles.index', $this->buyer))->assertForbidden();
});
