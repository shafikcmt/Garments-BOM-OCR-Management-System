<?php

use App\Models\Buyer;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ImportUnmappedColumn;
use App\Models\User;
use App\Services\ImportColumnMatcher;
use App\Services\UnmappedImportColumns;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

/** M-12: the file columns the system has no header for. */
beforeEach(function () {
    Storage::fake('local');
    $this->admin = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('admin', 'web'));
    $merchantRole = Role::findOrCreate('merchant', 'web');
    $this->merchant = User::factory()->create(['status' => 1])->assignRole($merchantRole);
    $this->buyer = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    $this->style = ExcelHeader::create(['header_name' => 'Style', 'header_key' => 'style_name', 'owner_role_id' => $merchantRole->id, 'position' => 1]);
    $this->remark = ExcelHeader::create(['header_name' => 'Merchant Remark', 'header_key' => 'merchant_remark', 'owner_role_id' => $merchantRole->id, 'position' => 2]);
    $this->vendor = ExcelHeader::create(['header_name' => 'Vendor Name', 'header_key' => 'vendor_name', 'owner_role_id' => Role::findOrCreate('supply_chain', 'web')->id, 'position' => 3]);
});

it('records only the columns that match no header, and counts repeats per buyer', function () {
    $svc = app(UnmappedImportColumns::class);
    $titles = [1 => 'Style', 2 => 'Fabric Mill Notes', 3 => 'Vendor Name', 4 => 'Merchant Remark', 5 => 'Wash   Care'];

    // Style is imported; Vendor Name and Merchant Remark match headers (skipped on purpose); two are unknown.
    expect($svc->record($this->buyer->id, $titles, [1 => $this->style->id], null))->toBe(2);
    expect(ImportUnmappedColumn::pluck('title')->all())->toEqualCanonicalizing(['Fabric Mill Notes', 'Wash   Care']);

    $svc->record($this->buyer->id, [2 => 'FABRIC MILL NOTES'], [], null);
    $row = ImportUnmappedColumn::where('title', 'Fabric Mill Notes')->first();
    expect($row->times_seen)->toBe(2)->and(ImportUnmappedColumn::count())->toBe(2);
});

it('teaches a title as an Other Name so the next file matches it, for every buyer', function () {
    $svc = app(UnmappedImportColumns::class);
    $other = Buyer::create(['buyer_name' => 'Macys', 'is_active' => true]);
    $svc->record($this->buyer->id, [1 => 'Mill Notes'], [], null);
    $svc->record($other->id, [1 => 'mill notes'], [], null);

    $this->actingAs($this->admin)->post(route('admin.unmapped.teach'), ['title_key' => ImportUnmappedColumn::first()->title_key, 'header_id' => $this->remark->id])->assertSessionHas('success');

    expect($this->remark->fresh()->aliasList())->toContain('Mill Notes')
        ->and(ImportUnmappedColumn::where('status', 'mapped')->count())->toBe(2)
        ->and(app(ImportColumnMatcher::class)->headerKeys($this->remark->fresh()))->toContain(app(ImportColumnMatcher::class)->key('Mill Notes'));

    // A later file with the same title is no longer unknown.
    expect($svc->record($this->buyer->id, [1 => 'Mill Notes'], [], null))->toBe(0);
});

it('ignores and reopens a title, and shows the lists to Admin only', function () {
    $svc = app(UnmappedImportColumns::class);
    $svc->record($this->buyer->id, [1 => 'Colour Story'], [], null);
    $key = ImportUnmappedColumn::first()->title_key;

    $this->actingAs($this->admin)->get(route('admin.unmapped.index'))->assertOk()->assertSee('Colour Story')->assertSee('Ann Taylor');
    $this->post(route('admin.unmapped.ignore'), ['title_key' => $key])->assertSessionHas('success');
    $this->get(route('admin.unmapped.index'))->assertDontSee('Colour Story');
    $this->get(route('admin.unmapped.index', ['status' => 'ignored']))->assertSee('Colour Story');
    $this->post(route('admin.unmapped.reopen'), ['title_key' => $key])->assertSessionHas('success');
    expect(ImportUnmappedColumn::first()->status)->toBe('open');

    $this->post(route('admin.unmapped.teach'), ['title_key' => $key])->assertSessionHasErrors('header_id');
    $this->get(route('admin.headers.create', ['name' => 'Colour Story']))->assertOk()->assertSee('Colour Story');

    $this->actingAs($this->merchant)->get(route('admin.unmapped.index'))->assertForbidden();
    $this->post(route('admin.unmapped.ignore'), ['title_key' => $key])->assertForbidden();
});

it('records the unknown columns when a file is imported through the wizard', function () {
    $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $book->getActiveSheet()->setTitle('Database');
    foreach (['Style', 'Merchant Remark', 'Fabric Mill Notes'] as $i => $t) {
        $sheet->setCellValue([$i + 1, 1], $t);
        $sheet->setCellValue([$i + 1, 2], $i === 0 ? '748268' : 'x');
    }
    $path = tempnam(sys_get_temp_dir(), 'um') . '.xlsx';
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);

    $this->actingAs($this->merchant)->post(route('merchant.import.upload'), ['buyer_id' => $this->buyer->id, 'season_code' => 'SP27', 'file' => new UploadedFile($path, 'f.xlsx', null, null, true)]);
    $this->post(route('merchant.import.preview'), ['sheet' => 'Database', 'header_row' => 1, 'map' => [1 => $this->style->id, 2 => $this->remark->id, 3 => '']]);
    $this->post(route('merchant.import.confirm'))->assertSessionHas('success');

    expect(ImportUnmappedColumn::pluck('title')->all())->toBe(['Fabric Mill Notes'])
        ->and(ImportUnmappedColumn::first()->last_file_id)->toBe(ExcelFile::firstOrFail()->id);
});
