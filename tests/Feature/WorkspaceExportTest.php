<?php

use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use Spatie\Permission\Models\Role;

it('exports every row of a workspace file with the data entered since upload', function () {
    $role = Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $header = ExcelHeader::create([
        'header_name' => 'Vendor Name', 'header_key' => 'vendor_name', 'owner_role_id' => $role->id,
        'position' => 1, 'field_type' => 'text', 'is_active' => true,
    ]);

    $file = ExcelFile::create([
        'file_name' => 'bom.xlsx', 'original_file_name' => 'bom.xlsx', 'file_path' => 'bom.xlsx',
        'uploaded_by' => $admin->id, 'total_rows' => 2,
    ]);

    foreach ([1 => 'Alpha Fabrics', 2 => 'Beta Trims'] as $number => $vendor) {
        $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => $number]);
        ExcelCell::create(['row_id' => $row->id, 'header_id' => $header->id, 'value' => $vendor]);
    }

    $response = $this->actingAs($admin)->get(route('uploaded-files.download_updated', $file));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('UPDATED');
});
