<?php

use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * The "Add New Order" dialog — its source picker and its single write.
 *
 * WHY THESE EXIST. The dialog replaced the per-row Duplicate button, so it is
 * now the ONLY single-row duplication entry point a merchant has. Two things
 * about it are not visible from the browser and are pinned here:
 *
 *   - the picker searches the WHOLE file, not the rendered page. That is the
 *     reason it is a server request at all, so a test that only proved it
 *     returned something would miss the point.
 *   - the edits it posts are re-checked server-side against what the user may
 *     write. The form renders only editable columns, but the form is not the
 *     control — a hand-made request must not reach another department's column.
 *
 * Everything else about the write is deliberately unchanged from
 * duplicateRows(): same gate, same lineage, same below-source placement. Those
 * rules live in RowDuplicateTest and are not restated here.
 */
function addOrderUser(string $role): User
{
    Role::findOrCreate($role, 'web');

    return User::factory()->create()->assignRole($role);
}

/**
 * Headers spanning TWO owning roles on purpose.
 *
 * buyer/style/season/contract are merchant-owned; material_po_number is Supply
 * Chain's. That split is what makes the override guard testable — a merchant
 * may write the first four and must not be able to write the fifth.
 *
 * @return \Illuminate\Support\Collection<string, ExcelHeader>
 */
function addOrderHeaders(): \Illuminate\Support\Collection
{
    $merchant = Role::findOrCreate('merchant', 'web');
    $supplyChain = Role::findOrCreate('supply_chain', 'web');

    $spec = [
        'buyer_name' => $merchant,
        'style_name' => $merchant,
        'season_name' => $merchant,
        'initial_contract_number' => $merchant,
        'gmts_color_name' => $merchant,
        'material_po_number' => $supplyChain,
    ];

    $position = 0;

    return collect($spec)->mapWithKeys(function ($ownerRole, $key) use (&$position) {
        return [$key => ExcelHeader::firstOrCreate(
            ['header_key' => $key],
            [
                'header_name' => ucwords(str_replace('_', ' ', $key)),
                'owner_role_id' => $ownerRole->id,
                'position' => $position++,
                'is_active' => true,
            ]
        )];
    });
}

/** $rows is a list of [buyer, style, season, contract, colour, materialPo]. */
function addOrderFile(array $rows): ExcelFile
{
    $file = ExcelFile::create([
        'file_name' => 'sheet.xlsx',
        'original_file_name' => 'sheet.xlsx',
        'file_path' => 'sheet.xlsx',
        'status' => 'processing',
        'uploaded_by' => User::factory()->create()->id,
        'total_rows' => count($rows),
    ]);

    $headers = addOrderHeaders();

    foreach ($rows as $index => $values) {
        $row = ExcelRow::create([
            'excel_file_id' => $file->id,
            'row_number' => $index + 1,
        ]);

        $byKey = array_combine(
            ['buyer_name', 'style_name', 'season_name', 'initial_contract_number', 'gmts_color_name', 'material_po_number'],
            $values
        );

        foreach ($byKey as $key => $value) {
            ExcelCell::create([
                'row_id' => $row->id,
                'header_id' => $headers[$key]->id,
                'value' => $value,
            ]);
        }
    }

    return $file->fresh();
}

function addOrderCellValue(ExcelRow $row, string $headerKey): ?string
{
    $header = ExcelHeader::where('header_key', $headerKey)->first();

    return $row->fresh('cells')->cells->firstWhere('header_id', $header->id)?->value;
}

/*
 * THE SOURCE PICKER.
 */

