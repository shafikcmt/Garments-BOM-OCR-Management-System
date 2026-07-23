<?php

use App\Models\BookingPo;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use App\Services\BookingPoSourceService;
use Spatie\Permission\Models\Role;

/**
 * "Garments PO" as a fourth search handle in Bulk Issue.
 *
 * Two different PO identifiers live side by side and must never be conflated:
 *
 *   PO Number   -> material_po_number, the PO this system generates
 *                  (HB26FA0004) and mirrors onto booking_pos.po_no.
 *   Garments PO -> garments_po, the buyer's own garment-level PO (12458787),
 *                  a Merchant-owned BOM column.
 *
 * They never share a value, so searching one must never return the other's
 * matches. That separation is what these tests hold in place.
 */
function garmentsPoUser(): User
{
    Role::findOrCreate('store', 'web');

    return User::factory()->create()->assignRole('store');
}

/**
 * One BOM row carrying both PO identifiers, plus the BookingPo the material PO
 * resolves to.
 */
function rowWithBothPos(string $materialPo, string $garmentsPo): BookingPo
{
    $ownerRoleId = Role::findOrCreate('merchant', 'web')->id;

    $file = ExcelFile::create([
        'file_name' => 'bom.xlsx',
        'original_file_name' => 'bom.xlsx',
        'file_path' => 'bom.xlsx',
        'status' => 'completed',
        'uploaded_by' => User::factory()->create()->id,
    ]);

    $header = fn (string $key, string $name, int $pos) => ExcelHeader::firstOrCreate(
        ['header_key' => $key],
        ['header_name' => $name, 'position' => $pos, 'owner_role_id' => $ownerRoleId]
    );

    $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => 1]);

    ExcelCell::create([
        'row_id' => $row->id,
        'header_id' => $header('material_po_number', 'Material PO Number', 1)->id,
        'value' => $materialPo,
    ]);
    ExcelCell::create([
        'row_id' => $row->id,
        'header_id' => $header('garments_po', 'Garments PO', 2)->id,
        'value' => $garmentsPo,
    ]);

    return BookingPo::create([
        'excel_file_id' => $file->id,
        'excel_row_id' => $row->id,
        'po_no' => $materialPo,
        'buyer_name' => 'Hugo Boss',
        'season_name' => 'W26FA',
        'style_name' => 'STYLE-1',
        'item_name' => 'Accessories',
    ]);
}

it('finds the PO by its Garments PO number', function () {
    $po = rowWithBothPos('HB26FA0004', '12458787');

    $matches = app(BookingPoSourceService::class)->bookingPosMatching('garments_po', '12458787');

    expect($matches->pluck('id')->all())->toEqual([$po->id]);
});

it('keeps the two PO identifiers apart', function () {
    rowWithBothPos('HB26FA0004', '12458787');

    $service = app(BookingPoSourceService::class);

    // The garment PO is not a material PO...
    expect($service->bookingPosMatching('po_no', '12458787'))->toBeEmpty();

    // ...and the material PO is not a garment PO.
    expect($service->bookingPosMatching('garments_po', 'HB26FA0004'))->toBeEmpty();
});

it('leaves the existing PO Number search working exactly as before', function () {
    $po = rowWithBothPos('HB26FA0004', '12458787');

    $matches = app(BookingPoSourceService::class)->bookingPosMatching('po_no', 'HB26FA0004');

    expect($matches->pluck('id')->all())->toEqual([$po->id]);
});

it('does not pull in the separate GMNTS PO Number column', function () {
    $po = rowWithBothPos('HB26FA0004', '12458787');

    // A different Store-owned column whose relationship to Garments PO is an
    // open business question — it must not be silently merged into this search.
    ExcelCell::create([
        'row_id' => $po->excel_row_id,
        'header_id' => ExcelHeader::firstOrCreate(
            ['header_key' => 'gmnts_po_number'],
            [
                'header_name' => 'GMNTS PO Number',
                'position' => 3,
                'owner_role_id' => Role::findOrCreate('store', 'web')->id,
            ]
        )->id,
        'value' => '4800093966',
    ]);

    expect(app(BookingPoSourceService::class)->bookingPosMatching('garments_po', '4800093966'))
        ->toBeEmpty();
});

it('accepts garments_po as a search type on the endpoint', function () {
    rowWithBothPos('HB26FA0004', '12458787');

    $results = $this->actingAs(garmentsPoUser())
        ->getJson(route('store.material.bulk-issues.po-search', [
            'type' => 'garments_po', 'term' => '12458787',
        ]))
        ->assertOk()
        ->json('results');

    expect(collect($results)->pluck('po_no')->all())->toEqual(['HB26FA0004']);
});

it('still rejects an unknown search type', function () {
    $this->actingAs(garmentsPoUser())
        ->getJson(route('store.material.bulk-issues.po-search', ['type' => 'nonsense']))
        ->assertStatus(422);
});

it('offers Garments PO in the browse list without a term', function () {
    rowWithBothPos('HB26FA0004', '12458787');

    $options = app(BookingPoSourceService::class)->browseOptionsForGroup('garments_po', 50);

    expect($options->pluck('value')->all())->toContain('12458787');
});
