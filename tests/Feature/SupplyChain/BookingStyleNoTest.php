<?php

use App\Http\Controllers\SupplyChain\BookingController;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use Spatie\Permission\Models\Role;

/**
 * The booking's Style No is the style, not the contract number, even when the
 * contract number column comes first in the row.
 */
function bsnValue(array $cells): ?string
{
    $role = Role::findOrCreate('merchant', 'web');
    $file = ExcelFile::create(['uploaded_by' => \App\Models\User::factory()->create()->id, 'file_name' => 'f.xlsx', 'original_file_name' => 'f.xlsx', 'file_path' => 'x', 'status' => 'pending', 'total_rows' => 1]);
    $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => 1]);
    $pos = 1;

    foreach ($cells as [$name, $key, $value]) {      // created in this order, so the contract cell has the lower id
        $header = ExcelHeader::create(['header_name' => $name, 'header_key' => $key, 'owner_role_id' => $role->id, 'position' => $pos++]);
        ExcelCell::create(['row_id' => $row->id, 'header_id' => $header->id, 'value' => $value]);
    }

    $controller = app(BookingController::class);
    $method = new ReflectionMethod($controller, 'valueFor');
    $method->setAccessible(true);

    return $method->invoke($controller, $row->fresh()->load('cells.header'), 'style');
}

it('uses the style name even when the contract number column comes first', function () {
    expect(bsnValue([
        ['Initial Contract Number', 'initial_contract_number', 'HAPLANN/SUM27/11'],
        ['Style Name', 'style_name', '607294(MISSY)'],
    ]))->toBe('607294(MISSY)');
});

it('falls back to the contract number only when there is no style', function () {
    expect(bsnValue([
        ['Initial Contract Number', 'initial_contract_number', 'HAPLANN/SUM27/11'],
        ['Style Name', 'style_name', ''],
    ]))->toBe('HAPLANN/SUM27/11');
});
