<?php

use App\Models\ActivityLog;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

/**
 * BOM details page: the change history and the original-file download.
 *
 * Both are surfacing things that already existed. Every cell edit has been
 * written to activity_logs since the workspace was built and no screen ever
 * read it; the uploaded file has always been kept on disk with no route to
 * fetch it back.
 */
function detailsUser(string $role = 'merchant'): User
{
    Role::findOrCreate($role, 'web');

    return User::factory()->create()->assignRole($role);
}

function detailsFile(): ExcelFile
{
    return ExcelFile::create([
        'file_name' => 'bom.xlsx',
        'original_file_name' => 'February BOM.xlsx',
        'file_path' => 'uploads/bom.xlsx',
        'status' => 'processing',
        'uploaded_by' => User::factory()->create()->id,
    ]);
}

it('shows who changed a field, and from what to what', function () {
    $file = detailsFile();
    $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => 1]);
    $editor = User::factory()->create(['name' => 'Rashida Khatun']);

    $header = ExcelHeader::firstOrCreate(
        ['header_key' => 'vendor_name'],
        ['header_name' => 'Vendor Name', 'position' => 1, 'owner_role_id' => Role::findOrCreate('merchant', 'web')->id]
    );

    ActivityLog::create([
        'excel_file_id' => $file->id,
        'row_id' => $row->id,
        'header_id' => $header->id,
        'old_value' => 'Old Vendor',
        'new_value' => 'New Vendor',
        'action' => 'updated',
        'user_id' => $editor->id,
    ]);

    $this->actingAs(detailsUser())
        ->get(route('uploaded-files.show', $file->id))
        ->assertOk()
        ->assertSee('Change history')
        ->assertSee('Rashida Khatun')
        ->assertSee('Vendor Name')
        ->assertSee('Old Vendor')
        ->assertSee('New Vendor');
});

it('hides the history panel when the file has none', function () {
    $file = detailsFile();

    $this->actingAs(detailsUser())
        ->get(route('uploaded-files.show', $file->id))
        ->assertOk()
        ->assertDontSee('Change history');
});

it('downloads the original file under the name it was uploaded with', function () {
    Storage::fake();
    Storage::put('uploads/bom.xlsx', 'spreadsheet-bytes');

    $file = detailsFile();

    $response = $this->actingAs(detailsUser())
        ->get(route('uploaded-files.download', $file->id))
        ->assertOk();

    expect($response->headers->get('content-disposition'))
        ->toContain('February BOM.xlsx');
});

it('reports honestly when the stored file has gone missing', function () {
    Storage::fake();

    // Row still references a path, but nothing is on disk behind it.
    $file = detailsFile();

    $this->actingAs(detailsUser())
        ->get(route('uploaded-files.download', $file->id))
        ->assertRedirect()
        ->assertSessionHas('warning');
});

it('refuses the download to someone the file is locked for', function () {
    Storage::fake();
    Storage::put('uploads/bom.xlsx', 'spreadsheet-bytes');

    $file = detailsFile();
    $file->update([
        'is_locked' => true,
        'lock_scope' => 'all_users',
    ]);

    $this->actingAs(detailsUser('commercial'))
        ->get(route('uploaded-files.download', $file->id))
        ->assertForbidden();
});
