<?php

use App\Http\Controllers\SupplyChain\PaymentRequestController;
use App\Models\BookingPo;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use App\Services\BookingPoSourceService;

/**
 * How the PRA Preview turns a selected PO into approval LINES.
 *
 * WHY THIS FILE EXISTS SEPARATELY FROM PaymentRequestApprovalRowsTest. That file
 * pins the merge key, starting from PaymentRequestItems that are already one per
 * BOM line. It passed while the Preview screen was still visibly wrong, because
 * the defect was one step upstream: Preview builds its items from
 * paymentSnapshot(), which folds every worksheet row of a PO into ONE record —
 * joining the text columns and summing PI Amount. A single row cannot be split
 * by any merge key, so the screen kept showing one line reading "Fabric
 * Interlining, Fabric Scrim, Fabric Scrim, ..." against one fused total.
 *
 * These tests therefore start from real worksheet rows and assert what Preview
 * actually receives. Preview and Create share snapshotsForSelectedBookingPos(),
 * so this covers both.
 *
 * THE INVARIANT: splitting only redistributes figures that already existed, so
 * the lines must sum back to the aggregate PI Amount exactly.
 */

/** Worksheet columns this fixture fills, by the header_key the service reads. */
const PRA_HEADERS = [
    'material_po_number',
    'style_name',
    'material_type',
    'material_description',
    'material_color',
    'materials_ordered',
    'pi_rate',
    'pi_amount',
    'material_pi_number',
    'pi_status',
    'payment_status',
];

/**
 * One PO spanning several worksheet rows: the primary row (which owns the
 * BookingPo) plus its siblings, all carrying the same PO number. This is the
 * shape that produced the cramped screen.
 */
function bookingPoSpanningRows(array $rows): BookingPo
{
    $user = User::factory()->create();

    $file = ExcelFile::create([
        'file_name' => 'booking.xlsx',
        'original_file_name' => 'booking.xlsx',
        'file_path' => 'uploads/booking.xlsx',
        'uploaded_by' => $user->id,
    ]);

    $headers = [];
    foreach (PRA_HEADERS as $index => $key) {
        $headers[$key] = ExcelHeader::create([
            'excel_file_id' => $file->id,
            'header_name' => $key,
            'header_key' => $key,
            'owner_role_id' => 1,
            'column_index' => $index,
        ]);
    }

    $excelRows = [];
    foreach (array_values($rows) as $number => $values) {
        $row = ExcelRow::create([
            'excel_file_id' => $file->id,
            'row_number' => $number + 1,
        ]);

        foreach ($values as $key => $value) {
            ExcelCell::create([
                'row_id' => $row->id,
                'header_id' => $headers[$key]->id,
                'value' => (string) $value,
            ]);
        }

        $excelRows[] = $row;
    }

    // Only the first row owns a BookingPo; the rest are siblings found by PO no.
    return BookingPo::create([
        'excel_file_id' => $file->id,
        'excel_row_id' => $excelRows[0]->id,
        'po_no' => $rows[0]['material_po_number'],
        'generated_at' => now(),
    ]);
}

/** The snapshots Preview and Create both feed into the approval-row builder. */
function previewSnapshots(BookingPo $bookingPo): Illuminate\Support\Collection
{
    $controller = app(PaymentRequestController::class);

    $method = new ReflectionMethod($controller, 'snapshotsForSelectedBookingPos');
    $method->setAccessible(true);

    return $method->invoke($controller, collect([$bookingPo->id]));
}

/** The approval table Preview renders, straight from those snapshots. */
function previewApprovalRows(BookingPo $bookingPo): Illuminate\Support\Collection
{
    $controller = app(PaymentRequestController::class);

    $method = new ReflectionMethod($controller, 'approvalRowsFromSnapshots');
    $method->setAccessible(true);

    return $method->invoke($controller, previewSnapshots($bookingPo));
}

