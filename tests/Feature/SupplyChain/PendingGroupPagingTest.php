<?php

use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * PO Generate pending list: one list row is one booking group, so the list is
 * paged by group. It used to page 25 source rows and merge them afterwards, which
 * showed a few rows per page against a total of source rows, and split a group
 * across pages.
 */
beforeEach(function () {
    $owner = Role::findOrCreate('supply_chain', 'web');
    $this->user = User::factory()->create()->assignRole($owner);

    $position = 1;
    $this->headers = [];
    foreach ([
        'buyer_name' => 'Buyer Name', 'season_name' => 'Season Name', 'contract_shipment_date' => 'Contract Shipment Date',
        'material_description' => 'Material Description', 'sap_code' => 'SAP Code', 'vendor_name' => 'Vendor Name',
        'materials_ordered' => 'Materials to be Ordered',
    ] as $key => $name) {
        $this->headers[$key] = ExcelHeader::create([
            'header_name' => $name, 'header_key' => $key, 'owner_role_id' => $owner->id,
            'position' => $position++, 'field_type' => 'text', 'is_active' => true,
        ]);
    }

    $this->file = ExcelFile::create([
        'file_name' => 'bom.xlsx', 'original_file_name' => 'bom.xlsx', 'file_path' => 'bom.xlsx',
        'uploaded_by' => User::factory()->create()->id, 'total_rows' => 0,
    ]);
    $this->n = 0;

    $this->line = function (array $values) {
        $row = ExcelRow::create(['excel_file_id' => $this->file->id, 'row_number' => ++$this->n]);
        foreach ($values + ['buyer_name' => 'Ann Taylor', 'season_name' => 'SU27', 'contract_shipment_date' => '2027-01-25'] as $key => $value) {
            ExcelCell::create(['row_id' => $row->id, 'header_id' => $this->headers[$key]->id, 'value' => (string) $value]);
        }

        return $row;
    };

    // 30 groups of two rows (same vendor and SAP Code), plus 5 rows with no vendor and
    // no SAP Code, which share one group by buyer, season and ship date.
    foreach (range(1, 30) as $i) {
        foreach ([1, 2] as $part) {
            ($this->line)(['material_description' => "Item $i-$part", 'vendor_name' => "Vendor $i", 'sap_code' => "SAP$i", 'materials_ordered' => '10']);
        }
    }
    foreach (range(1, 5) as $i) {
        ($this->line)(['material_description' => "Loose item $i", 'materials_ordered' => '4']);
    }
});

it('pages the pending list by booking group, not by source row', function () {
    $page1 = $this->withoutVite()->actingAs($this->user)->get(route('supply_chain.bookings.index'))->assertOk()->viewData('pendingRows');

    // 65 source rows, 31 groups: 30 vendor/SAP pairs and one blank-vendor date group.
    expect($page1->total())->toBe(31)
        ->and($page1->perPage())->toBe(25)
        ->and($page1->count())->toBe(25)
        ->and($page1->lastPage())->toBe(2);

    $page2 = $this->withoutVite()->actingAs($this->user)->get(route('supply_chain.bookings.index', ['page' => 2]))->viewData('pendingRows');
    expect($page2->count())->toBe(6);

    // No source row is listed twice, and none is lost, across the two pages.
    $leaders = $page1->pluck('id')->merge($page2->pluck('id'));
    expect($leaders->unique()->count())->toBe(31);
});

it('shows what each group holds: how many source rows, which items, how much', function () {
    $page = $this->withoutVite()->actingAs($this->user)->get(route('supply_chain.bookings.index', ['per_page' => 50]))->viewData('pendingRows');

    expect($page->count())->toBe(31)->and($page->perPage())->toBe(50);

    $pair = $page->first(fn ($row) => str_contains((string) json_encode($row->booking_group_items), 'Item 7-1'));
    expect($pair->booking_group_count)->toBe(2)
        ->and($pair->booking_group_qty_total)->toBe(20.0)
        ->and($pair->booking_group_items)->toBe(['Item 7-1', 'Item 7-2']);

    $loose = $page->first(fn ($row) => $row->booking_group_count === 5);
    expect($loose)->not->toBeNull()->and($loose->booking_group_qty_total)->toBe(20.0);
});

it('ignores a rows-per-page value the list does not offer', function () {
    $page = $this->withoutVite()->actingAs($this->user)->get(route('supply_chain.bookings.index', ['per_page' => 5000]))->viewData('pendingRows');

    expect($page->perPage())->toBe(25);
});

it('keeps the rows-per-page choice in the page links', function () {
    $page = $this->withoutVite()->actingAs($this->user)->get(route('supply_chain.bookings.index', ['per_page' => 25, 'buyer' => 'Ann Taylor']))->viewData('pendingRows');

    expect($page->nextPageUrl())->toContain('per_page=25')->toContain('buyer=Ann%20Taylor');
});


it('works a group\'s quantity out live when the stored Materials to be Ordered still says 0', function () {
    // The stored cell can hold 0 while the workspace shows the live figure; the list,
    // the preview and the PO must all use the live one.
    foreach (['bom_quantity' => 'BOM Quantity', 'booking_consumption_from_cad' => 'Booking Consumption from CAD', 'wastage_for_ordering_percent' => '% Wastage for ordering'] as $key => $name) {
        $this->headers[$key] = ExcelHeader::create([
            'header_name' => $name, 'header_key' => $key, 'owner_role_id' => Role::findOrCreate('merchant', 'web')->id,
            'position' => 30, 'field_type' => 'text', 'is_active' => true,
        ]);
    }

    foreach ([1, 2] as $part) {
        ($this->line)([
            'material_description' => "Live item $part", 'vendor_name' => 'Live Vendor', 'sap_code' => 'LIVE-1',
            'materials_ordered' => '0', 'bom_quantity' => '1000', 'booking_consumption_from_cad' => '0.5', 'wastage_for_ordering_percent' => '0.1',
        ]);
    }

    $page = $this->withoutVite()->actingAs($this->user)->get(route('supply_chain.bookings.index', ['per_page' => 100]))->viewData('pendingRows');
    $group = $page->first(fn ($row) => in_array('Live item 1', (array) $row->booking_group_items, true));

    // 0.5 x (1 + 0.1) x 1000 = 550 for each of the two rows.
    expect($group->booking_group_count)->toBe(2)->and($group->booking_group_qty_total)->toBe(1100.0);
});
