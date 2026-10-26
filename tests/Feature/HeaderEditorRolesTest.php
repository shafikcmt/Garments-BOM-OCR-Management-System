<?php

use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Admin > Excel Header Control: a column can be edited by its owner role and
 * by any extra roles Admin ticks under "Also Editable By" (e.g. shipment
 * columns kept by both Merchandising and Commercial). No role is named in
 * code; it is all data.
 */
function editorUser(string $role): User
{
    return User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate($role, 'web'));
}

beforeEach(function () {
    $merchant = editorUser('merchant');
    $this->file = ExcelFile::create([
        'file_name' => 'bom.xlsx', 'original_file_name' => 'bom.xlsx', 'file_path' => 'bom.xlsx',
        'status' => 'processing', 'uploaded_by' => $merchant->id, 'total_rows' => 0,
    ]);
    $this->header = ExcelHeader::create([
        'header_name' => 'Forwarder', 'header_key' => 'forwarder',
        'owner_role_id' => Role::findOrCreate('merchant', 'web')->id, 'position' => 1,
        'field_type' => 'text', 'value_mode' => 'manual', 'is_active' => true, 'can_view_all' => true,
    ]);
    $this->row = ExcelRow::create(['excel_file_id' => $this->file->id, 'row_number' => 1]);
});

function saveForwarder(User $user, string $value)
{
    return test()->actingAs($user)->put(route('uploaded-files.update', test()->file->id), [
        'cells' => [test()->row->id => [test()->header->id => $value]],
    ]);
}

function forwarderValue(): ?string
{
    return ExcelCell::where('row_id', test()->row->id)->where('header_id', test()->header->id)->value('value');
}

it('lets only the owner role edit a column with no extra editors', function () {
    saveForwarder(editorUser('commercial'), 'DHL');

    expect(forwarderValue())->toBeNull()
        ->and($this->header->isEditableByRoleIds([Role::findOrCreate('commercial', 'web')->id]))->toBeFalse();
});

it('lets a role Admin added as an extra editor edit the column', function () {
    $this->header->editorRoles()->sync([Role::findOrCreate('commercial', 'web')->id]);

    saveForwarder(editorUser('commercial'), 'DHL');

    expect(forwarderValue())->toBe('DHL');
});

it('saves the extra editor roles from the Excel Header Control form', function () {
    $admin = editorUser('admin');
    $commercial = Role::findOrCreate('commercial', 'web');
    $merchantId = $this->header->owner_role_id;

    $this->actingAs($admin)->put(route('admin.headers.update', $this->header), [
        'header_name' => 'Forwarder', 'header_key' => 'forwarder', 'owner_role_id' => $merchantId,
        'position' => 1, 'field_type' => 'text', 'value_mode' => 'input',
        'is_active' => 1, 'can_view_all' => 1,
        // The owner role ticked too: it is dropped, the owner always edits.
        'editor_role_ids' => [$commercial->id, $merchantId],
    ])->assertRedirect(route('admin.headers.index'));

    $this->header->refresh();
    expect($this->header->editorRoles->pluck('id')->all())->toBe([$commercial->id])
        ->and($this->header->can_edit_owner_only)->toBeFalse();

    $this->get(route('admin.headers.index'))->assertOk()->assertSee('Also editable by Commercial');

    // Unticking every role puts the column back to owner only.
    $this->put(route('admin.headers.update', $this->header), [
        'header_name' => 'Forwarder', 'header_key' => 'forwarder', 'owner_role_id' => $merchantId,
        'position' => 1, 'field_type' => 'text', 'value_mode' => 'input', 'is_active' => 1,
    ]);
    expect($this->header->fresh()->editorRoles)->toBeEmpty()
        ->and($this->header->fresh()->can_edit_owner_only)->toBeTrue();
});