it('searches the whole file, not just the first page of rows', function () {
    // 60 rows — more than the 50 the grid renders per page. The row the search
    // must find sits beyond that boundary, which is the whole reason this
    // endpoint exists instead of a client-side filter.
    $rows = [];

    for ($i = 1; $i <= 59; $i++) {
        $rows[] = ['H&M', 'FILLER', 'Winter', 'C-' . $i, 'Black', 'PO-' . $i];
    }

    $rows[] = ['Zara', 'DEEPROW', 'Summer', 'C-60', 'Red', 'PO-60'];

    $file = addOrderFile($rows);

    $response = $this->actingAs(addOrderUser('merchant'))
        ->getJson(route('uploaded-files.rows.order_sources', $file->id) . '?style_name=DEEPROW');

    $response->assertOk();

    expect($response->json('total'))->toBe(1);
    expect($response->json('rows.0.row_number'))->toBe(60);
    expect($response->json('rows.0.values.style_name'))->toBe('DEEPROW');
});

it('matches substrings, so a partial style still finds the row', function () {
    $file = addOrderFile([
        ['Zara', 'ABC', 'Summer', 'C-1', 'Red', 'PO-1'],
        ['Zara', 'ABC-2', 'Summer', 'C-2', 'Blue', 'PO-2'],
    ]);

    // Unlike "Duplicate All Matching Rows", which is exact: this picker shows
    // the merchant what it found and they choose ONE, so over-matching here
    // costs a glance rather than data.
    $response = $this->actingAs(addOrderUser('merchant'))
        ->getJson(route('uploaded-files.rows.order_sources', $file->id) . '?style_name=ABC');

    expect($response->json('total'))->toBe(2);
});

it('caps the list and says how many matches were left out', function () {
    $rows = [];

    for ($i = 1; $i <= 30; $i++) {
        $rows[] = ['Zara', 'ABC', 'Summer', 'C-' . $i, 'Red', 'PO-' . $i];
    }

    $file = addOrderFile($rows);

    $response = $this->actingAs(addOrderUser('merchant'))
        ->getJson(route('uploaded-files.rows.order_sources', $file->id) . '?style_name=ABC');

    expect($response->json('total'))->toBe(30);
    expect($response->json('shown'))->toBe(25);
    expect($response->json('rows'))->toHaveCount(25);
});

it('returns pre-fill values only for columns this user may edit', function () {
    $file = addOrderFile([
        ['Zara', 'ABC', 'Summer', 'C-1', 'Red', 'PO-1'],
    ]);

    $contract = ExcelHeader::where('header_key', 'initial_contract_number')->first();
    $materialPo = ExcelHeader::where('header_key', 'material_po_number')->first();

    $response = $this->actingAs(addOrderUser('merchant'))
        ->getJson(route('uploaded-files.rows.order_sources', $file->id) . '?style_name=ABC');

    $formValues = $response->json('rows.0.form_values');

    // Merchant-owned, on the form and pre-filled.
    expect($formValues)->toHaveKey((string) $contract->id);
    expect($formValues[(string) $contract->id])->toBe('C-1');

    // Supply Chain's column: still copied to the new row, but not something
    // this merchant is offered an input for.
    expect($formValues)->not->toHaveKey((string) $materialPo->id);
});

it('does not offer the picker to a role that cannot duplicate', function () {
    $file = addOrderFile([['Zara', 'ABC', 'Summer', 'C-1', 'Red', 'PO-1']]);

    $this->actingAs(addOrderUser('supply_chain'))
        ->getJson(route('uploaded-files.rows.order_sources', $file->id))
        ->assertForbidden();
});

/*
 * THE FILTER DROPDOWNS.
 *
 * They were free-text boxes, which asked a merchant to reproduce a style code
 * from memory — a typo returned "no rows match" and looked exactly like the
 * style genuinely not being in the file. The options must come from THIS file's
 * rows: a global list would offer values that cannot match anything in the open
 * workbook, which is a worse lie than free text, because a value picked from a
 * dropdown looks confirmed.
 */

