<?php

use App\Models\SupplierOption;
use App\Models\User;

it('keeps Payment Terms as an Admin-managed option list', function () {
    expect(SupplierOption::TYPES)->toHaveKey('payment_term');

    SupplierOption::create(['type' => 'payment_term', 'name' => 'LC at sight', 'sort_order' => 1]);
    SupplierOption::create(['type' => 'payment_term', 'name' => 'TT before shipment', 'sort_order' => 2]);

    expect(SupplierOption::names('payment_term'))->toBe(['LC at sight', 'TT before shipment'])
        // A value a row already holds is kept even after it was removed from the list.
        ->and(SupplierOption::names('payment_term', ['Old Term']))->toContain('Old Term');
});

it('answers the vendor defaults lookup with an empty result for an unknown vendor', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson(route('uploaded-files.supplier_defaults', ['name' => 'No Such Vendor']))
        ->assertOk()
        ->assertExactJson(['found' => false, 'incoterm' => '', 'payment_term' => '', 'ship_mode' => '']);

    $this->actingAs($user)
        ->getJson(route('uploaded-files.supplier_defaults'))
        ->assertOk()
        ->assertJson(['found' => false]);
});
