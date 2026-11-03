<?php

use App\Models\Buyer;
use App\Models\BuyerImportProfile;
use App\Models\BomItem;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\MotherStyle;
use App\Models\OrderLine;
use App\Models\User;
use App\Services\ImportColumnMatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Role;

/**
 * The import wizard: any layout in, matched once, remembered per buyer.
 */
beforeEach(function () {
    Storage::fake('local');

    $role = Role::findOrCreate('merchant', 'web');
    $this->merchant = User::factory()->create()->assignRole('merchant');
    $this->buyer = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);

    $pos = 1;
    foreach ([
        ['Style', 'style_name'], ['GMTS Color Name', 'gmts_color_name'], ['Material Description', 'material_description'],
        ['BOM Quantity', 'bom_quantity'], ['Season Name', 'season_name'],
    ] as [$name, $key]) {
        $this->headers[$key] = ExcelHeader::create([
            'header_name' => $name, 'header_key' => $key, 'owner_role_id' => $role->id, 'position' => $pos++,
        ]);
    }
    // A Supply Chain column, which the wizard must never offer.
    $this->vendorHeader = ExcelHeader::create(['header_name' => 'Vendor Name', 'header_key' => 'vendor_name', 'owner_role_id' => Role::findOrCreate('supply_chain', 'web')->id, 'position' => 9]);
});

/** A buyer-style workbook: a summary sheet first, the data on sheet two under a 3-row banner. */
function lotWorkbook(array $rows, array $titles = ['Style', 'GMTS Color Name', 'Material Description', 'BOM Qty', 'Vendor']): UploadedFile
{
    $book = new Spreadsheet();
    $book->getActiveSheet()->setTitle('Summary')->setCellValue('A1', 'Payment summary');
    $sheet = $book->createSheet()->setTitle('Database');
    $sheet->setCellValue('A1', 'Ann Taylor lot file');
    foreach ($titles as $i => $title) {
        $sheet->setCellValue([$i + 1, 3], $title);
    }
    foreach ($rows as $r => $values) {
        foreach ($values as $i => $value) {
            $sheet->setCellValue([$i + 1, 4 + $r], $value);
        }
    }

    $path = tempnam(sys_get_temp_dir(), 'lot') . '.xlsx';
    (new Xlsx($book))->save($path);

    return new UploadedFile($path, 'lot.xlsx', null, null, true);
}

function sampleLotRows(): array
{
    return [
        ['748268 (866317)', 'WHITE', 'Zipper', '1,200', 'Acme'],
        ['748268 (866317)', 'WHITE', 'Button', '500', 'Acme'],
        ['720193(PETITE)', 'INDIGO', 'Zipper', '300', 'Beta'],
    ];
}

it('finds the data sheet and title row and matches differently named columns', function () {
    $this->actingAs($this->merchant)->post(route('merchant.import.upload'), [
        'buyer_id' => $this->buyer->id, 'season_code' => 'sp27', 'file' => lotWorkbook(sampleLotRows()),
    ])->assertRedirect(route('merchant.import.map'));

    $page = $this->actingAs($this->merchant)->get(route('merchant.import.map'))->assertOk();

    $page->assertSee('Database')->assertSee('4 of 5 columns matched')->assertSee('Vendor');
    expect($page->viewData('sheet'))->toBe('Database')->and($page->viewData('headerRow'))->toBe(3);

    // "BOM Qty" found "BOM Quantity"; "Vendor" belongs to another department.
    $map = collect($page->viewData('suggestions'));
    expect($map[4]['header_id'])->toBe($this->headers['bom_quantity']->id)->and($map[5]['header_id'])->toBeNull();
});