it('offers dropdown options taken from this files own rows', function () {
    $file = addOrderFile([
        ['Zara', 'ABC', 'Summer', 'C-1', 'Red', 'PO-1'],
        ['Zara', 'XYZ', 'Winter', 'C-2', 'Blue', 'PO-2'],
    ]);

    $response = $this->actingAs(addOrderUser('merchant'))
        ->get(route('uploaded-files.show', $file->id))
        ->assertOk();

    $options = $response->viewData('addOrderFilterOptions');

    expect($options['buyer_name'])->toEqual(['Zara']);          // distinct, not once per row
    expect($options['style_name'])->toEqual(['ABC', 'XYZ']);    // sorted
    expect($options['season_name'])->toEqual(['Summer', 'Winter']);
});

it('does not offer values that belong to another workbook', function () {
    $file = addOrderFile([['Zara', 'ABC', 'Summer', 'C-1', 'Red', 'PO-1']]);

    // A second file with completely different values. Its styles must not
    // appear in the first file's dropdowns.
    addOrderFile([['H&M', 'OTHERSTYLE', 'Winter', 'C-9', 'Blue', 'PO-9']]);

    $options = $this->actingAs(addOrderUser('merchant'))
        ->get(route('uploaded-files.show', $file->id))
        ->assertOk()
        ->viewData('addOrderFilterOptions');

    expect($options['style_name'])->toEqual(['ABC']);
    expect($options['style_name'])->not->toContain('OTHERSTYLE');
    expect($options['buyer_name'])->not->toContain('H&M');
});

it('leaves blank and whitespace-only values out of the dropdowns', function () {
    // A real sheet has empty cells; an empty option in a filter reads as "no
    // filter" and would silently widen the search.
    $file = addOrderFile([
        ['Zara', 'ABC', 'Summer', 'C-1', 'Red', 'PO-1'],
        ['', 'XYZ', '   ', 'C-2', 'Blue', 'PO-2'],
    ]);

    // And a genuinely null cell, which is not the same as an empty string.
    $row = ExcelRow::where('excel_file_id', $file->id)->where('row_number', 2)->first();
    $buyerHeader = ExcelHeader::where('header_key', 'buyer_name')->first();
    ExcelCell::where('row_id', $row->id)->where('header_id', $buyerHeader->id)->update(['value' => null]);

    $options = $this->actingAs(addOrderUser('merchant'))
        ->get(route('uploaded-files.show', $file->id))
        ->assertOk()
        ->viewData('addOrderFilterOptions');

    expect($options['buyer_name'])->toEqual(['Zara']);
    expect($options['season_name'])->toEqual(['Summer']);
    // The style on that row is real, so it stays.
    expect($options['style_name'])->toEqual(['ABC', 'XYZ']);
});

it('renders the three filters as dropdowns carrying those options', function () {
    $file = addOrderFile([['Zara', 'ABC', 'Summer', 'C-1', 'Red', 'PO-1']]);

    $response = $this->actingAs(addOrderUser('merchant'))
        ->get(route('uploaded-files.show', $file->id))
        ->assertOk();

    // Style is the primary field and is a <select>, not a text input.
    $response->assertSee('id="addOrderFilterStyle"', false);
    $response->assertSee('ao-field-primary', false);
    $response->assertSee('<option value="ABC">ABC</option>', false);
    $response->assertSee('<option value="Summer">Summer</option>', false);

    // The free-text edge-case box survives the conversion.
    $response->assertSee('id="addOrderFilterAny"', false);

    // And Tom Select is actually loaded for them.
    $response->assertSee('tom-select@2.3.1', false);
});

it('builds no options for a user who cannot open the dialog', function () {
    $file = addOrderFile([['Zara', 'ABC', 'Summer', 'C-1', 'Red', 'PO-1']]);

    // supply_chain can view the sheet but not add rows, so the dialog is not
    // rendered and the query behind it should not run either.
    $this->actingAs(addOrderUser('supply_chain'))
        ->get(route('uploaded-files.show', $file->id))
        ->assertOk()
        ->assertViewHas('addOrderFilterOptions', []);
});