/** Three BOM lines of one PO — two items, two styles, three amounts. */
function threeLinePo(): BookingPo
{
    $line = fn (array $overrides) => array_merge([
        'material_po_number' => 'HB26FA0007',
        'material_pi_number' => 'TEST12458',
        'material_color' => '001 Black',
        'pi_status' => 'PI Received',
        'payment_status' => 'Pmt Pending',
        'materials_ordered' => '100',
        'pi_rate' => '1',
    ], $overrides);

    return bookingPoSpanningRows([
        $line(['style_name' => '4800093966', 'material_type' => 'Fabric Interlining', 'material_description' => 'Non-fuse HT-1030NFC', 'pi_amount' => '817.86']),
        $line(['style_name' => '4800093966', 'material_type' => 'Fabric Scrim', 'material_description' => 'Scrim KB/SCRIM-15', 'pi_amount' => '2625.96']),
        $line(['style_name' => '4800093964', 'material_type' => 'Fabric Interlining', 'material_description' => 'Non-fuse HT-1030NFW', 'pi_amount' => '198.75']),
    ]);
}

it('gives the preview one snapshot per worksheet row, not one per PO', function () {
    $snapshots = previewSnapshots(threeLinePo());

    // The old behaviour was a single snapshot for the whole PO.
    expect($snapshots)->toHaveCount(3);

    // Each line carries its OWN item and style, not a joined list of all three.
    expect($snapshots->pluck('material_type')->sort()->values()->all())
        ->toBe(['Fabric Interlining', 'Fabric Interlining', 'Fabric Scrim']);

    foreach ($snapshots as $snapshot) {
        expect($snapshot['material_type'])->not->toContain(', ')
            ->and($snapshot['style_name'])->not->toContain(', ')
            ->and($snapshot['pi_number'])->not->toContain(', ');
    }
});

it('renders the preview approval table as one line per item, with its own amount', function () {
    $rows = previewApprovalRows(threeLinePo());

    // This is the screen from the bug report: three lines, not one.
    expect($rows)->toHaveCount(3);

    expect($rows->pluck('pi_amount')->sort()->values()->all())
        ->toBe([198.75, 817.86, 2625.96]);

    // No cell may hold a comma-joined list of the identity columns, and the PO
    // and PI repeat on every line instead of being fused into one.
    foreach ($rows as $row) {
        expect($row['material_type'])->not->toContain(', ')
            ->and($row['style'])->not->toContain(', ')
            ->and($row['material_po_number'])->toBe('HB26FA0007')
            ->and($row['material_pi_number'])->toBe('TEST12458');
    }
});

it('keeps the grand total identical to the old single-snapshot amount', function () {
    $bookingPo = threeLinePo();
    $controller = app(PaymentRequestController::class);

    $summarise = new ReflectionMethod($controller, 'summaryFromApprovalRows');
    $summarise->setAccessible(true);

    $rows = previewApprovalRows($bookingPo);
    $summary = $summarise->invoke($controller, $rows);

    // paymentSnapshot() still aggregates the whole PO — the pending payment
    // list depends on it. Splitting must not move that figure by a cent.
    $aggregate = (float) app(BookingPoSourceService::class)->paymentSnapshot($bookingPo)['pi_amount'];

    expect($summary['total_pi_amount'])->toBe(817.86 + 2625.96 + 198.75)
        ->and($summary['total_pi_amount'])->toBe($aggregate)
        ->and($rows->sum(fn ($row) => $row['pi_amount']))->toBe($aggregate);
});

it('leaves a single-row PO exactly as it was', function () {
    $bookingPo = bookingPoSpanningRows([[
        'material_po_number' => 'HB26FA0009',
        'material_pi_number' => 'PI-900',
        'style_name' => 'ST-1',
        'material_type' => 'Fabric Scrim',
        'material_color' => 'Navy',
        'materials_ordered' => '50',
        'pi_rate' => '2',
        'pi_amount' => '100',
        'pi_status' => 'PI Received',
        'payment_status' => 'Pmt Pending',
    ]]);

    // No siblings -> the aggregate snapshot is returned untouched.
    $snapshots = previewSnapshots($bookingPo);

    expect($snapshots)->toHaveCount(1)
        ->and($snapshots->first()['pi_amount'])
        ->toBe(app(BookingPoSourceService::class)->paymentSnapshot($bookingPo)['pi_amount']);

    expect(previewApprovalRows($bookingPo))->toHaveCount(1);
});