it('previews, imports, links the season and styles, and remembers the matching', function () {
    $this->actingAs($this->merchant)->post(route('merchant.import.upload'), [
        'buyer_id' => $this->buyer->id, 'season_code' => 'sp27', 'file' => lotWorkbook(sampleLotRows()),
    ]);

    $map = [1 => $this->headers['style_name']->id, 2 => $this->headers['gmts_color_name']->id, 3 => $this->headers['material_description']->id, 4 => $this->headers['bom_quantity']->id, 5 => ''];

    $this->actingAs($this->merchant)->post(route('merchant.import.preview'), [
        'sheet' => 'Database', 'header_row' => 3, 'map' => $map, 'save_profile' => 1, 'profile_name' => 'Denim lot',
    ])->assertOk()->assertSee('Import 3 Rows')->assertSee('748268')->assertSee('866317');

    $this->actingAs($this->merchant)->post(route('merchant.import.confirm'))
        ->assertRedirect()->assertSessionHas('success');

    $file = ExcelFile::firstOrFail();
    expect($file->buyer_id)->toBe($this->buyer->id)->and($file->season->code)->toBe('SP27')->and($file->total_rows)->toBe(3);

    // Standard tables + Style Library, season taken from the upload.
    expect(OrderLine::count())->toBe(2)->and(BomItem::count())->toBe(3);
    expect(MotherStyle::orderBy('style_no')->pluck('style_no')->all())->toBe(['720193', '748268']);
    expect((float) BomItem::where('material_description', 'Button')->value('bom_qty'))->toBe(500.0);

    // The vendor column was skipped, never written.
    expect(\App\Models\ExcelCell::where('header_id', $this->vendorHeader->id)->whereNotNull('value')->count())->toBe(0);

    $profile = BuyerImportProfile::with('columns')->firstOrFail();
    expect($profile->name)->toBe('Denim lot')->and($profile->sheet_name)->toBe('Database')->and($profile->header_row)->toBe(3);
    expect($profile->columns->firstWhere('source_key', 'bom_qty')->excel_header_id)->toBe($this->headers['bom_quantity']->id);
    expect($profile->columns->firstWhere('source_key', 'vendor')->excel_header_id)->toBeNull();
});

it('applies the saved profile automatically to the next file with the same layout', function () {
    $profile = BuyerImportProfile::create(['buyer_id' => $this->buyer->id, 'name' => 'Denim lot', 'sheet_name' => 'Database', 'header_row' => 3]);
    foreach ([['Style', 'style_name'], ['GMTS Color Name', 'gmts_color_name'], ['Material Description', 'material_description'], ['BOM Qty', 'bom_quantity'], ['Vendor', null]] as [$title, $key]) {
        $profile->columns()->create([
            'source_header' => $title, 'source_key' => app(ImportColumnMatcher::class)->key($title),
            'excel_header_id' => $key ? $this->headers[$key]->id : null,
        ]);
    }

    // Titles reordered in the new file: the saved matching still wins.
    $file = lotWorkbook([['720193', 'RED', 'Thread', '70', 'Acme']], ['Style', 'GMTS Color Name', 'Material Description', 'BOM Qty', 'Vendor']);
    $this->actingAs($this->merchant)->post(route('merchant.import.upload'), ['buyer_id' => $this->buyer->id, 'season_code' => 'FA26', 'file' => $file]);

    $this->actingAs($this->merchant)->get(route('merchant.import.map'))
        ->assertOk()->assertSee('Matched automatically with the saved matching');
});

it('refuses another buyer, a header the wizard does not offer, and a doubled header', function () {
    $other = Buyer::create(['buyer_name' => 'Other', 'is_active' => true]);
    $scoped = User::factory()->create(['buyer_id' => $this->buyer->id, 'can_upload' => true])->assignRole('merchant');
    $scoped->buyers()->attach($this->buyer->id);

    $this->actingAs($scoped->fresh())->post(route('merchant.import.upload'), [
        'buyer_id' => $other->id, 'season_code' => 'SP27', 'file' => lotWorkbook(sampleLotRows()),
    ])->assertSessionHasErrors('buyer_id');

    $this->actingAs($this->merchant)->post(route('merchant.import.upload'), [
        'buyer_id' => $this->buyer->id, 'season_code' => 'SP27', 'file' => lotWorkbook(sampleLotRows()),
    ]);

    $this->actingAs($this->merchant)->post(route('merchant.import.preview'), [
        'sheet' => 'Database', 'header_row' => 3, 'map' => [1 => $this->vendorHeader->id],
    ])->assertSessionHas('warning');

    $this->actingAs($this->merchant)->post(route('merchant.import.preview'), [
        'sheet' => 'Database', 'header_row' => 3, 'map' => [1 => $this->headers['style_name']->id, 2 => $this->headers['style_name']->id],
    ])->assertSessionHas('warning', fn ($m) => str_contains($m, 'more than one column'));

    expect(ExcelFile::count())->toBe(0);
});

