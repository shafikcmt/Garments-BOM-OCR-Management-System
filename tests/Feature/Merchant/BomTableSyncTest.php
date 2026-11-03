<?php

use App\Models\Buyer;
use App\Models\BomItem;
use App\Models\BuyerSeason;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\MotherStyle;
use App\Models\OrderLine;
use App\Models\User;
use App\Services\BomTableSync;

/**
 * The BOM workspace rows become order lines + bom items, linked to the Style
 * Library, and re-running never duplicates anything.
 */
function bomFixture(array $rows, bool $withBuyer = true): array
{
    $buyer = $withBuyer ? Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]) : null;
    $file = ExcelFile::create([
        'file_name' => 'lot.xlsx', 'original_file_name' => 'lot.xlsx', 'file_path' => 'lot.xlsx',
        'uploaded_by' => User::factory()->create()->id, 'buyer_id' => $buyer?->id,
    ]);

    $keys = [
        'season_name' => 'Season Name', 'style_name' => 'Style', 'gmts_color_name' => 'GMTS Color Name',
        'contract_number' => 'Contract Number', 'material_description' => 'Material Description',
        'sap_code' => 'SAP Code', 'bom_quantity' => 'BOM Quantity', 'mother_style' => 'Mother Style',
    ];
    $headers = [];
    $position = 1;
    foreach ($keys as $key => $name) {
        $headers[$key] = ExcelHeader::create([
            'header_name' => $name, 'header_key' => $key, 'owner_role_id' => 1, 'position' => $position++,
        ]);
    }

    $models = [];
    foreach ($rows as $i => $values) {
        $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => $i + 1]);
        foreach ($values as $key => $value) {
            ExcelCell::create(['row_id' => $row->id, 'header_id' => $headers[$key]->id, 'value' => (string) $value]);
        }
        $models[] = $row;
    }

    return [$file, $models, $headers, $buyer];
}

it('groups rows into order lines and creates one bom item per row', function () {
    [$file] = bomFixture([
        ['season_name' => 'sp27', 'style_name' => '748268 (866317)', 'gmts_color_name' => 'WHITE', 'contract_number' => 'C1', 'material_description' => 'Zipper', 'sap_code' => 'Z1', 'bom_quantity' => '1,200'],
        ['season_name' => 'sp27', 'style_name' => '748268 (866317)', 'gmts_color_name' => 'WHITE', 'contract_number' => 'C1', 'material_description' => 'Button', 'sap_code' => 'B1', 'bom_quantity' => '500'],
        ['season_name' => 'sp27', 'style_name' => '720193(PETITE)', 'gmts_color_name' => 'INDIGO', 'contract_number' => 'C2', 'material_description' => 'Zipper', 'sap_code' => 'Z1', 'bom_quantity' => '300'],
        ['season_name' => 'sp27', 'style_name' => '', 'material_description' => 'Stray'],
    ]);

    $result = app(BomTableSync::class)->syncFile($file);

    expect($result)->toMatchArray(['rows' => 4, 'order_lines' => 2, 'bom_items' => 3, 'skipped' => 1]);
    expect(OrderLine::count())->toBe(2)->and(BomItem::count())->toBe(3);
    expect((float) BomItem::where('sap_code', 'B1')->value('bom_qty'))->toBe(500.0);
    expect((float) BomItem::where('sap_code', 'Z1')->orderBy('id')->value('bom_qty'))->toBe(1200.0);

    // Style Library: season SP27, mothers 748268 + 720193, children 866317 + PETITE.
    expect(BuyerSeason::pluck('code')->all())->toBe(['SP27']);
    expect(MotherStyle::orderBy('style_no')->pluck('style_no')->all())->toBe(['720193', '748268']);
    $line = OrderLine::where('style_no', '748268 (866317)')->first();
    expect($line->motherStyle->style_no)->toBe('748268')->and($line->childStyle->style_no)->toBe('866317');
});

it('is idempotent and follows a later edit of the workspace', function () {
    [$file, $rows, $headers] = bomFixture([
        ['season_name' => 'SP27', 'style_name' => '882635', 'gmts_color_name' => 'BLUE', 'contract_number' => 'C1', 'material_description' => 'Zipper', 'bom_quantity' => '100'],
    ]);
    $sync = app(BomTableSync::class);

    $sync->syncFile($file);
    $again = $sync->syncFile($file);

    expect($again['order_lines'])->toBe(0)->and($again['bom_items'])->toBe(0);
    expect(OrderLine::count())->toBe(1)->and(BomItem::count())->toBe(1)->and(MotherStyle::count())->toBe(1);

    ExcelCell::where('row_id', $rows[0]->id)->where('header_id', $headers['bom_quantity']->id)->update(['value' => '250']);
    $sync->syncRows($file, [$rows[0]->id]);

    expect((float) BomItem::first()->bom_qty)->toBe(250.0);
});

