<?php

use App\Models\BookingPo;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\MaterialReceiving;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Multi-item Material Receiving.
 *
 * One PO number spans several BOM lines, but only the primary line owns a
 * BookingPo record — the rest exist purely as worksheet rows. These tests lock
 * that each selected line is saved as its own record, keyed to its OWN
 * excel_row_id (which is what the stock ledger aggregates on), and that a line
 * belonging to a different PO cannot be smuggled in.
 */
function storeUser(): User
{
    Role::findOrCreate('store', 'web');

    return User::factory()->create()->assignRole('store');
}

/**
 * A PO whose primary row is $rows[0] and whose sibling lines are $rows[1..].
 * Each line gets its own style / description / colour cells.
 *
 * @return array{po: BookingPo, rows: array<int, ExcelRow>}
 */
function poWithLines(string $poNo, array $descriptions): array
{
    $file = ExcelFile::create([
        'file_name' => 'bom.xlsx',
        'original_file_name' => 'bom.xlsx',
        'file_path' => 'bom.xlsx',
        'status' => 'completed',
        'uploaded_by' => User::factory()->create()->id,
    ]);

    $ownerRoleId = Role::findOrCreate('merchant', 'web')->id;

    // header_key is globally unique — headers are shared across workbooks.
    $header = fn (string $key, string $name, int $pos) => ExcelHeader::firstOrCreate(
        ['header_key' => $key],
        ['header_name' => $name, 'position' => $pos, 'owner_role_id' => $ownerRoleId]
    );

    $poHeader = $header('material_po_number', 'PO Number', 1);
    $descHeader = $header('material_description', 'Material Description', 2);
    $styleHeader = $header('style_name', 'Style Name', 3);

    $rows = [];
    foreach (array_values($descriptions) as $i => $description) {
        $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => $i + 1]);

        ExcelCell::create(['row_id' => $row->id, 'header_id' => $poHeader->id, 'value' => $poNo]);
        ExcelCell::create(['row_id' => $row->id, 'header_id' => $descHeader->id, 'value' => $description]);
        ExcelCell::create(['row_id' => $row->id, 'header_id' => $styleHeader->id, 'value' => 'STYLE-'.($i + 1)]);

        $rows[] = $row;
    }

    $po = BookingPo::create([
        'excel_file_id' => $file->id,
        'excel_row_id' => $rows[0]->id,
        'po_no' => $poNo,
        'buyer_code' => 'HB',
        'season_code' => '26FA',
        'buyer_name' => 'Buyer A',
        'season_name' => 'Summer 26',
        'style_name' => 'STYLE-1',
        'item_name' => $descriptions[0],
        'uom' => 'PCS',
        'qty' => 100,
    ]);

    return ['po' => $po, 'rows' => $rows];
}

it('lists every material line under the PO, including siblings without a BookingPo record', function () {
    ['po' => $po, 'rows' => $rows] = poWithLines('PO-0001', ['Thread Tex 40', 'Thread Tex 24', 'Zipper']);

    $response = $this->actingAs(storeUser())
        ->getJson(route('store.material.receivings.po-items', $po))
        ->assertOk();

    $items = $response->json('items');

    expect($items)->toHaveCount(3)
        ->and(collect($items)->pluck('excel_row_id')->all())
        ->toEqual(collect($rows)->pluck('id')->all())
        ->and(collect($items)->pluck('material_description')->all())
        ->toEqual(['Thread Tex 40', 'Thread Tex 24', 'Zipper']);
});

it('saves one receiving per selected line, each keyed to its own BOM row and its own GRN', function () {
    ['po' => $po, 'rows' => $rows] = poWithLines('PO-0002', ['Thread Tex 40', 'Thread Tex 24', 'Zipper']);

    $this->actingAs(storeUser())
        ->post(route('store.material.receivings.store'), [
            'booking_po_id' => $po->id,
            'rows' => [
                ['excel_row_id' => $rows[0]->id, 'receive_date' => '2026-07-01', 'source_type' => 'booking', 'qty' => 10, 'invoice_qty' => 10, 'unit_price' => 2.5],
                ['excel_row_id' => $rows[2]->id, 'receive_date' => '2026-07-02', 'source_type' => 'booking', 'qty' => 5,  'invoice_qty' => 4,  'unit_price' => 3],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $saved = MaterialReceiving::orderBy('id')->get();

    expect($saved)->toHaveCount(2);

    // Each record is keyed to the line that was actually received — this is the
    // (excel_row_id, size) key the stock ledger aggregates on.
    expect($saved->pluck('excel_row_id')->all())->toEqual([$rows[0]->id, $rows[2]->id]);
    expect($saved->pluck('material_description')->all())->toEqual(['Thread Tex 40', 'Zipper']);

    // One GRN per record, and they are distinct.
    expect($saved->pluck('grn_no')->filter()->unique())->toHaveCount(2);

    // Invoice Value is recomputed server-side, never taken from the form.
    expect((float) $saved[0]->invoice_value)->toBe(25.0)
        ->and((float) $saved[1]->invoice_value)->toBe(12.0);
});

it('recomputes Invoice Value server-side and ignores any value posted by the client', function () {
    ['po' => $po, 'rows' => $rows] = poWithLines('PO-0003', ['Thread Tex 40']);

    $this->actingAs(storeUser())
        ->post(route('store.material.receivings.store'), [
            'booking_po_id' => $po->id,
            'rows' => [[
                'excel_row_id' => $rows[0]->id,
                'receive_date' => '2026-07-01',
                'source_type' => 'booking',
                'qty' => 10,
                'invoice_qty' => 10,
                'unit_price' => 2,
                'invoice_value' => 999999,
            ]],
        ])
        ->assertRedirect();

    expect((float) MaterialReceiving::first()->invoice_value)->toBe(20.0);
});

it('rejects a line that belongs to a different PO', function () {
    ['po' => $po] = poWithLines('PO-0004', ['Thread Tex 40']);
    ['rows' => $otherRows] = poWithLines('PO-0005', ['Someone Else Fabric']);

    $this->actingAs(storeUser())
        ->post(route('store.material.receivings.store'), [
            'booking_po_id' => $po->id,
            'rows' => [
                ['excel_row_id' => $otherRows[0]->id, 'receive_date' => '2026-07-01', 'source_type' => 'booking', 'qty' => 10],
            ],
        ])
        ->assertSessionHasErrors('rows.0.excel_row_id');

    expect(MaterialReceiving::count())->toBe(0);
});

it('requires Physical Rcv Qty on every row and saves nothing when one row is invalid', function () {
    ['po' => $po, 'rows' => $rows] = poWithLines('PO-0006', ['Thread Tex 40', 'Zipper']);

    $this->actingAs(storeUser())
        ->post(route('store.material.receivings.store'), [
            'booking_po_id' => $po->id,
            'rows' => [
                ['excel_row_id' => $rows[0]->id, 'receive_date' => '2026-07-01', 'source_type' => 'booking', 'qty' => 10],
                ['excel_row_id' => $rows[1]->id, 'receive_date' => '2026-07-01', 'source_type' => 'booking'],
            ],
        ])
        ->assertSessionHasErrors('rows.1.qty');

    expect(MaterialReceiving::count())->toBe(0);
});