it('detects the title row and picks only recognisable titles', function () {
    $matcher = app(ImportColumnMatcher::class);
    $headers = collect($this->headers)->values();

    $rows = [1 => [1 => 'Ann Taylor lot file'], 2 => [], 3 => [1 => 'Style', 2 => 'GMTS Color Name', 3 => 'BOM Qty'], 4 => [1 => '748268', 2 => 'WHITE', 3 => '10']];

    expect($matcher->detectHeaderRow($rows, $headers))->toBe(3);
});

it('reads the value Excel saved for a formula instead of calculating it', function () {
    $this->actingAs($this->merchant)->post(route('merchant.import.upload'), [
        'buyer_id' => $this->buyer->id, 'season_code' => 'SP27',
        'file' => lotWorkbook([['333', 'RED', 'Zipper', '=2*3', 'Acme']]),
    ]);

    $map = [1 => $this->headers['style_name']->id, 2 => $this->headers['gmts_color_name']->id, 3 => $this->headers['material_description']->id, 4 => $this->headers['bom_quantity']->id];

    $this->actingAs($this->merchant)->post(route('merchant.import.preview'), ['sheet' => 'Database', 'header_row' => 3, 'map' => $map])->assertOk();
    $this->actingAs($this->merchant)->post(route('merchant.import.confirm'))->assertRedirect();

    expect((float) BomItem::first()->bom_qty)->toBe(6.0);
});

it('matches a buyer column by the other names Admin typed on a header', function () {
    $matcher = app(ImportColumnMatcher::class);
    $mother = ExcelHeader::create([
        'header_name' => 'Mother Style', 'header_key' => 'mother_style', 'owner_role_id' => Role::findOrCreate('merchant', 'web')->id,
        'position' => 20, 'aliases' => "Parent Style\nMain Style, Base Style",
    ]);

    expect($mother->aliasList())->toBe(['Parent Style', 'Main Style', 'Base Style']);

    $result = $matcher->suggest([0 => 'Style', 1 => 'Base Style', 2 => 'Unknown'], \App\Models\ExcelHeader::all());

    expect($result[1]['header_id'])->toBe($mother->id)->and($result[1]['reason'])->toBe('name')
        ->and($result[2]['header_id'])->toBeNull();
});

it('works out mother and child the same way for the preview and the import', function () {
    expect(\App\Support\StyleHierarchy::resolve('788457(PETITE)', '813132.0'))
        ->toBe(['mother' => '813132', 'child' => '788457(PETITE)', 'kind' => 'variant', 'source' => 'column', 'missing' => false]);
    expect(\App\Support\StyleHierarchy::resolve('813132', '813132'))
        ->toBe(['mother' => '813132', 'child' => null, 'kind' => null, 'source' => 'column', 'missing' => false]);
    expect(\App\Support\StyleHierarchy::resolve('720193(PETITE)', ''))
        ->toBe(['mother' => '720193', 'child' => 'PETITE', 'kind' => 'variant', 'source' => 'code', 'missing' => false]);
    expect(\App\Support\StyleHierarchy::resolve('882635'))
        ->toBe(['mother' => '882635', 'child' => null, 'kind' => null, 'source' => 'style', 'missing' => false]);
});

