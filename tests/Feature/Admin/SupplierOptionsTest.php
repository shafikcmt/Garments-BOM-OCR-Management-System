<?php

use App\Models\Supplier;
use App\Models\SupplierOption;
use App\Models\User;
use Spatie\Permission\Models\Role;

function optionsAdmin(): User
{
    Role::findOrCreate('admin', 'web');

    return User::factory()->create()->assignRole('admin');
}

it('starts with the standard Item Type, Incoterm and Ship Mode lists', function () {
    expect(SupplierOption::names('item_type'))->toContain('Fabric', 'Accessories')
        ->and(SupplierOption::names('incoterm'))->toContain('FOB')
        ->and(SupplierOption::names('ship_mode'))->toContain('SEA');
});

it('lets admin add an option and refuses a duplicate in any letter case', function () {
    $this->actingAs(optionsAdmin())->post(route('admin.supplier-options.store'), ['type' => 'ship_mode', 'name' => 'Rail'])
        ->assertSessionHasNoErrors();
    expect(SupplierOption::names('ship_mode'))->toContain('Rail');

    $this->post(route('admin.supplier-options.store'), ['type' => 'ship_mode', 'name' => 'RAIL'])->assertSessionHasErrors('name');
    $this->post(route('admin.supplier-options.store'), ['type' => 'nonsense', 'name' => 'X'])->assertSessionHasErrors('type');
});

it('lets admin delete an option without touching vendors that use it', function () {
    $vendor = Supplier::create(['supplier_name' => 'ACME', 'incoterm' => 'CNF']);
    $option = SupplierOption::where('type', 'incoterm')->where('name', 'CNF')->firstOrFail();

    $this->actingAs(optionsAdmin())->delete(route('admin.supplier-options.destroy', $option))->assertRedirect();

    expect(SupplierOption::where('type', 'incoterm')->pluck('name'))->not->toContain('CNF')
        ->and($vendor->fresh()->incoterm)->toBe('CNF')
        // still offered on this vendor's own form
        ->and(SupplierOption::names('incoterm', ['CNF']))->toContain('CNF');
});

it('saves several item types for one vendor', function () {
    $this->actingAs(optionsAdmin())->post(route('admin.suppliers.store'), [
        'supplier_name' => 'BOTH LTD', 'item_types' => ['Fabric', 'Accessories', ''],
    ])->assertSessionHasNoErrors();

    expect(Supplier::firstOrFail()->item_type)->toBe('Fabric, Accessories');
});

it('shows the ticked item types when the vendor form is opened', function () {
    $vendor = Supplier::create(['supplier_name' => 'BOTH LTD', 'item_type' => 'Fabric, Accessories']);

    $html = $this->actingAs(optionsAdmin())->get(route('admin.suppliers.edit', $vendor))->assertOk()->getContent();

    expect($html)->toMatch('/value="Fabric"[^>]*checked/')->toMatch('/value="Accessories"[^>]*checked/');
});

it('still accepts a single item_type text value', function () {
    $this->actingAs(optionsAdmin())->post(route('admin.suppliers.store'), ['supplier_name' => 'OLD STYLE', 'item_type' => 'Zipper'])
        ->assertSessionHasNoErrors();

    expect(Supplier::firstOrFail()->item_type)->toBe('Zipper');
});

it('gives admin the vendor Excel export, template and import', function () {
    $admin = optionsAdmin();
    Supplier::create(['supplier_name' => 'ACME']);

    $this->actingAs($admin)->get(route('admin.suppliers.index'))->assertOk()->assertSee('Export Excel')->assertSee('Import Excel');
    $this->get(route('admin.suppliers.export'))->assertOk();
    $this->get(route('admin.suppliers.template'))->assertOk();
    $this->post(route('admin.suppliers.import'), [])->assertSessionHasErrors('file');
});
