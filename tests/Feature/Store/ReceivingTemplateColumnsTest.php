<?php

use App\Exports\ReceivingTemplateExport;
use App\Imports\ReceivingImport;
use App\Models\StockItem;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * Brand/Specification on the receiving bulk upload.
 *
 * It is a reference column, not data the file writes: the item already exists
 * by the time a row is read — the importer refuses unknown item names rather
 * than inventing an item — so the master is the authority, exactly as it
 * already is for Uom and Category. What these assert is therefore mostly what
 * does NOT happen: a mismatch does not block the delivery, and it does not
 * change the item.
 *
 * Brand, Size and Specification used to be three separate columns. A file
 * written to that older template is still accepted, so the last tests here are
 * about what happens to one.
 *
 * In-memory sqlite. No real record touched.
 */
function receivingItem(array $attributes = []): StockItem
{
    return StockItem::create(array_merge([
        'name' => 'Sewing Needle',
        'uom' => 'Pkt',
        'category' => 'Needle',
        'brand' => 'Groz-Beckert DBx1 90/14',
        'opening_qty' => 0,
    ], $attributes));
}

/** A sheet: heading row from the template, then the given data rows. */
function receivingSheet(array $rows): array
{
    return array_merge([ReceivingImport::COLUMNS], $rows);
}

/** One row keyed by heading, so a column insert cannot silently shift a test. */
function receivingRow(array $values): array
{
    $row = array_fill(0, count(ReceivingImport::COLUMNS), null);

    foreach ($values as $heading => $value) {
        $i = array_search($heading, ReceivingImport::COLUMNS, true);
        expect($i)->not->toBeFalse("unknown column: {$heading}");
        $row[$i] = $value;
    }

    return $row;
}

it('carries one merged reference column between Item Name and Uom', function () {
    $columns = ReceivingImport::COLUMNS;

    expect($columns)->toBe([
        'Challan Date*',
        'RCV Date',
        'Month',
        'GRN No',
        'Challan No/Invoice No',
        'Supplier Name',
        'Item Name*',
        'Brand/Specification',
        'Uom',
        'Category',
        'Purchased Qty*',
        'Unit Price',
        'Total Value',
        'Remarks',
    ]);
});

it('gives the downloadable template the same headings and a full sample row', function () {
    $export = new ReceivingTemplateExport;

    expect($export->headings())->toBe(ReceivingImport::COLUMNS);

    // Every column has a sample cell — a short row would misalign the example.
    expect(ReceivingImport::SAMPLE_ROW)->toHaveCount(count(ReceivingImport::COLUMNS));

    $at = fn (string $h) => array_search($h, ReceivingImport::COLUMNS, true);

    expect(ReceivingImport::SAMPLE_ROW[$at('Brand/Specification')])
        ->toBe('Groz-Beckert DBx1 90/14, ball point');
});

it('keeps the second example row aligned with the columns', function () {
    // The regression this guards: the indexes were hardcoded, so changing the
    // columns put the quantity under the wrong heading.
    [$first, $second] = (new ReceivingTemplateExport)->array();

    $at = fn (string $h) => array_search($h, ReceivingImport::COLUMNS, true);

    expect($second[$at('Item Name*')])->toBe('EXAMPLE — a second item on the SAME challan')
        ->and($second[$at('Purchased Qty*')])->toBe(5)
        ->and($second[$at('Unit Price')])->toBe(20)
        ->and($second[$at('Total Value')])->toBe(100)
        // ...and the reference column still carries its own sample, not a number.
        ->and($second[$at('Brand/Specification')])->toBe('Groz-Beckert DBx1 90/14, ball point');

    expect($first)->toHaveCount(count(ReceivingImport::COLUMNS));
});

/**
 * Both date columns have to be real Excel dates, not the look of one. Written
 * as text they come back from the user as text, and the date is lost on
 * re-upload — which is what this pair of tests exists to stop happening again.
 */