it('previews mothers, children, colors and rows to check, using the Mother Style column', function () {
    $role = Role::findOrCreate('merchant', 'web');
    $mother = ExcelHeader::create(['header_name' => 'Mother Style', 'header_key' => 'mother_style', 'owner_role_id' => $role->id, 'position' => 30]);

    $file = lotWorkbook([
        ['813132(MISSY)', 'WASH', 'Thread', '10', '813132'],
        ['788457(PETITE)', 'WASH', 'Thread', '10', '813132'],
        ['868536(CURVY)', 'DARK', 'Thread', '10', ''],
        ['', 'DARK', 'Thread', '10', '813132'],
    ], ['Style', 'GMTS Color Name', 'Material Description', 'BOM Qty', 'Parent Style']);
    $mother->update(['aliases' => 'Parent Style']);

    $this->actingAs($this->merchant)->post(route('merchant.import.upload'), ['buyer_id' => $this->buyer->id, 'season_code' => 'SU27', 'file' => $file]);

    $map = [1 => $this->headers['style_name']->id, 2 => $this->headers['gmts_color_name']->id, 3 => $this->headers['material_description']->id, 4 => $this->headers['bom_quantity']->id, 5 => $mother->id];

    $response = $this->withoutVite()->actingAs($this->merchant)->post(route('merchant.import.preview'), ['sheet' => 'Database', 'header_row' => 3, 'map' => $map])->assertOk();

    $summary = $response->viewData('summary');
    expect($summary['mothers'])->toBe(2)           // 813132 and 868536 (fell back to its own code)
        ->and($summary['children'])->toBe(3)
        ->and($summary['colors'])->toBe(2)
        ->and($summary['no_style_rows'])->toBe(1)
        ->and($summary['mother_fallback_rows'])->toBe(1)
        ->and($summary['mother_column'])->toBeTrue();
    $response->assertSee('have no style')->assertSee('empty Mother Style');
});


it('treats an ALL style row as a common item, not a mother style', function () {
    expect(\App\Support\StyleHierarchy::isCommon(' All '))->toBeTrue()
        ->and(\App\Support\StyleHierarchy::isCommon('ALL'))->toBeTrue()
        ->and(\App\Support\StyleHierarchy::isCommon('ALLEN-1'))->toBeFalse();

    $file = lotWorkbook([
        ['882635', 'RED', 'Thread', '10', ''],
        ['ALL', 'ALL', 'Poly Bag', '10', ''],
    ]);
    $this->actingAs($this->merchant)->post(route('merchant.import.upload'), ['buyer_id' => $this->buyer->id, 'season_code' => 'SU27', 'file' => $file]);

    $map = [1 => $this->headers['style_name']->id, 2 => $this->headers['gmts_color_name']->id, 3 => $this->headers['material_description']->id, 4 => $this->headers['bom_quantity']->id];
    $response = $this->withoutVite()->actingAs($this->merchant)->post(route('merchant.import.preview'), ['sheet' => 'Database', 'header_row' => 3, 'map' => $map])->assertOk();

    expect($response->viewData('summary')['mothers'])->toBe(1)->and($response->viewData('summary')['common_rows'])->toBe(1);

    $this->actingAs($this->merchant)->post(route('merchant.import.confirm'))->assertRedirect();

    expect(MotherStyle::pluck('style_no')->all())->toBe(['882635']);
    expect(BomItem::count())->toBe(2);
    expect(OrderLine::where('style_no', 'ALL')->first()->mother_style_id)->toBeNull();
});

it('stops reading after a long run of blank rows', function () {
    $rows = [['882635', 'RED', 'Thread', '10', '']];
    $book = new Spreadsheet();
    $sheet = $book->getActiveSheet()->setTitle('Database');
    foreach (['Style', 'GMTS Color Name', 'Material Description', 'BOM Qty'] as $i => $t) {
        $sheet->setCellValue([$i + 1, 1], $t);
    }
    $sheet->fromArray(['882635', 'RED', 'Thread', '10'], null, 'A2');
    // Far below the data: a stray formatted/typed cell, like a sheet that reports a million rows.
    $sheet->setCellValue('A5000', 'stray');
    $path = tempnam(sys_get_temp_dir(), 'blank') . '.xlsx';
    (new Xlsx($book))->save($path);

    $this->actingAs($this->merchant)->post(route('merchant.import.upload'), ['buyer_id' => $this->buyer->id, 'season_code' => 'SU27', 'file' => new UploadedFile($path, 'blank.xlsx', null, null, true)]);
    $map = [1 => $this->headers['style_name']->id, 2 => $this->headers['gmts_color_name']->id, 3 => $this->headers['material_description']->id, 4 => $this->headers['bom_quantity']->id];

    $response = $this->withoutVite()->actingAs($this->merchant)->post(route('merchant.import.preview'), ['sheet' => 'Database', 'header_row' => 1, 'map' => $map])->assertOk();

    expect($response->viewData('rowCount'))->toBe(1);
});


