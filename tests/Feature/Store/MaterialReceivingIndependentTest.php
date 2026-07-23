<?php

use App\Models\MaterialReceiving;
use App\Models\MaterialStockLedger;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Independent receiving — material that physically arrived but whose paperwork
 * matches no PO / PI / Invoice yet.
 *
 * The behaviour that matters is the stock guarantee: an unmatched delivery must
 * never reach the ledger, because there is no BOM row to book it against and a
 * guess would inflate someone's closing stock. It joins the ledger only once a
 * human links it to a real material line.
 *
 * Helpers (storeUser, poWithLines) come from MaterialReceivingMultiItemTest.
 */
/**
 * Linking a receiving to a PO is a correction (it moves stock into the
 * ledger), so it needs store.edit — an Admin/Management right, not a Store one.
 */
function receivingCorrectorUser(): User
{
    $role = Role::findOrCreate('admin', 'web');

    foreach (['store.edit', 'store.delete'] as $name) {
        Permission::findOrCreate($name, 'web');

        if (! $role->hasPermissionTo($name)) {
            $role->givePermissionTo($name);
        }
    }

    return User::factory()->create()->assignRole($role);
}

it('records an independent receiving against a style, with no PO attached', function () {
    poWithLines('PO-9001', ['Thread Tex 40']);

    $this->actingAs(storeUser())
        ->post(route('store.material.receivings.independent'), [
            'buyer_name' => 'Buyer A',
            'style_name' => 'STYLE-1',
            'season_name' => 'Summer 26',
            'material_name' => 'Unmatched Cord',
            'receive_date' => '2026-07-20',
            'source_type' => 'booking',
            'qty' => 25,
        ])
        ->assertSessionHas('success');

    $receiving = MaterialReceiving::sole();

    expect($receiving->match_status)->toBe(MaterialReceiving::MATCH_INDEPENDENT)
        ->and($receiving->booking_po_id)->toBeNull()
        ->and($receiving->excel_row_id)->toBeNull()
        ->and($receiving->style_name)->toBe('STYLE-1')
        ->and((float) $receiving->qty)->toBe(25.0)
        // Numbered on the same sequence as PO-linked GRNs, so the two can never
        // collide.
        ->and($receiving->grn_no)->toStartWith('GRN-');
});

it('keeps an independent receiving out of the stock ledger', function () {
    poWithLines('PO-9002', ['Thread Tex 40']);

    $this->actingAs(storeUser())
        ->post(route('store.material.receivings.independent'), [
            'buyer_name' => 'Buyer A',
            'style_name' => 'STYLE-1',
            'material_name' => 'Unmatched Cord',
            'receive_date' => '2026-07-20',
            'source_type' => 'booking',
            'qty' => 25,
        ])->assertSessionHas('success');

    expect(MaterialStockLedger::count())->toBe(0);
});

it('requires a style, a material name and a quantity', function () {
    $this->actingAs(storeUser())
        ->post(route('store.material.receivings.independent'), [
            'receive_date' => '2026-07-20',
            'source_type' => 'booking',
        ])
        ->assertSessionHasErrors(['buyer_name', 'style_name', 'material_name', 'qty']);

    expect(MaterialReceiving::count())->toBe(0);
});

it('values an independent receiving on the physical qty, not the invoice qty', function () {
    poWithLines('PO-9200', ['Thread Tex 40']);

    $this->actingAs(storeUser())
        ->post(route('store.material.receivings.independent'), [
            'buyer_name' => 'Buyer A',
            'style_name' => 'STYLE-1',
            'material_name' => 'Unmatched Cord',
            'receive_date' => '2026-07-20',
            'source_type' => 'booking',
            // Invoiced for 500 but only 300 physically arrived.
            'invoice_qty' => 500,
            'qty' => 300,
            'unit_price' => 1.5,
        ])->assertSessionHas('success');

    // 300 x 1.50, not 500 x 1.50.
    expect((float) MaterialReceiving::sole()->invoice_value)->toBe(450.0);
});

it('recomputes invoice value rather than trusting the form', function () {
    poWithLines('PO-9003', ['Thread Tex 40']);

    $this->actingAs(storeUser())
        ->post(route('store.material.receivings.independent'), [
            'buyer_name' => 'Buyer A',
            'style_name' => 'STYLE-1',
            'material_name' => 'Unmatched Cord',
            'receive_date' => '2026-07-20',
            'source_type' => 'booking',
            'qty' => 10,
            'invoice_qty' => 12,
            'unit_price' => 2.5,
            // Ignored: the server always derives this from physical qty x price.
            'invoice_value' => 999999,
        ])->assertSessionHas('success');

    // 10 (physical) x 2.50 — never 12 (invoiced) x 2.50.
    expect((float) MaterialReceiving::sole()->invoice_value)->toBe(25.0);
});

