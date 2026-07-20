<?php

use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use App\Services\ExcelFileSummaryService;
use Spatie\Permission\Models\Role;

/**
 * The workspace file list and the summary lookup behind it.
 *
 * The list previously eager-loaded rows.cells.header to read five values from
 * each file's first row, hydrating every cell in the database to do it. These
 * lock the replacement: the same values, without touching rows beyond the
 * first, and the list still rendering for every role that shows it.
 */
function workspaceUser(string $role): User
{
    Role::findOrCreate($role, 'web');

    return User::factory()->create()->assignRole($role);
}

function fileWithRows(array $firstRowValues, int $extraRows = 0): ExcelFile
{
    $owner = Role::findOrCreate('merchant', 'web');

    $file = ExcelFile::create([
        'file_name' => 'bom.xlsx',
        'original_file_name' => 'bom.xlsx',
        'file_path' => 'bom.xlsx',
        'status' => 'processing',
        'uploaded_by' => User::factory()->create()->id,
    ]);

    $header = fn (string $name) => ExcelHeader::firstOrCreate(
        ['header_key' => \Illuminate\Support\Str::snake($name)],
        ['header_name' => $name, 'position' => 1, 'owner_role_id' => $owner->id]
    );

    $first = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => 1]);

    foreach ($firstRowValues as $name => $value) {
        ExcelCell::create([
            'row_id' => $first->id,
            'header_id' => $header($name)->id,
            'value' => $value,
        ]);
    }

    // Later rows carry values too — the summary must ignore them.
    for ($i = 0; $i < $extraRows; $i++) {
        $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => $i + 2]);

        ExcelCell::create([
            'row_id' => $row->id,
            'header_id' => $header('Buyer Name')->id,
            'value' => 'SHOULD NOT APPEAR',
        ]);
    }

    return $file;
}

it('reads the summary from the first row only', function () {
    $file = fileWithRows([
        'Buyer Name' => 'Buyer A',
        'Season Name' => 'Summer 26',
        'Style Name' => 'STYLE-1',
        'Contract Number' => 'CN-900',
        'Contract Shipment Date' => '2026-08-01',
    ], extraRows: 5);

    $summary = app(ExcelFileSummaryService::class)->for(collect([$file]));

    expect($summary[$file->id]['Buyer Name'])->toBe('Buyer A')
        ->and($summary[$file->id]['Season Name'])->toBe('Summer 26')
        ->and($summary[$file->id]['Style Name'])->toBe('STYLE-1')
        ->and($summary[$file->id]['Contract Number'])->toBe('CN-900')
        ->and($summary[$file->id]['Contract Shipment Date'])->toBe('2026-08-01');
});

it('does not load rows beyond the first', function () {
    fileWithRows(['Buyer Name' => 'Buyer A'], extraRows: 40);

    $files = ExcelFile::all();

    DB::enableQueryLog();
    app(ExcelFileSummaryService::class)->for($files);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // Three targeted lookups: first rows, headers, cells. The point is that it
    // is a fixed number regardless of how large the BOM is.
    expect(count($queries))->toBeLessThanOrEqual(3);
});

it('returns blanks for a file with no rows rather than failing', function () {
    $file = ExcelFile::create([
        'file_name' => 'empty.xlsx',
        'original_file_name' => 'empty.xlsx',
        'file_path' => 'empty.xlsx',
        'status' => 'pending',
        'uploaded_by' => User::factory()->create()->id,
    ]);

    $summary = app(ExcelFileSummaryService::class)->for(collect([$file]));

    expect($summary[$file->id]['Buyer Name'])->toBe('');
});

it('handles being given no files at all', function () {
    expect(app(ExcelFileSummaryService::class)->for(collect()))->toBe([]);
});

dataset('workspaces', [
    'merchant' => ['merchant.workspace', 'merchant'],
    'store' => ['store.workspace', 'store'],
    'admin' => ['admin.workspace', 'admin'],
    'commercial' => ['commercial.workspace', 'commercial'],
    'account' => ['account.workspace', 'account'],
    'supply chain' => ['supply_chain.workspace', 'supply_chain'],
]);

it('renders the file list with the summary values', function (string $routeName, string $role) {
    fileWithRows([
        'Buyer Name' => 'Buyer Zeta',
        'Style Name' => 'STYLE-ZETA',
    ], extraRows: 3);

    $this->actingAs(workspaceUser($role))
        ->get(route($routeName))
        ->assertOk()
        ->assertSee('Buyer Zeta')
        ->assertSee('STYLE-ZETA')
        ->assertDontSee('SHOULD NOT APPEAR');
})->with('workspaces');

it('offers export but never a bulk delete', function () {
    fileWithRows(['Buyer Name' => 'Buyer A']);

    $response = $this->actingAs(workspaceUser('admin'))
        ->get(route('admin.workspace'))
        ->assertOk();

    $response->assertSee('data-file-export', false)
        ->assertSee('data-file-select-all', false);

    // Deleting a file removes rows that booking POs, receivings and the stock
    // ledger reference by excel_row_id, so it stays a deliberate, one-at-a-time
    // action.
    $response->assertDontSee('data-file-bulk-delete', false);
});
