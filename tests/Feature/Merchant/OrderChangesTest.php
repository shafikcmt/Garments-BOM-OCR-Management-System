<?php

use App\Models\Buyer;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\MotherStyle;
use App\Models\User;
use App\Services\BomTableSync;
use Spatie\Permission\Models\Role;

/**
 * A style's Order Changes box: workspace edits to columns marked "Show in
 * Order Changes", who made them and when.
 */
beforeEach(function () {
    $role = Role::findOrCreate('merchant', 'web');
    $this->merchant = User::factory()->create(['status' => 1])->assignRole($role);
    $buyer = Buyer::create(['buyer_name' => 'Ann Taylor', 'is_active' => true]);
    $this->file = ExcelFile::create(['file_name' => 'c.xlsx', 'original_file_name' => 'c.xlsx', 'file_path' => 'c',
        'status' => 'processing', 'uploaded_by' => $this->merchant->id, 'buyer_id' => $buyer->id]);

    $make = fn ($key, $name, $pos, $track = false) => ExcelHeader::create(['header_key' => $key, 'header_name' => $name,
        'owner_role_id' => $role->id, 'position' => $pos, 'field_type' => 'text', 'value_mode' => 'manual', 'is_active' => true,
        'can_view_all' => true, 'track_order_changes' => $track]);
    $h = [
        'season_name' => $make('season_name', 'Season Name', 1), 'style_name' => $make('style_name', 'Style', 2),
        'gmts_color_name' => $make('gmts_color_name', 'GMTS Color Name', 3), 'contract_number' => $make('contract_number', 'Contract Number', 4),
        // Customer Contract Quantity is calculated in the workspace; the ship date is typed.
        'qty' => $make('contract_shipment_date', 'Contract Shipment date', 5, true),
        'material_description' => $make('material_description', 'Material Description', 6),
        'remark' => $make('merchant_remark', 'Merchant Remark', 7),
    ];
    $this->h = $h;
    $this->rows = collect(['Shell', 'Lining'])->map(function ($material, $i) use ($h) {
        $row = ExcelRow::create(['excel_file_id' => $this->file->id, 'row_number' => $i + 1]);
        foreach (['season_name' => 'SP27', 'style_name' => '748268', 'gmts_color_name' => 'WHITE', 'contract_number' => 'C1', 'qty' => '2026-11-05', 'material_description' => $material] as $k => $v) {
            ExcelCell::create(['row_id' => $row->id, 'header_id' => $h[$k]->id, 'value' => $v]);
        }

        return $row;
    });
    app(BomTableSync::class)->syncFile($this->file);
    $this->mother = MotherStyle::where('style_no', '748268')->firstOrFail();
});

it('lists a ship date change once with who and when, and skips untracked columns', function () {
    $this->actingAs($this->merchant)->put(route('uploaded-files.update', $this->file->id), ['cells' => [
        $this->rows[0]->id => [$this->h['qty']->id => '2026-11-20', $this->h['remark']->id => 'call mill'],
        $this->rows[1]->id => [$this->h['qty']->id => '2026-11-20'],
    ]])->assertSessionHas('success');

    $changes = $this->get(route('merchant.styles.mothers.show', $this->mother))->assertOk()
        ->assertSee('Order Changes')->viewData('orderChanges');

    expect($changes)->toHaveCount(1)
        ->and($changes[0])->toMatchArray(['field' => 'Contract Shipment date', 'old' => '2026-11-05', 'new' => '2026-11-20', 'by' => $this->merchant->name, 'color' => 'WHITE', 'po' => 'C1', 'lines' => 2]);
});

it('shows nothing when Admin tracks no column', function () {
    ExcelHeader::query()->update(['track_order_changes' => false]);

    $this->actingAs($this->merchant)->put(route('uploaded-files.update', $this->file->id), ['cells' => [
        $this->rows[0]->id => [$this->h['qty']->id => '2026-12-01'],
    ]]);

    $this->get(route('merchant.styles.mothers.show', $this->mother))
        ->assertViewHas('orderChanges', fn ($c) => $c->isEmpty())->assertSee('No changes to the order since it came in.');
});
