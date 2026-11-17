<?php

use App\Models\Buyer;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * PO Generate pending list filters: two registered buyers whose sheets both say
 * the same text in Buyer Name stay two choices, and the keyword box searches the
 * booking columns it names, not every cell of the row.
 */
beforeEach(function () {
    $owner = Role::findOrCreate('supply_chain', 'web');
    $this->user = User::factory()->create()->assignRole($owner);
    $uploader = User::factory()->create();

    $pos = 1;
    foreach (['buyer_name' => 'Buyer Name', 'season_name' => 'Season Name', 'contract_shipment_date' => 'Contract Shipment Date',
              'material_description' => 'Material Description', 'sap_code' => 'SAP Code', 'vendor_name' => 'Vendor Name',
              'remarks' => 'Remarks', 'materials_ordered' => 'Materials to be Ordered'] as $key => $name) {
        $this->h[$key] = ExcelHeader::create(['header_name' => $name, 'header_key' => $key, 'owner_role_id' => $owner->id, 'position' => $pos++, 'field_type' => 'text', 'is_active' => true]);
    }

    $this->woven = Buyer::create(['buyer_name' => 'ANNTAYLOR (WOVEN)', 'is_active' => true]);
    $this->denim = Buyer::create(['buyer_name' => 'ANNTAYLOR (DENIM)', 'is_active' => true]);

    $this->fileFor = fn (Buyer $b) => ExcelFile::create(['file_name' => $b->id.'.xlsx', 'original_file_name' => $b->id.'.xlsx', 'file_path' => 'x', 'uploaded_by' => $uploader->id, 'buyer_id' => $b->id, 'total_rows' => 0]);
    $this->n = 0;
    $this->line = function (ExcelFile $file, array $values) {
        $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => ++$this->n]);
        foreach ($values + ['buyer_name' => 'Ann-Taylor', 'season_name' => 'SU27', 'contract_shipment_date' => '2027-01-25'] as $key => $v) {
            ExcelCell::create(['row_id' => $row->id, 'header_id' => $this->h[$key]->id, 'value' => (string) $v]);
        }

        return $row;
    };

    $this->wovenFile = ($this->fileFor)($this->woven);
    $this->denimFile = ($this->fileFor)($this->denim);
    ($this->line)($this->wovenFile, ['material_description' => 'POCKETING', 'vendor_name' => 'V1', 'sap_code' => 'S1', 'materials_ordered' => '5']);
    ($this->line)($this->wovenFile, ['material_description' => 'THREAD', 'vendor_name' => 'V2', 'sap_code' => 'S2', 'materials_ordered' => '5', 'remarks' => 'for pocketing area']);
    ($this->line)($this->denimFile, ['material_description' => 'ZIPPER', 'vendor_name' => 'V3', 'sap_code' => 'S3', 'materials_ordered' => '5']);
});

it('offers each registered buyer, even when the sheets carry the same Buyer Name text', function () {
    $options = $this->withoutVite()->actingAs($this->user)->get(route('supply_chain.bookings.index'))->assertOk()->viewData('filterOptions')['buyers'];

    expect(collect($options)->pluck('label')->all())->toBe(['ANNTAYLOR (DENIM)', 'ANNTAYLOR (WOVEN)']);
});

it('filters by the chosen registered buyer', function () {
    $page = $this->withoutVite()->actingAs($this->user)->get(route('supply_chain.bookings.index', ['buyer' => 'id:'.$this->denim->id]))->assertOk()->viewData('pendingRows');
    expect($page->total())->toBe(1);

    $page = $this->withoutVite()->actingAs($this->user)->get(route('supply_chain.bookings.index', ['buyer' => 'id:'.$this->woven->id]))->viewData('pendingRows');
    expect($page->total())->toBe(2);
});

it('searches the item and booking columns, not a remark on another material', function () {
    $page = $this->withoutVite()->actingAs($this->user)->get(route('supply_chain.bookings.index', ['keyword' => 'pocketing']))->assertOk()->viewData('pendingRows');

    expect($page->total())->toBe(1)
        ->and($page->first()->booking_preview['item_name'])->toBe('POCKETING');
});
