<?php

use App\Models\Supplier;
use App\Models\User;
use Spatie\Permission\Models\Role;

function vendorMerchant(): User
{
    Role::findOrCreate('merchant', 'web');

    return User::factory()->create()->assignRole('merchant');
}

it('lets a merchant add a vendor as a draft, and ignores bank fields posted to the form', function () {
    $this->actingAs(vendorMerchant())->post(route('merchant.vendors.store'), [
        'supplier_name' => 'IDEAL FASTENER',
        'supplier_code' => '',
        'bank_name' => 'Dutch-Bangla Bank',
        'bank_account_number' => '1234567890',
    ])->assertRedirect();

    $vendor = Supplier::firstOrFail();
    expect($vendor->supplier_code)->toBeNull()
        ->and($vendor->approval_status)->toBe('draft')
        ->and($vendor->vendor_status)->toBe('inactive')
        ->and($vendor->bank_name)->toBeNull()
        ->and($vendor->bank_account_number)->toBeNull()
        ->and($vendor->bankAccounts()->count())->toBe(0);   // bank accounts only via approved requests
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

it('shows the vendor form with the master fields and no bank fields', function () {
    $user = vendorMerchant();

    $this->withoutVite()->actingAs($user)->get(route('merchant.vendors.create'))
        ->assertOk()->assertSee('Vendor Type')->assertSee('Default Payment Terms')->assertDontSee('Account Number');
});


it('lists the vendors, with a search, and never shows an account number', function () {
    Supplier::create(['supplier_name' => 'IDEAL FASTENER', 'supplier_code' => 'SUP-1', 'city' => 'Dhaka', 'bank_name' => 'Dutch-Bangla Bank', 'bank_branch' => 'Motijheel', 'bank_account_number' => '1234567890', 'is_active' => true]);
    Supplier::create(['supplier_name' => 'LOCAL TRIMS', 'is_active' => true]);
    Supplier::create(['supplier_name' => 'OLD SUPPLIER', 'is_active' => false]);
    $user = vendorMerchant();

    $page = $this->withoutVite()->actingAs($user)->get(route('merchant.vendors.index'))->assertOk();

    $page->assertSee('IDEAL FASTENER')->assertSee('LOCAL TRIMS')->assertSee('OLD SUPPLIER')
        ->assertDontSee('1234567890')                 // a legacy number is never shown on the list
        ->assertDontSee('Dutch-Bangla Bank')
        ->assertViewHas('totals', fn ($t) => $t === ['all' => 3, 'active' => 2, 'pending' => 0]);
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

it('lets a merchant edit a vendor and keeps the vendor\'s own code valid', function () {
    $vendor = Supplier::create(['supplier_name' => 'IDEAL FASTENER', 'supplier_code' => 'SUP-1', 'is_active' => true]);
    $user = vendorMerchant();

    // The form opens filled in, and the list carries an Edit button.
    $this->withoutVite()->actingAs($user)->get(route('merchant.vendors.edit', $vendor))
        ->assertOk()->assertSee('IDEAL FASTENER')->assertSee('SUP-1');
    $this->withoutVite()->actingAs($user)->get(route('merchant.vendors.index'))
        ->assertSee(route('vendors.show', $vendor), false)->assertSee('Open');

    $this->actingAs($user)->put(route('merchant.vendors.update', $vendor), [
        'supplier_name' => 'IDEAL FASTENER LTD',
        'supplier_code' => 'SUP-1',               // unchanged: must not clash with itself
        'country' => 'Bangladesh',
    ])->assertRedirect(route('vendors.show', $vendor));

    $vendor->refresh();
    expect($vendor->supplier_name)->toBe('IDEAL FASTENER LTD')
        ->and($vendor->supplier_code)->toBe('SUP-1')
        ->and($vendor->country)->toBe('Bangladesh');
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

/* ---------------------------------------------------------------- Excel */

function vendorXlsx(array $rows): \Illuminate\Http\UploadedFile
{
    $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $book->getActiveSheet();
    $sheet->fromArray(\App\Imports\VendorListImport::COLUMNS, null, 'A1');
    $sheet->fromArray($rows, null, 'A2');
    $path = tempnam(sys_get_temp_dir(), 'vend') . '.xlsx';
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);

    return new \Illuminate\Http\UploadedFile($path, 'vendors.xlsx', null, null, true);
}

function vendorRow(array $over = []): array
{
    $row = array_fill(0, count(\App\Imports\VendorListImport::COLUMNS), '');
    foreach ($over as $i => $v) { $row[$i] = $v; }

    return $row;
}

it('imports vendors from the old single-sheet file: contact, address and category rows are created, bank rows need bank permission', function () {
    $user = vendorMerchant();

    $this->actingAs($user)->post(route('merchant.vendors.import'), ['file' => vendorXlsx([
        vendorRow([0 => 'IDEAL FASTENER', 1 => 'SUP-1', 4 => 'ideal@example.com', 5 => '+8801711000111', 7 => 'Dhaka', 12 => '3', 13 => 'Brac Bank', 14 => 'Gulshan', 16 => '55667788', 18 => 'BRAKBDDH']),
        vendorRow([0 => 'LOCAL TRIMS']),
    ])])->assertRedirect()->assertSessionHas('success');

    expect(Supplier::count())->toBe(2);
    $ideal = Supplier::where('supplier_code', 'SUP-1')->firstOrFail();
    expect($ideal->supplier_name)->toBe('IDEAL FASTENER')
        ->and($ideal->contacts()->first()->email)->toBe('ideal@example.com')
        ->and($ideal->addresses()->first()->city)->toBe('Dhaka')
        ->and($ideal->bankAccounts()->count())->toBe(0)           // merchant has no bank permission: skipped
        ->and((float) $ideal->tolerance_percent)->toBe(3.0)
        ->and($ideal->approval_status)->toBe('draft');
});

it('imports nothing when one row is wrong, and says which', function () {
    $this->actingAs(vendorMerchant())->post(route('merchant.vendors.import'), ['file' => vendorXlsx([
        vendorRow([0 => 'GOOD VENDOR']),
        vendorRow([0 => 'BAD EMAIL', 4 => 'not-an-email']),
        vendorRow([0 => '', 7 => 'Dhaka']),
        vendorRow([0 => 'BAD TOLERANCE', 12 => '250']),
    ])])->assertSessionHas('warning')->assertSessionHas('import_errors', fn ($e) => count($e) === 3);

    expect(Supplier::count())->toBe(0);
});

it('skips a vendor already on the list instead of overwriting it, and rejects a code that is taken', function () {
    Supplier::create(['supplier_name' => 'IDEAL FASTENER', 'supplier_code' => 'SUP-1', 'city' => 'Dhaka', 'is_active' => true]);
    $user = vendorMerchant();

    $this->actingAs($user)->post(route('merchant.vendors.import'), ['file' => vendorXlsx([
        vendorRow([0 => 'ideal fastener', 7 => 'Chattogram']),   // same name, any case: skipped
        vendorRow([0 => 'NEW ONE']),
    ])])->assertSessionHas('success')->assertSessionHas('import_skipped', fn ($s) => count($s) === 1);

    expect(Supplier::count())->toBe(2)
        ->and(Supplier::where('supplier_name', 'IDEAL FASTENER')->value('city'))->toBe('Dhaka');   // untouched

    $this->actingAs($user)->post(route('merchant.vendors.import'), ['file' => vendorXlsx([
        vendorRow([0 => 'ANOTHER', 1 => 'SUP-1']),
    ])])->assertSessionHas('import_errors');
    expect(Supplier::count())->toBe(2);
});

it('ignores the template example row and a masked account number from an export', function () {
    $this->actingAs(vendorMerchant())->post(route('merchant.vendors.import'), ['file' => vendorXlsx([
        \App\Imports\VendorListImport::SAMPLE_ROW,
        vendorRow([0 => 'FROM EXPORT', 13 => 'Brac Bank', 16 => '****7890']),
    ])])->assertSessionHas('success');

    expect(Supplier::count())->toBe(1)->and(Supplier::first()->bankAccounts()->count())->toBe(0);
});

it('exports the vendor workbook, filtered by the search, without bank data for a merchant', function () {
    $ideal = Supplier::create(['supplier_name' => 'IDEAL FASTENER', 'is_active' => true]);
    $ideal->contacts()->create(['name' => 'Rahim', 'email' => 'r@example.com', 'is_primary' => true]);
    Supplier::create(['supplier_name' => 'LOCAL TRIMS', 'is_active' => false]);
    $user = vendorMerchant();

    $read = function (array $query = []) use ($user) {
        $response = $this->actingAs($user)->get(route('merchant.vendors.export', $query))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'exp') . '.xlsx';
        file_put_contents($path, $response->streamedContent());

        return \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
    };

    $book = $read();
    expect($book->getSheetNames())->toBe(['Vendors', 'Contacts', 'Addresses', 'Item Categories']);   // no Bank Accounts sheet
    expect(collect($book->getSheetByName('Vendors')->toArray())->pluck(0)->all())->toBe(['Vendor Name*', 'IDEAL FASTENER', 'LOCAL TRIMS']);
    expect($book->getSheetByName('Contacts')->toArray()[1][1])->toBe('Rahim');

    expect(collect($read(['q' => 'local'])->getSheetByName('Vendors')->toArray())->pluck(0)->all())->toBe(['Vendor Name*', 'LOCAL TRIMS']);
});

it('offers a template and keeps import, export and template to merchants', function () {
    $this->actingAs(vendorMerchant())->get(route('merchant.vendors.template'))->assertOk();

    $other = User::factory()->create();
    $this->actingAs($other)->get(route('merchant.vendors.export'))->assertForbidden();
    $this->actingAs($other)->get(route('merchant.vendors.template'))->assertForbidden();
    $this->actingAs($other)->post(route('merchant.vendors.import'), ['file' => vendorXlsx([vendorRow([0 => 'X'])])])->assertForbidden();
    expect(Supplier::count())->toBe(0);
});

it('shows Import Excel, Export Excel and the template link on the vendor list', function () {
    $this->withoutVite()->actingAs(vendorMerchant())->get(route('merchant.vendors.index'))
        ->assertOk()->assertSee('Export Excel')->assertSee('Import Excel')->assertSee('Download Template')
        ->assertSee(route('merchant.vendors.import'), false);
});