it('moves a row to a new order line when its style changes and prunes the empty one', function () {
    [$file, $rows, $headers] = bomFixture([
        ['season_name' => 'SP27', 'style_name' => '111', 'gmts_color_name' => 'RED', 'contract_number' => 'C1', 'material_description' => 'Zipper'],
    ]);
    $sync = app(BomTableSync::class);
    $sync->syncFile($file);

    ExcelCell::where('row_id', $rows[0]->id)->where('header_id', $headers['style_name']->id)->update(['value' => '222']);
    $sync->syncRows($file, [$rows[0]->id]);

    expect(OrderLine::pluck('style_no')->all())->toBe(['222']);
    expect(BomItem::count())->toBe(1);
});

it('builds the tables but leaves styles unlinked when the file has no buyer', function () {
    [$file] = bomFixture([
        ['season_name' => 'SP27', 'style_name' => '333', 'material_description' => 'Zipper'],
    ], withBuyer: false);

    app(BomTableSync::class)->syncFile($file);

    expect(OrderLine::first()->mother_style_id)->toBeNull();
    expect(MotherStyle::count())->toBe(0);
});

it('dry run reports counts and saves nothing, a real run saves them', function () {
    [$file] = bomFixture([
        ['season_name' => 'SP27', 'style_name' => '444', 'material_description' => 'Zipper'],
    ]);

    $this->artisan('bom:sync-tables', ['--dry-run' => true])->expectsOutputToContain('DRY RUN')->assertSuccessful();
    expect(OrderLine::count())->toBe(0)->and(BomItem::count())->toBe(0);

    $this->artisan('bom:sync-tables')->assertSuccessful();
    expect(OrderLine::count())->toBe(1)->and(BomItem::count())->toBe(1);
});

it('does nothing on a save when the switch is off', function () {
    config(['stock.sync_bom_tables' => false]);
    [$file, $rows] = bomFixture([
        ['season_name' => 'SP27', 'style_name' => '555', 'material_description' => 'Zipper'],
    ]);

    app(BomTableSync::class)->syncRows($file, [$rows[0]->id]);

    expect(OrderLine::count())->toBe(0);
});


it('takes the mother from the Mother Style column and keeps the whole style code as the child', function () {
    bomFixture([
        ['season_name' => 'SU27', 'style_name' => '813132(MISSY)', 'mother_style' => '813132', 'gmts_color_name' => 'WASH', 'contract_number' => 'C1', 'material_description' => 'Thread'],
        ['season_name' => 'SU27', 'style_name' => '788457(PETITE)', 'mother_style' => '813132.0', 'gmts_color_name' => 'WASH', 'contract_number' => 'C1', 'material_description' => 'Thread'],
        ['season_name' => 'SU27', 'style_name' => '868536(CURVY)', 'mother_style' => '813132', 'gmts_color_name' => 'WASH', 'contract_number' => 'C1', 'material_description' => 'Thread'],
        ['season_name' => 'SU27', 'style_name' => '742744', 'mother_style' => '742744', 'gmts_color_name' => 'WASH', 'contract_number' => 'C1', 'material_description' => 'Thread'],
    ]);

    app(BomTableSync::class)->syncFile(\App\Models\ExcelFile::first());

    expect(MotherStyle::orderBy('style_no')->pluck('style_no')->all())->toBe(['742744', '813132']);
    $mother = MotherStyle::where('style_no', '813132')->first();
    expect($mother->children()->orderBy('style_no')->pluck('style_no')->all())
        ->toBe(['788457(PETITE)', '813132(MISSY)', '868536(CURVY)']);
    expect(OrderLine::where('style_no', '742744')->first()->childStyle)->toBeNull();
});

it('works out the mother from the style code when the sheet has no Mother Style value', function () {
    bomFixture([
        ['season_name' => 'SU27', 'style_name' => '720193(PETITE)', 'gmts_color_name' => 'WASH', 'contract_number' => 'C1', 'material_description' => 'Thread'],
        ['season_name' => 'SU27', 'style_name' => '882635', 'gmts_color_name' => 'WASH', 'contract_number' => 'C1', 'material_description' => 'Thread'],
    ]);

    app(BomTableSync::class)->syncFile(\App\Models\ExcelFile::first());

    expect(MotherStyle::orderBy('style_no')->pluck('style_no')->all())->toBe(['720193', '882635']);
    expect(OrderLine::where('style_no', '720193(PETITE)')->first()->childStyle->style_no)->toBe('PETITE');
});
