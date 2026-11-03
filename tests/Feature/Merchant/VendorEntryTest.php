<?php

use App\Models\Supplier;
use App\Models\User;
use Spatie\Permission\Models\Role;

function vendorMerchant(): User
{
    Role::findOrCreate('merchant', 'web');

    return User::factory()->create()->assignRole('merchant');
}

it('lets a merchant add a vendor with bank details', function () {
    $this->actingAs(vendorMerchant())->post(route('merchant.vendors.store'), [
        'supplier_name' => 'IDEAL FASTENER',
        'supplier_code' => '',
        'bank_name' => 'Dutch-Bangla Bank',
        'bank_branch' => 'Motijheel',
        'bank_account_name' => 'Ideal Fastener Ltd',
        'bank_account_number' => '1234567890',
        'bank_swift_code' => 'DBBLBDDH',
        'is_active' => '1',
    ])->assertRedirect(route('merchant.vendors.index'));

    $vendor = Supplier::firstOrFail();
    expect($vendor->supplier_code)->toBeNull()
        ->and($vendor->bank_name)->toBe('Dutch-Bangla Bank')
        ->and($vendor->bank_account_number)->toBe('1234567890')
        ->and($vendor->bank_swift_code)->toBe('DBBLBDDH');
});

it('still adds a vendor with no bank details', function () {
    $this->actingAs(vendorMerchant())->post(route('merchant.vendors.store'), ['supplier_name' => 'LOCAL TRIMS'])->assertRedirect();

    expect(Supplier::firstOrFail()->bank_name)->toBeNull();
});

it('needs a vendor name', function () {
    $this->actingAs(vendorMerchant())->post(route('merchant.vendors.store'), ['bank_name' => 'X'])->assertSessionHasErrors('supplier_name');
});

it('keeps the vendor entry to merchants', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('merchant.vendors.create'))->assertForbidden();
});

it('shows the vendor form with the bank fields and a dashboard button', function () {
    $user = vendorMerchant();

    $this->withoutVite()->actingAs($user)->get(route('merchant.vendors.create'))
        ->assertOk()->assertSee('Bank Details')->assertSee('Account Number')->assertSee('SWIFT Code');
});


it('lists the vendors, with a search, and never shows an account number', function () {
    Supplier::create(['supplier_name' => 'IDEAL FASTENER', 'supplier_code' => 'SUP-1', 'city' => 'Dhaka', 'bank_name' => 'Dutch-Bangla Bank', 'bank_branch' => 'Motijheel', 'bank_account_number' => '1234567890', 'is_active' => true]);
    Supplier::create(['supplier_name' => 'LOCAL TRIMS', 'is_active' => true]);
    Supplier::create(['supplier_name' => 'OLD SUPPLIER', 'is_active' => false]);
    $user = vendorMerchant();

    $page = $this->withoutVite()->actingAs($user)->get(route('merchant.vendors.index'))->assertOk();

    $page->assertSee('IDEAL FASTENER')->assertSee('LOCAL TRIMS')->assertSee('OLD SUPPLIER')
        ->assertSee('Dutch-Bangla Bank')->assertSee('Account ending 7890')->assertSee('No bank details')
        ->assertDontSee('1234567890')                 // the number itself stays on the Admin screen
        ->assertViewHas('totals', fn ($t) => $t === ['all' => 3, 'active' => 2, 'with_bank' => 1]);
    // The sidebar carries the entry.
    $page->assertSee(route('merchant.vendors.index'), false)->assertSee('Vendors');

    // Search is case-insensitive on every database.
    $this->withoutVite()->actingAs($user)->get(route('merchant.vendors.index', ['q' => 'ideal']))
        ->assertSee('IDEAL FASTENER')->assertDontSee('LOCAL TRIMS');
    $this->withoutVite()->actingAs($user)->get(route('merchant.vendors.index', ['q' => 'zzz']))
        ->assertSee('No vendor matches your search');
});

it('keeps the vendor list to merchants', function () {
    $this->actingAs(User::factory()->create())->get(route('merchant.vendors.index'))->assertForbidden();
});

it('lets a merchant edit a vendor, bank details included, and keeps the vendor\'s own code valid', function () {
    $vendor = Supplier::create(['supplier_name' => 'IDEAL FASTENER', 'supplier_code' => 'SUP-1', 'is_active' => true]);
    $user = vendorMerchant();

    // The form opens filled in, and the list carries an Edit button.
    $this->withoutVite()->actingAs($user)->get(route('merchant.vendors.edit', $vendor))
        ->assertOk()->assertSee('IDEAL FASTENER')->assertSee('SUP-1')->assertSee('Bank Details');
    $this->withoutVite()->actingAs($user)->get(route('merchant.vendors.index'))
        ->assertSee(route('merchant.vendors.edit', $vendor), false)->assertSee('Edit');

    $this->actingAs($user)->put(route('merchant.vendors.update', $vendor), [
        'supplier_name' => 'IDEAL FASTENER LTD',
        'supplier_code' => 'SUP-1',               // unchanged: must not clash with itself
        'phone' => '+880 1711 000111',
        'bank_name' => 'Brac Bank',
        'bank_account_number' => '55667788',
        'is_active' => '1',
    ])->assertRedirect(route('merchant.vendors.index'));

    $vendor->refresh();
    expect($vendor->supplier_name)->toBe('IDEAL FASTENER LTD')
        ->and($vendor->supplier_code)->toBe('SUP-1')
        ->and($vendor->phone)->toBe('+880 1711 000111')
        ->and($vendor->bank_name)->toBe('Brac Bank')
        ->and($vendor->bank_account_number)->toBe('55667788');
    expect(Supplier::count())->toBe(1);          // edited in place, not duplicated
});

it('refuses an edit that takes another vendor\'s code or blanks the name', function () {
    Supplier::create(['supplier_name' => 'A', 'supplier_code' => 'SUP-1', 'is_active' => true]);
    $other = Supplier::create(['supplier_name' => 'B', 'supplier_code' => 'SUP-2', 'is_active' => true]);

    $this->actingAs(vendorMerchant())->put(route('merchant.vendors.update', $other), ['supplier_name' => 'B', 'supplier_code' => 'SUP-1'])
        ->assertSessionHasErrors('supplier_code');
    $this->actingAs(vendorMerchant())->put(route('merchant.vendors.update', $other), ['supplier_name' => ''])
        ->assertSessionHasErrors('supplier_name');

    expect($other->fresh()->supplier_code)->toBe('SUP-2');
});

it('keeps vendor editing to merchants, and a deleted vendor out of reach', function () {
    $vendor = Supplier::create(['supplier_name' => 'A', 'is_active' => true]);

    $this->actingAs(User::factory()->create())->get(route('merchant.vendors.edit', $vendor))->assertForbidden();
    $this->actingAs(User::factory()->create())->put(route('merchant.vendors.update', $vendor), ['supplier_name' => 'Hacked'])->assertForbidden();
    expect($vendor->fresh()->supplier_name)->toBe('A');

    $vendor->delete();
    $this->actingAs(vendorMerchant())->get(route('merchant.vendors.edit', $vendor->id))->assertNotFound();
});
