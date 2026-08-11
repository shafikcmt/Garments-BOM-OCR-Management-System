<?php

use App\Exports\ReceivingTemplateExport;
use App\Imports\ReceivingImport;
use App\Models\StockItem;

/**
 * Brand, Size and Specification on the receiving bulk upload.
 *
 * They are reference columns, not data the file writes: the item already
 * exists by the time a row is read — the importer refuses unknown item names
 * rather than inventing an item — so the master is the authority, exactly as
 * it already is for Uom and Category. What these assert is therefore mostly
 * what does NOT happen: a mismatch does not block the delivery, and it does
 * not change the item.
 *
 * In-memory sqlite. No real record touched.
 */
function receivingItem(array $attributes = []): StockItem
{
    return StockItem::create(array_merge([
        'name' => 'Sewing Needle',
        'uom' => 'Pkt',
        'category' => 'Needle',
        'brand' => 'Groz-Beckert',
        'size' => 'DBx1 90/14',
        'specification' => 'Ball point, chrome',
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

it('places the three columns between Item Name and Uom', function () {
    $columns = ReceivingImport::COLUMNS;

    expect($columns)->toBe([
        'Challan Date*',
        'RCV Date',
        'Month',
        'GRN No',
        'Challan No/Invoice No',
        'Supplier Name',
        'Item Name*',
        'Brand',
        'Size',
        'Specification',
        'Uom',
        'Category',
        'Purchased Qty*',
        'Unit Price',
        'Total Value',
        'Remarks',
    ]);
});

it('gives the downloadable template the same headings and a full sample row', function () {
    $export = new ReceivingTemplateExport();

    expect($export->headings())->toBe(ReceivingImport::COLUMNS);

    // Every column has a sample cell — a short row would misalign the example.
    expect(ReceivingImport::SAMPLE_ROW)->toHaveCount(count(ReceivingImport::COLUMNS));

    $at = fn (string $h) => array_search($h, ReceivingImport::COLUMNS, true);

    expect(ReceivingImport::SAMPLE_ROW[$at('Brand')])->toBe('Groz-Beckert')
        ->and(ReceivingImport::SAMPLE_ROW[$at('Size')])->toBe('DBx1 90/14')
        ->and(ReceivingImport::SAMPLE_ROW[$at('Specification')])->toBe('Ball point, chrome');
});

it('keeps the second example row aligned after the column insert', function () {
    // The regression this guards: the indexes were hardcoded, so inserting
    // three columns put the quantity under Specification.
    [$first, $second] = (new ReceivingTemplateExport())->array();

    $at = fn (string $h) => array_search($h, ReceivingImport::COLUMNS, true);

    expect($second[$at('Item Name*')])->toBe('EXAMPLE — a second item on the SAME challan')
        ->and($second[$at('Purchased Qty*')])->toBe(5)
        ->and($second[$at('Unit Price')])->toBe(20)
        ->and($second[$at('Total Value')])->toBe(100)
        // ...and the new columns still carry their own sample, not a number.
        ->and($second[$at('Brand')])->toBe('Groz-Beckert')
        ->and($second[$at('Specification')])->toBe('Ball point, chrome');

    expect($first)->toBe(ReceivingImport::SAMPLE_ROW);
});

it('imports a row with all three filled and matching, without a note', function () {
    receivingItem();

    $result = ReceivingImport::parse(receivingSheet([
        receivingRow([
            'Challan Date*' => '2026-08-01',
            'Challan No/Invoice No' => 'CH-100',
            'Item Name*' => 'Sewing Needle',
            'Brand' => 'Groz-Beckert',
            'Size' => 'DBx1 90/14',
            'Specification' => 'Ball point, chrome',
            'Purchased Qty*' => 10,
        ]),
    ]));

    expect($result['errors'])->toBeEmpty()
        ->and($result['challans'])->toHaveCount(1)
        ->and($result['notes'])->toBeEmpty();
});

it('imports a row with all three blank, since they are optional', function () {
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
            'Brand' => 'Organ',
            'Size' => '80/12',
            'Specification' => 'Sharp point',
            'Purchased Qty*' => 4,
        ]),
    ]));

    // The row is not lost.
    expect($result['errors'])->toBeEmpty()
        ->and($result['challans'])->toHaveCount(1);

    $notes = implode(' | ', $result['notes']);

    expect($notes)->toContain('Brand in the file is "Organ"')
        ->and($notes)->toContain('Size in the file is "80/12"')
        ->and($notes)->toContain('Specification in the file is "Sharp point"')
        ->and($notes)->toContain('The item master was used.');

    // And the master is genuinely untouched.
    expect($item->fresh()->brand)->toBe('Groz-Beckert')
        ->and($item->fresh()->size)->toBe('DBx1 90/14')
        ->and($item->fresh()->specification)->toBe('Ball point, chrome');
});

it('ignores case and surrounding space when comparing', function () {
    receivingItem();

    $result = ReceivingImport::parse(receivingSheet([
        receivingRow([
            'Challan Date*' => '2026-08-01',
            'Challan No/Invoice No' => 'CH-103',
            'Item Name*' => 'Sewing Needle',
            'Brand' => '  groz-beckert  ',
            'Purchased Qty*' => 3,
        ]),
    ]));

    expect($result['notes'])->toBeEmpty()
        ->and($result['challans'])->toHaveCount(1);
});

it('says nothing when the master itself has no value to compare', function () {
    // An item with no brand recorded: the file's value is not a contradiction,
    // so a note would be noise.
    receivingItem(['brand' => null, 'size' => null, 'specification' => null]);

    $result = ReceivingImport::parse(receivingSheet([
        receivingRow([
            'Challan Date*' => '2026-08-01',
            'Challan No/Invoice No' => 'CH-104',
            'Item Name*' => 'Sewing Needle',
            'Brand' => 'Organ',
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
            'Brand' => 'Organ',
            'Purchased Qty*' => 1,
        ]),
    ]));

    expect($result['challans'])->toBeEmpty();
    expect(implode(' ', $result['errors']))->toContain('is not in the item master');

    // The three columns must not have become a back door to creating one.
    expect(StockItem::where('name', 'Not In The Master')->exists())->toBeFalse();
});

it('accepts the header spellings a legacy workbook uses', function () {
    receivingItem();

    $headings = ReceivingImport::COLUMNS;
    $headings[array_search('Brand', $headings, true)] = 'Brand Name';
    $headings[array_search('Specification', $headings, true)] = 'Specs';

    $row = receivingRow([
        'Challan Date*' => '2026-08-01',
        'Challan No/Invoice No' => 'CH-106',
        'Item Name*' => 'Sewing Needle',
        'Brand' => 'Organ',
        'Specification' => 'Sharp point',
        'Purchased Qty*' => 6,
    ]);

    $result = ReceivingImport::parse(array_merge([$headings], [$row]));

    expect($result['challans'])->toHaveCount(1);

    $notes = implode(' | ', $result['notes']);

    expect($notes)->toContain('Brand in the file is "Organ"')
        ->and($notes)->toContain('Specification in the file is "Sharp point"');
});