it('links an independent receiving to a PO line and lets it reach the ledger', function () {
    ['po' => $po, 'rows' => $rows] = poWithLines('PO-9004', ['Thread Tex 40', 'Zipper']);

    $this->actingAs(storeUser())
        ->post(route('store.material.receivings.independent'), [
            'buyer_name' => 'Buyer A',
            'style_name' => 'STYLE-1',
            'material_name' => 'Unmatched Cord',
            'receive_date' => '2026-07-20',
            'source_type' => 'booking',
            'qty' => 40,
        ])->assertSessionHas('success');

    $receiving = MaterialReceiving::sole();
    $grnBefore = $receiving->grn_no;

    $this->actingAs(receivingCorrectorUser())
        ->post(route('store.material.receivings.link', $receiving), [
            'booking_po_id' => $po->id,
            'excel_row_id' => $rows[1]->id,
        ])
        ->assertSessionHas('success');

    $receiving->refresh();

    expect($receiving->match_status)->toBe(MaterialReceiving::MATCH_LINKED)
        ->and($receiving->booking_po_id)->toBe($po->id)
        ->and($receiving->excel_row_id)->toBe($rows[1]->id)
        ->and($receiving->po_no)->toBe('PO-9004')
        ->and($receiving->matched_by)->not->toBeNull()
        // The delivery itself did not change — only what it is known to be for.
        ->and($receiving->grn_no)->toBe($grnBefore)
        ->and((float) $receiving->qty)->toBe(40.0);

    // Now that it has a BOM row, the ledger picks it up.
    $ledger = MaterialStockLedger::where('excel_row_id', $rows[1]->id)->sole();
    expect((float) $ledger->total_receive_qty)->toBe(40.0)
        ->and((float) $ledger->running_closing_qty)->toBe(40.0);
});

it('refuses to link a material line that belongs to another PO', function () {
    ['po' => $poA] = poWithLines('PO-9005', ['Thread Tex 40']);
    ['rows' => $otherRows] = poWithLines('PO-9006', ['Zipper']);

    $this->actingAs(storeUser())
        ->post(route('store.material.receivings.independent'), [
            'buyer_name' => 'Buyer A',
            'style_name' => 'STYLE-1',
            'material_name' => 'Unmatched Cord',
            'receive_date' => '2026-07-20',
            'source_type' => 'booking',
            'qty' => 5,
        ])->assertSessionHas('success');

    $receiving = MaterialReceiving::sole();

    $this->actingAs(receivingCorrectorUser())
        ->post(route('store.material.receivings.link', $receiving), [
            'booking_po_id' => $poA->id,
            'excel_row_id' => $otherRows[0]->id,
        ])
        ->assertSessionHasErrors('excel_row_id');

    expect($receiving->refresh()->match_status)->toBe(MaterialReceiving::MATCH_INDEPENDENT);
});

it('will not re-link a receiving that is already attached to a PO', function () {
    ['po' => $po, 'rows' => $rows] = poWithLines('PO-9007', ['Thread Tex 40']);

    $this->actingAs(storeUser())
        ->post(route('store.material.receivings.store'), [
            'booking_po_id' => $po->id,
            'rows' => [
                ['excel_row_id' => $rows[0]->id, 'receive_date' => '2026-07-01', 'source_type' => 'booking', 'qty' => 10],
            ],
        ])->assertSessionHas('success');

    $this->actingAs(receivingCorrectorUser())
        ->post(route('store.material.receivings.link', MaterialReceiving::sole()), [
            'booking_po_id' => $po->id,
            'excel_row_id' => $rows[0]->id,
        ])
        ->assertSessionHas('warning');
});

// --- BOM-backed suggestions for the Material fields -------------------------
// These only feed the dropdowns. Nothing about what gets saved depends on them,
// so the guarantee worth locking is that a style returns its own BOM lines and
// an unknown style returns nothing rather than everything.
it('returns the BOM material lines belonging to a style', function () {
    poWithLines('PO-9101', ['Thread Tex 40', 'Zipper']);

    $lines = $this->actingAs(storeUser())
        ->getJson(route('store.material.receivings.style-bom', ['style_name' => 'STYLE-1']))
        ->assertOk()
        ->json('lines');

    // poWithLines puts the first two descriptions under STYLE-1.
    expect(collect($lines)->pluck('material_description')->sort()->values()->all())
        ->toEqual(['Thread Tex 40', 'Zipper']);
});

it('does not leak material lines from other styles', function () {
    // Three lines: the first two are STYLE-1, the third is STYLE-2.
    poWithLines('PO-9102', ['Thread Tex 40', 'Zipper', 'Button']);

    $lines = $this->actingAs(storeUser())
        ->getJson(route('store.material.receivings.style-bom', ['style_name' => 'STYLE-2']))
        ->assertOk()
        ->json('lines');

    expect(collect($lines)->pluck('material_description')->all())->toEqual(['Button']);
});

it('returns nothing for a style with no BOM lines, so the fields stay free text', function () {
    poWithLines('PO-9103', ['Thread Tex 40']);

    $lines = $this->actingAs(storeUser())
        ->getJson(route('store.material.receivings.style-bom', ['style_name' => 'STYLE-DOES-NOT-EXIST']))
        ->assertOk()
        ->json('lines');

    expect($lines)->toBe([]);
});

it('requires a style name', function () {
    $this->actingAs(storeUser())
        ->getJson(route('store.material.receivings.style-bom'))
        ->assertStatus(422);
});

it('lists existing buyer/style pairs for the independent style picker', function () {
    poWithLines('PO-9008', ['Thread Tex 40']);

    $results = $this->actingAs(storeUser())
        ->getJson(route('store.material.receivings.style-search'))
        ->assertOk()
        ->json('results');

    expect(collect($results)->pluck('style_name'))->toContain('STYLE-1')
        ->and(collect($results)->pluck('buyer_name'))->toContain('Buyer A');
});
