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
function poWithLines(string $poNo, array $descriptions, array $extraCells = []): array
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
    $sapHeader = $header('sap_code', 'SAP Code', 4);
    $piHeader = $header('material_pi_number', 'Material PI Number', 5);

    $rows = [];
    foreach (array_values($descriptions) as $i => $description) {
        $row = ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => $i + 1]);

        ExcelCell::create(['row_id' => $row->id, 'header_id' => $poHeader->id, 'value' => $poNo]);
        ExcelCell::create(['row_id' => $row->id, 'header_id' => $descHeader->id, 'value' => $description]);

        // A style may carry one item or several: the first two lines share a
        // style, the rest get their own.
        ExcelCell::create([
            'row_id' => $row->id,
            'header_id' => $styleHeader->id,
            'value' => 'STYLE-'.($i < 2 ? 1 : $i),
        ]);

        if (isset($extraCells['sap_code'])) {
            ExcelCell::create(['row_id' => $row->id, 'header_id' => $sapHeader->id, 'value' => $extraCells['sap_code']]);
        }

        if (isset($extraCells['pi_number'])) {
            ExcelCell::create(['row_id' => $row->id, 'header_id' => $piHeader->id, 'value' => $extraCells['pi_number']]);
        }

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

it('finds the PO by PO number, SAP code and PI number alike', function () {
    poWithLines('PO-0007', ['Thread Tex 40', 'Zipper'], ['sap_code' => '1000753', 'pi_number' => 'PI-ABC-1']);

    $user = storeUser();

    $byPo = $this->actingAs($user)->getJson(route('store.material.receivings.po-search', ['type' => 'po_no', 'term' => 'PO-0007']));
    $bySap = $this->actingAs($user)->getJson(route('store.material.receivings.po-search', ['type' => 'sap_code', 'term' => '1000753']));
    $byPi = $this->actingAs($user)->getJson(route('store.material.receivings.po-search', ['type' => 'pi_number', 'term' => 'PI-ABC-1']));

    foreach ([$byPo, $bySap, $byPi] as $response) {
        $response->assertOk();
        expect($response->json('results.0.po_no'))->toBe('PO-0007');
    }
});

it('returns every matching PO when one SAP code spans several POs', function () {
    poWithLines('PO-0008', ['Thread Tex 40'], ['sap_code' => 'SHARED-SAP']);
    poWithLines('PO-0009', ['Zipper'], ['sap_code' => 'SHARED-SAP']);

    $results = $this->actingAs(storeUser())
        ->getJson(route('store.material.receivings.po-search', ['type' => 'sap_code', 'term' => 'SHARED-SAP']))
        ->assertOk()
        ->json('results');

    expect(collect($results)->pluck('po_no')->sort()->values()->all())
        ->toEqual(['PO-0008', 'PO-0009']);
});

it('matches partially and case-insensitively, and returns nothing for an unknown term', function () {
    poWithLines('PO-0010', ['Thread Tex 40'], ['pi_number' => 'PI-XYZ-99']);

    $user = storeUser();

    expect($this->actingAs($user)->getJson(route('store.material.receivings.po-search', ['type' => 'pi_number', 'term' => 'pi-xyz']))->json('results'))
        ->toHaveCount(1);

    expect($this->actingAs($user)->getJson(route('store.material.receivings.po-search', ['type' => 'pi_number', 'term' => 'nothing-here']))->json('results'))
        ->toHaveCount(0);
});

it('rejects an unknown search type', function () {
    $this->actingAs(storeUser())
        ->getJson(route('store.material.receivings.po-search', ['type' => 'buyer_name', 'term' => 'x']))
        ->assertStatus(422);
});

it('groups the PO items by style, with one style carrying several items', function () {
    ['po' => $po] = poWithLines('PO-0011', ['Thread Tex 40', 'Thread Tex 24', 'Zipper']);

    $items = $this->actingAs(storeUser())
        ->getJson(route('store.material.receivings.po-items', $po))
        ->assertOk()
        ->json('items');

    $byStyle = collect($items)->groupBy('style_name')->map->count();

    // STYLE-1 carries two items, STYLE-2 carries one — the two-level picker
    // depends on this shape.
    expect($byStyle->get('STYLE-1'))->toBe(2)
        ->and($byStyle->get('STYLE-2'))->toBe(1);
});

it('applies the shared header values to every saved row', function () {
    ['po' => $po, 'rows' => $rows] = poWithLines('PO-0012', ['Thread Tex 40', 'Zipper']);

    // The browser mirrors the shared header into each row, so the payload shape
    // is unchanged — every row still carries its own copy.
    $shared = ['receive_date' => '2026-07-05', 'source_type' => 'internal_po', 'invoice_no' => 'INV-77', 'remarks' => 'Partial delivery'];

    $this->actingAs(storeUser())
        ->post(route('store.material.receivings.store'), [
            'booking_po_id' => $po->id,
            'rows' => [
                array_merge($shared, ['excel_row_id' => $rows[0]->id, 'qty' => 10, 'invoice_qty' => 10, 'unit_price' => 2]),
                array_merge($shared, ['excel_row_id' => $rows[1]->id, 'qty' => 4, 'invoice_qty' => 4, 'unit_price' => 5]),
            ],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $saved = MaterialReceiving::orderBy('id')->get();

    expect($saved)->toHaveCount(2);
    expect($saved->pluck('invoice_no')->unique()->all())->toEqual(['INV-77']);
    expect($saved->pluck('remarks')->unique()->all())->toEqual(['Partial delivery']);
    expect($saved->pluck('source_type')->unique()->all())->toEqual(['internal_po']);
    expect($saved->pluck('receive_date')->map->format('Y-m-d')->unique()->all())->toEqual(['2026-07-05']);

    // Per-row values still differ, and Invoice Value is still computed per row.
    expect((float) $saved[0]->invoice_value)->toBe(20.0)
        ->and((float) $saved[1]->invoice_value)->toBe(20.0)
        ->and($saved->pluck('grn_no')->unique())->toHaveCount(2);
});