/*
 * Local development is MySQL, production is PostgreSQL and this suite runs on
 * SQLite — three dialects, and green tests here prove nothing about the one
 * that matters. The options query is built entirely from query-builder methods
 * with no raw SQL, no LIKE and no string or date function, so it compiles on
 * all three; this pins that by reading the SQL the real PostgreSQL grammar
 * produces.
 */
it('compiles the dropdown options query to portable SQL on postgresql', function () {
    config()->set('database.connections.pgcheck', [
        'driver' => 'pgsql',
        'host' => 'localhost',
        'database' => 'unused',
        'username' => 'unused',
        'password' => 'unused',
        'charset' => 'utf8',
        'prefix' => '',
        'schema' => 'public',
    ]);

    // The same shape addOrderFilterOptions() builds: SELECT DISTINCT over two
    // columns, narrowed by a subquery on this file's rows.
    $sql = DB::connection('pgcheck')->table('excel_cells')
        ->select('header_id', 'value')
        ->distinct()
        ->whereIn('header_id', [1, 2, 3])
        ->whereIn('row_id', DB::connection('pgcheck')->table('excel_rows')->select('id')->where('excel_file_id', 5))
        ->whereNotNull('value')
        ->where('value', '<>', '')
        ->orderBy('value')
        ->toSql();

    // Double-quoted identifiers, no backticks, and none of the constructs that
    // differ between engines.
    expect($sql)
        ->toContain('select distinct')
        ->toContain('"excel_cells"')
        ->toContain('"row_id" in (select "id" from "excel_rows"')
        ->not->toContain('`')
        ->not->toContain('ilike')
        ->not->toContain('DATE_FORMAT')
        ->not->toContain('CONCAT');
});

/*
 * THE WRITE.
 */

it('creates the new order below its source with the merchant edits applied', function () {
    $file = addOrderFile([
        ['Zara', 'ABC', 'Summer', 'C-1', 'Red', 'PO-1'],
        ['H&M', 'XYZ', 'Winter', 'C-2', 'Blue', 'PO-2'],
    ]);

    $source = ExcelRow::where('excel_file_id', $file->id)->where('row_number', 1)->first();
    $contract = ExcelHeader::where('header_key', 'initial_contract_number')->first();

    $this->actingAs(addOrderUser('merchant'))
        ->post(route('uploaded-files.rows.add_order', $file->id), [
            'source_row_id' => $source->id,
            'overrides' => [$contract->id => 'C-NEW'],
        ])
        ->assertRedirect(route('uploaded-files.show', $file->id))
        ->assertSessionHas('success');

    $copy = ExcelRow::where('duplicated_from_row_id', $source->id)->first();

    // Placement and lineage are duplicateRows()' behaviour, unchanged.
    expect($copy->row_number)->toBe(2);
    expect($copy->duplicated_at)->not->toBeNull();

    // The edited column took the new value...
    expect(addOrderCellValue($copy, 'initial_contract_number'))->toBe('C-NEW');
    // ...and everything else came across from the source.
    expect(addOrderCellValue($copy, 'buyer_name'))->toBe('Zara');
    expect(addOrderCellValue($copy, 'material_po_number'))->toBe('PO-1');

    // The source itself is untouched.
    expect(addOrderCellValue($source, 'initial_contract_number'))->toBe('C-1');
});

it('creates the order with no edits at all, as a plain copy', function () {
    $file = addOrderFile([['Zara', 'ABC', 'Summer', 'C-1', 'Red', 'PO-1']]);
    $source = ExcelRow::where('excel_file_id', $file->id)->first();

    $this->actingAs(addOrderUser('merchant'))
        ->post(route('uploaded-files.rows.add_order', $file->id), ['source_row_id' => $source->id]);

    $copy = ExcelRow::where('duplicated_from_row_id', $source->id)->first();

    expect($copy)->not->toBeNull();
    expect(addOrderCellValue($copy, 'initial_contract_number'))->toBe('C-1');
});

