<?php

use App\Models\Buyer;
use App\Models\BuyerImportProfile;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Per-buyer column view: Admin > Buyers > Workspace Columns hides the columns
 * a buyer does not use on that buyer's workspace files. Display only.
 */
function bcvHeader(string $name, string $role, int $pos): ExcelHeader
{
    return ExcelHeader::create([
        'header_name' => $name, 'header_key' => Str::snake($name), 'owner_role_id' => Role::findOrCreate($role, 'web')->id,
        'position' => $pos, 'field_type' => 'text', 'value_mode' => 'input', 'is_active' => true, 'can_view_all' => true,
    ]);
}

beforeEach(function () {
    $this->admin = User::factory()->create(['status' => 1])->assignRole(Role::findOrCreate('admin', 'web'));
    $this->buyer = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    $this->style = bcvHeader('Style Ref', 'merchant', 1);
    $this->unused = bcvHeader('Hanger Spec', 'merchant', 2);
    $this->vendor = bcvHeader('Vendor Ref', 'supply_chain', 3);
    $this->file = ExcelFile::create([
        'file_name' => 'b.xlsx', 'original_file_name' => 'b.xlsx', 'file_path' => 'b', 'status' => 'processing',
        'uploaded_by' => $this->admin->id, 'buyer_id' => $this->buyer->id, 'total_rows' => 1,
    ]);
    ExcelRow::create(['excel_file_id' => $this->file->id, 'row_number' => 1]);
});

it('hides the columns Admin unticked for the buyer, and shows them on request', function () {
    $this->actingAs($this->admin)->put(route('admin.buyers.columns.update', $this->buyer), [
        'shown' => [$this->style->id, $this->vendor->id],
    ])->assertRedirect(route('admin.buyers.columns.edit', $this->buyer));

    expect($this->buyer->hiddenHeaders()->pluck('excel_headers.id')->all())->toBe([$this->unused->id]);

    $this->get(route('uploaded-files.show', $this->file))->assertOk()
        ->assertViewHas('headers', fn ($h) => ! $h->contains('id', $this->unused->id) && $h->contains('id', $this->vendor->id))
        ->assertSee('1 other columns are hidden');

    $this->get(route('uploaded-files.show', [$this->file, 'columns' => 'all']))->assertOk()
        ->assertViewHas('headers', fn ($h) => $h->contains('id', $this->unused->id));

    // The choice is remembered for the file until switched back.
    $this->get(route('uploaded-files.show', $this->file))
        ->assertViewHas('headers', fn ($h) => $h->contains('id', $this->unused->id));
});

it('sets the columns from the buyer saved matching, keeping other departments shown', function () {
    $profile = BuyerImportProfile::create(['buyer_id' => $this->buyer->id, 'name' => 'Ann Taylor']);
    $profile->columns()->create(['source_header' => 'Style', 'source_key' => 'style', 'excel_header_id' => $this->style->id]);

    $this->actingAs($this->admin)->post(route('admin.buyers.columns.from_profile', $this->buyer))->assertRedirect();

    expect($this->buyer->hiddenHeaders()->pluck('excel_headers.id')->all())->toBe([$this->unused->id]);

    $this->get(route('admin.buyers.columns.edit', $this->buyer))->assertOk()->assertSee('Saved column matching found')->assertSee('<span data-shown-count>2</span> of 3 columns shown', false);
});

it('leaves files of a buyer with no hidden columns unchanged', function () {
    $this->actingAs($this->admin)->get(route('uploaded-files.show', $this->file))->assertOk()
        ->assertViewHas('headers', fn ($h) => $h->count() === 3)
        ->assertDontSee('other columns are hidden');
});
