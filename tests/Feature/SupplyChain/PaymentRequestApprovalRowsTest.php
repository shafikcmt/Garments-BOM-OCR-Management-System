<?php

use App\Http\Controllers\SupplyChain\PaymentRequestController;
use App\Models\PaymentRequestItem;

/**
 * How a PRA's approval table is split into lines.
 *
 * WHAT THIS PINS. The merge key used to be Style + Color + Size alone, so
 * several BOM items sharing one style collapsed into a single line and
 * mergeApprovalRows() comma-joined everything that differed: one cell reading
 * "Fabric Interlining, Fabric Scrim, ..." beside another reading "PO-11,
 * PO-12", with the per-item amounts fused into one figure. The approver could
 * not see which item cost what.
 *
 * The three renderers — the on-screen preview, the PDF and the xlsx — all
 * iterate the SAME collection this builds, one <tr> per entry, so these assert
 * the shape all three receive. That is also why the fix needed no change in any
 * of them.
 *
 * THE INVARIANT THAT MATTERS MOST is the last test: splitting redistributes the
 * per-item amounts that were already there, so the Grand Total cannot move. An
 * export that changed the total would be a far worse bug than the one being
 * fixed.
 *
 * In-memory sqlite. Nothing is persisted — approvalRowsFromItems() takes a
 * collection, so these are built in memory and no PRA is written.
 */

/** approvalRowsFromItems() is protected; this is the smallest way to ask it. */
function approvalRows(array $items): Illuminate\Support\Collection
{
    $controller = app(PaymentRequestController::class);

    $method = new ReflectionMethod($controller, 'approvalRowsFromItems');
    $method->setAccessible(true);

    return $method->invoke($controller, collect($items));
}

/** One PRA item. `data` doubles as the snapshot the row builder reads from. */
function praItem(array $attributes): PaymentRequestItem
{
    $data = array_merge([
        'style_name' => 'ST-1',
        'material_color' => 'Grey',
        'material_type' => 'Fabric Interlining',
        'po_no' => 'PO-11',
        'pi_number' => 'PI-77',
        'pcd_required' => '2026-08-01',
        'contract_shipment' => '2026-09-15',
        'committed_ex_mill' => '2026-08-20',
    ], $attributes);

    return new PaymentRequestItem([
        'po_no' => $data['po_no'],
        'pi_number' => $data['pi_number'],
        'pi_amount' => $data['pi_amount'] ?? 0,
        'supplier_name' => $data['supplier_name'] ?? 'Ruby Textiles',
        'style_name' => $data['style_name'],
        'material_color' => $data['material_color'],
        'payment_term' => $data['payment_term'] ?? 'TT 30 Days',
        'data' => $data,
    ]);
}

it('gives each item on one style its own line', function () {
    $rows = approvalRows([
        praItem(['material_type' => 'Fabric Interlining', 'po_no' => 'PO-11', 'pi_number' => 'PI-77', 'pi_amount' => 7500]),
        praItem(['material_type' => 'Fabric Scrim', 'po_no' => 'PO-12', 'pi_number' => 'PI-78', 'pi_amount' => 5000]),
    ]);

    // Two lines, not one line holding "Fabric Interlining, Fabric Scrim".
    expect($rows)->toHaveCount(2);

    expect($rows->pluck('material_type')->all())->toBe(['Fabric Interlining', 'Fabric Scrim']);

    // No cell may carry a comma-joined list of the identity columns any more.
    foreach ($rows as $row) {
        expect($row['material_type'])->not->toContain(', ')
            ->and($row['style'])->not->toContain(', ')
            ->and($row['material_po_number'])->not->toContain(', ')
            ->and($row['material_pi_number'])->not->toContain(', ');
    }
});

it('repeats the shared columns on every split line', function () {
    $rows = approvalRows([
        praItem(['material_type' => 'Fabric Interlining', 'pi_amount' => 7500]),
        praItem(['material_type' => 'Fabric Scrim', 'pi_amount' => 5000]),
    ]);

    expect($rows)->toHaveCount(2);

    // PCD, Vendor, Pay Term, Ex Mill and C. Shipment describe the whole group,
    // so each line carries its own copy — the "repeat", not "merge", decision.
    foreach ($rows as $row) {
        expect($row['vendor_name'])->toBe('Ruby Textiles')
            ->and($row['payment_term'])->toBe('TT 30 Days')
            ->and($row['pcd_required'])->toBe('01-Aug-26')
            ->and($row['committed_ex_mill'])->toBe('20-Aug-26')
            ->and($row['contract_shipment'])->toBe('15-Sep-26')
            ->and($row['style'])->toBe('ST-1');
    }
});

it('carries each item its own amount instead of one fused figure', function () {
    $rows = approvalRows([
        praItem(['material_type' => 'Fabric Interlining', 'pi_amount' => 7500]),
        praItem(['material_type' => 'Fabric Scrim', 'pi_amount' => 5000]),
    ]);

    expect($rows->pluck('pi_amount')->all())->toBe([7500.0, 5000.0]);

    // The old behaviour was one line of 12500. Neither line may show the total.
    expect($rows->pluck('pi_amount')->all())->not->toContain(12500.0);
});

it('still merges rows that are identical across every identity column', function () {
    // Same style, colour, item, PO and PI — two BOM lines of one delivery.
    // These are not different work and must not become two identical lines.
    $rows = approvalRows([
        praItem(['pi_amount' => 300]),
        praItem(['pi_amount' => 200]),
    ]);

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['pi_amount'])->toBe(500.0)
        ->and($rows->first()['source_count'])->toBe(2);
});

it('keeps different styles on their own lines, as before', function () {
    $rows = approvalRows([
        praItem(['style_name' => 'ST-1', 'pi_amount' => 100]),
        praItem(['style_name' => 'ST-2', 'pi_amount' => 200]),
    ]);

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('style')->sort()->values()->all())->toBe(['ST-1', 'ST-2']);
});

/**
 * THE INVARIANT. The Grand Total is summaryFromApprovalRows() summing pi_amount
 * over these same rows, so splitting must not move it by a cent: the per-item
 * figures already existed and are merely distributed rather than added up.
 */
it('leaves the Grand Total unchanged however the lines are split', function () {
    $controller = app(PaymentRequestController::class);
    $summarise = new ReflectionMethod($controller, 'summaryFromApprovalRows');
    $summarise->setAccessible(true);

    $items = [
        praItem(['material_type' => 'Fabric Interlining', 'po_no' => 'PO-11', 'pi_number' => 'PI-77', 'pi_amount' => 7500]),
        praItem(['material_type' => 'Fabric Scrim', 'po_no' => 'PO-12', 'pi_number' => 'PI-78', 'pi_amount' => 5000]),
        praItem(['material_type' => 'Woven Label', 'po_no' => 'PO-13', 'pi_number' => 'PI-79', 'pi_amount' => 1250.55]),
    ];

    $rows = approvalRows($items);
    $summary = $summarise->invoke($controller, $rows);

    expect($rows)->toHaveCount(3)
        ->and($summary['total_pi_amount'])->toBe(13750.55);

    // The total equals the sum of the printed lines — what the reader adds up
    // by hand has to agree with the figure in the Grand Total cell.
    expect($rows->sum(fn ($row) => $row['pi_amount']))->toBe($summary['total_pi_amount']);

    // And it equals the raw per-item figures, so nothing was doubled by the
    // finer grouping.
    expect($summary['total_pi_amount'])->toBe(7500.0 + 5000.0 + 1250.55);

    // Three distinct POs across three lines, counted once each.
    expect($summary['total_po_count'])->toBe(3);
});