it('blanks a column when the merchant clears the field', function () {
    $file = addOrderFile([['Zara', 'ABC', 'Summer', 'C-1', 'Red', 'PO-1']]);
    $source = ExcelRow::where('excel_file_id', $file->id)->first();
    $contract = ExcelHeader::where('header_key', 'initial_contract_number')->first();

    $this->actingAs(addOrderUser('merchant'))
        ->post(route('uploaded-files.rows.add_order', $file->id), [
            'source_row_id' => $source->id,
            'overrides' => [$contract->id => ''],
        ]);

    $copy = ExcelRow::where('duplicated_from_row_id', $source->id)->first();

    // An empty box means "no value yet", not "keep the old contract number" —
    // which for this column is exactly what a new order needs.
    expect(addOrderCellValue($copy, 'initial_contract_number'))->toBeNull();
});

it('ignores an override for a column this user does not own', function () {
    $file = addOrderFile([['Zara', 'ABC', 'Summer', 'C-1', 'Red', 'PO-1']]);
    $source = ExcelRow::where('excel_file_id', $file->id)->first();
    $materialPo = ExcelHeader::where('header_key', 'material_po_number')->first();

    // A hand-made request naming Supply Chain's column. The form would never
    // render this input; the server refuses it anyway.
    $this->actingAs(addOrderUser('merchant'))
        ->post(route('uploaded-files.rows.add_order', $file->id), [
            'source_row_id' => $source->id,
            'overrides' => [$materialPo->id => 'TAMPERED'],
        ]);

    $copy = ExcelRow::where('duplicated_from_row_id', $source->id)->first();

    // Copied from the source, not taken from the request.
    expect(addOrderCellValue($copy, 'material_po_number'))->toBe('PO-1');
});

it('will not copy a row out of another workbook', function () {
    $file = addOrderFile([['Zara', 'ABC', 'Summer', 'C-1', 'Red', 'PO-1']]);
    $other = addOrderFile([['H&M', 'XYZ', 'Winter', 'C-9', 'Blue', 'PO-9']]);

    $foreign = $other->rows()->first();

    $this->actingAs(addOrderUser('merchant'))
        ->post(route('uploaded-files.rows.add_order', $file->id), ['source_row_id' => $foreign->id])
        ->assertRedirect(route('uploaded-files.show', $file->id))
        ->assertSessionHas('warning');

    expect($file->rows()->count())->toBe(1);
    expect($other->rows()->count())->toBe(1);
});

it('refuses a role that cannot add rows', function () {
    $file = addOrderFile([['Zara', 'ABC', 'Summer', 'C-1', 'Red', 'PO-1']]);
    $source = ExcelRow::where('excel_file_id', $file->id)->first();

    $this->actingAs(addOrderUser('supply_chain'))
        ->post(route('uploaded-files.rows.add_order', $file->id), ['source_row_id' => $source->id])
        ->assertForbidden();

    expect($file->rows()->count())->toBe(1);
});

it('requires a source row', function () {
    $file = addOrderFile([['Zara', 'ABC', 'Summer', 'C-1', 'Red', 'PO-1']]);

    $this->actingAs(addOrderUser('merchant'))
        ->post(route('uploaded-files.rows.add_order', $file->id), [])
        ->assertSessionHasErrors('source_row_id');

    expect($file->rows()->count())->toBe(1);
});

/*
 * THE GRID, after the change.
 */

it('no longer renders a per-row Duplicate button, but keeps the bulk actions', function () {
    $file = addOrderFile([['Zara', 'ABC', 'Summer', 'C-1', 'Red', 'PO-1']]);

    $response = $this->actingAs(addOrderUser('merchant'))
        ->get(route('uploaded-files.show', $file->id));

    $response->assertOk();

    // Gone from every row.
    $response->assertDontSee('row-duplicate-btn', false);

    // The new single-row entry point, and both bulk workflows, still there.
    $response->assertSee('Add New Order', false);
    $response->assertSee('addOrderModal', false);
    $response->assertSee('duplicateSelectedButton', false);
    $response->assertSee('row-select', false);
    $response->assertSee('Add New Row', false);
});