it('writes both date columns as real Excel date values, formatted, with Month derived', function () {
    $export = new ReceivingTemplateExport;

    $at = fn (string $h) => array_search($h, ReceivingImport::COLUMNS, true);

    [$first, $second] = $export->array();

    foreach (['Challan Date*', 'RCV Date'] as $heading) {
        $serial = $first[$at($heading)];

        expect($serial)->toBeNumeric()
            ->and(Date::excelToDateTimeObject((float) $serial)->format('Y-m-d'))
            ->toBe(ReceivingImport::SAMPLE_ROW[$at($heading)])
            // Both example rows are ONE challan, so both dates must match.
            ->and($second[$at($heading)])->toBe($serial);
    }

    // Derived from the Challan Date, so it can never disagree with it.
    expect($first[$at('Month')])->toBe('=TEXT(A2,"MMM-YY")')
        ->and($second[$at('Month')])->toBe('=TEXT(A3,"MMM-YY")');

    // And the columns carry a date format, or the serials show as 46235.
    expect($export->columnFormats())->toBe([
        'A2:A1000' => ReceivingTemplateExport::DATE_FORMAT,
        'B2:B1000' => ReceivingTemplateExport::DATE_FORMAT,
    ]);
});

it('reads the template date serials back as the same days on re-upload', function () {
    receivingItem();

    $at = fn (string $h) => array_search($h, ReceivingImport::COLUMNS, true);

    // The row exactly as the template writes it — serial dates and all — with
    // only the example marker replaced by a real item.
    $row = (new ReceivingTemplateExport)->array()[0];
    $row[$at('Item Name*')] = 'Sewing Needle';

    $result = ReceivingImport::parse([ReceivingImport::COLUMNS, $row]);

    expect($result['errors'])->toBe([])
        ->and($result['challans'])->toHaveCount(1)
        ->and($result['challans'][0]['purchase_date'])
        ->toBe(ReceivingImport::SAMPLE_ROW[$at('Challan Date*')])
        ->and($result['challans'][0]['rcv_date'])
        ->toBe(ReceivingImport::SAMPLE_ROW[$at('RCV Date')]);
});

it('imports a row with the column filled and matching, without a note', function () {
    receivingItem();

    $result = ReceivingImport::parse(receivingSheet([
        receivingRow([
            'Challan Date*' => '2026-08-01',
            'Challan No/Invoice No' => 'CH-100',
            'Item Name*' => 'Sewing Needle',
            'Brand/Specification' => 'Groz-Beckert DBx1 90/14',
            'Purchased Qty*' => 10,
        ]),
    ]));

    expect($result['errors'])->toBeEmpty()
        ->and($result['challans'])->toHaveCount(1)
        ->and($result['notes'])->toBeEmpty();
});

it('imports a row with the column blank, since it is optional', function () {
    receivingItem();

    $result = ReceivingImport::parse(receivingSheet([
        receivingRow([
            'Challan Date*' => '2026-08-01',
            'Challan No/Invoice No' => 'CH-101',
            'Item Name*' => 'Sewing Needle',
            'Purchased Qty*' => 7,
        ]),
    ]));

    expect($result['errors'])->toBeEmpty()
        ->and($result['challans'])->toHaveCount(1)
        ->and($result['notes'])->toBeEmpty();
});

it('notes a mismatch but still imports the delivery', function () {
    $item = receivingItem();

    $result = ReceivingImport::parse(receivingSheet([
        receivingRow([
            'Challan Date*' => '2026-08-01',
            'Challan No/Invoice No' => 'CH-102',
            'Item Name*' => 'Sewing Needle',
            'Brand/Specification' => 'Organ 80/12 sharp point',
            'Purchased Qty*' => 4,
        ]),
    ]));

    // The row is not lost.
    expect($result['errors'])->toBeEmpty()
        ->and($result['challans'])->toHaveCount(1);

    $notes = implode(' | ', $result['notes']);

    expect($notes)->toContain('Brand/Specification in the file is "Organ 80/12 sharp point"')
        ->and($notes)->toContain('The item master was used.');

    // And the master is genuinely untouched.
    expect($item->fresh()->brand)->toBe('Groz-Beckert DBx1 90/14');
});

it('ignores case and surrounding space when comparing', function () {
    receivingItem();

    $result = ReceivingImport::parse(receivingSheet([
        receivingRow([
            'Challan Date*' => '2026-08-01',
            'Challan No/Invoice No' => 'CH-103',
            'Item Name*' => 'Sewing Needle',
            'Brand/Specification' => '  groz-beckert dbx1 90/14  ',
            'Purchased Qty*' => 3,
        ]),
    ]));

    expect($result['notes'])->toBeEmpty()
        ->and($result['challans'])->toHaveCount(1);
});