it('follows the buyer-wise rule: fit words, style-only and column-only', function () {
    $r = fn (...$a) => \App\Support\StyleHierarchy::resolve(...$a);

    // Fit word without a bracket, longest first.
    expect($r('608471 CURVY PETITE', '', 'code', 'PETITE, CURVY PETITE'))->toMatchArray(['mother' => '608471', 'child' => 'CURVY PETITE', 'source' => 'code']);
    expect($r('608468 PETITE', '', null, 'PETITE'))->toMatchArray(['mother' => '608468', 'child' => 'PETITE']);
    expect($r('607294(MISSY)', '', null, 'PETITE'))->toMatchArray(['mother' => '607294', 'child' => 'MISSY']);
    // No fit words listed: the whole text stays the mother, as before.
    expect($r('608468 PETITE'))->toMatchArray(['mother' => '608468 PETITE', 'child' => null]);

    // Every style its own mother, even with a bracket or a Mother Style value.
    expect($r('720193(PETITE)', '999', 'style'))->toMatchArray(['mother' => '720193(PETITE)', 'child' => null, 'source' => 'style']);

    // Column only: a blank cell is flagged and not guessed from the code.
    expect($r('788457(PETITE)', '', 'column'))->toMatchArray(['mother' => '788457(PETITE)', 'child' => null, 'missing' => true]);
    expect($r('788457(PETITE)', '813132', 'column'))->toMatchArray(['mother' => '813132', 'child' => '788457(PETITE)', 'missing' => false]);

    // Code only: the Mother Style column is ignored.
    expect($r('720193(PETITE)', '999', 'code'))->toMatchArray(['mother' => '720193', 'child' => 'PETITE']);
});

it('saves the style rule on the profile and the imported file, and the sync follows it', function () {
    $file = lotWorkbook([
        ['608468 PETITE', 'RED', 'Thread', '10', ''],
        ['607594 TALL', 'RED', 'Thread', '10', ''],
    ]);
    $this->actingAs($this->merchant)->post(route('merchant.import.upload'), ['buyer_id' => $this->buyer->id, 'season_code' => 'SU27', 'file' => $file]);
    $map = [1 => $this->headers['style_name']->id, 2 => $this->headers['gmts_color_name']->id, 3 => $this->headers['material_description']->id, 4 => $this->headers['bom_quantity']->id];

    $this->withoutVite()->actingAs($this->merchant)->post(route('merchant.import.preview'), [
        'sheet' => 'Database', 'header_row' => 3, 'map' => $map, 'save_profile' => 1, 'profile_name' => 'Ann Taylor SU27',
        'mother_source' => 'code', 'fit_words' => 'PETITE, TALL',
    ])->assertOk()->assertSee('Mother style rule');

    $this->actingAs($this->merchant)->post(route('merchant.import.confirm'))->assertRedirect();

    $profile = BuyerImportProfile::firstOrFail();
    expect($profile->mother_source)->toBe('code')->and($profile->fit_words)->toBe('PETITE, TALL');
    expect(ExcelFile::firstOrFail()->mother_source)->toBe('code');
    expect(MotherStyle::orderBy('style_no')->pluck('style_no')->all())->toBe(['607594', '608468']);
    expect(\App\Models\ChildStyle::orderBy('style_no')->pluck('style_no')->all())->toBe(['PETITE', 'TALL']);
});


it('downloads a blank standard template built from the Merchandising input columns', function () {
    ExcelHeader::create(['header_name' => 'Mother Style', 'header_key' => 'mother_style', 'owner_role_id' => Role::findOrCreate('merchant', 'web')->id, 'position' => 40]);

    $response = $this->actingAs($this->merchant)->get(route('merchant.import.template'))->assertOk();

    $path = tempnam(sys_get_temp_dir(), 'tpl') . '.xlsx';
    file_put_contents($path, $response->streamedContent());
    $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
    $titles = collect($book->getSheetByName('Database')->rangeToArray('A1:Z1')[0])->filter()->values()->all();

    // Merchandising columns only (not the Supply Chain Vendor Name), in Admin's order.
    expect($titles)->toBe(['Style', 'GMTS Color Name', 'Material Description', 'BOM Quantity', 'Season Name', 'Mother Style']);
    expect($book->getSheetByName('How to fill'))->not->toBeNull();
    // A header row only: nothing for the import to read as data.
    expect($book->getSheetByName('Database')->getHighestRow())->toBe(1);
});