it('says nothing when the master itself has no value to compare', function () {
    // An item with no brand recorded: the file's value is not a contradiction,
    // so a note would be noise.
    receivingItem(['brand' => null]);

    $result = ReceivingImport::parse(receivingSheet([
        receivingRow([
            'Challan Date*' => '2026-08-01',
            'Challan No/Invoice No' => 'CH-104',
            'Item Name*' => 'Sewing Needle',
            'Brand/Specification' => 'Organ',
            'Purchased Qty*' => 2,
        ]),
    ]));

    expect($result['notes'])->toBeEmpty()
        ->and($result['challans'])->toHaveCount(1);
});

it('still refuses an unknown item, unchanged by this work', function () {
    receivingItem();

    $result = ReceivingImport::parse(receivingSheet([
        receivingRow([
            'Challan Date*' => '2026-08-01',
            'Challan No/Invoice No' => 'CH-105',
            'Item Name*' => 'Not In The Master',
            'Brand/Specification' => 'Organ',
            'Purchased Qty*' => 1,
        ]),
    ]));

    expect($result['challans'])->toBeEmpty();
    expect(implode(' ', $result['errors']))->toContain('is not in the item master');

    // The column must not have become a back door to creating one.
    expect(StockItem::where('name', 'Not In The Master')->exists())->toBeFalse();
});

it('accepts the header spellings a legacy workbook uses', function () {
    receivingItem();

    $headings = ReceivingImport::COLUMNS;
    $headings[array_search('Brand/Specification', $headings, true)] = 'Brand Name';

    $row = receivingRow([
        'Challan Date*' => '2026-08-01',
        'Challan No/Invoice No' => 'CH-106',
        'Item Name*' => 'Sewing Needle',
        'Brand/Specification' => 'Organ',
        'Purchased Qty*' => 6,
    ]);

    $result = ReceivingImport::parse(array_merge([$headings], [$row]));

    expect($result['challans'])->toHaveCount(1);

    expect(implode(' | ', $result['notes']))->toContain('Brand/Specification in the file is "Organ"');
});

it('imports a file written to the old three-column template', function () {
    // Exactly what someone still has on their desk: Brand, Size and
    // Specification as separate columns. It must upload as it always did —
    // Brand is read, Size is ignored, and the delivery is not lost.
    receivingItem();

    $headings = [
        'Challan Date*', 'RCV Date', 'Month', 'GRN No', 'Challan No/Invoice No',
        'Supplier Name', 'Item Name*', 'Brand', 'Size', 'Specification', 'Uom',
        'Category', 'Purchased Qty*', 'Unit Price', 'Total Value', 'Remarks',
    ];

    // No supplier name: an unknown supplier raises a note of its own, which
    // would say nothing about the columns under test.
    $row = [
        '2026-08-01', '2026-08-02', 'Aug-26', '', 'CH-107', '',
        'Sewing Needle', 'Groz-Beckert DBx1 90/14', '90/14', 'Ball point',
        'Pkt', 'Needle', 9, 145, 1305, '',
    ];

    $result = ReceivingImport::parse([$headings, $row]);

    expect($result['errors'])->toBeEmpty()
        ->and($result['challans'])->toHaveCount(1)
        ->and($result['challans'][0]['lines'])->toHaveCount(1)
        // Brand matched the master, so nothing is flagged — and the old Size
        // and Specification cells raise no note of their own.
        ->and($result['notes'])->toBeEmpty();
});

it('falls back to an old file\'s Specification column when it has no Brand', function () {
    receivingItem(['brand' => 'Ball point']);

    $headings = [
        'Challan Date*', 'Challan No/Invoice No', 'Item Name*', 'Specification', 'Purchased Qty*',
    ];

    $result = ReceivingImport::parse([
        $headings,
        ['2026-08-01', 'CH-108', 'Sewing Needle', 'Ball point', 4],
    ]);

    // Read as the Brand/Specification value, so it matches the master and the
    // delivery imports without a note.
    expect($result['errors'])->toBeEmpty()
        ->and($result['challans'])->toHaveCount(1)
        ->and($result['notes'])->toBeEmpty();
});
