<?php

use App\Exports\IssueTemplateExport;
use App\Exports\ItemMasterTemplateExport;
use App\Imports\ItemMasterImport;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * The item-master bulk upload after Brand, Size and Specification became one
 * "Brand/Specification" column.
 *
 * The risk this covers is the older template still sitting on people's desks.
 * Its twelve columns read as ten by position would put Uom under Category and
 * a date under Opening Stock — quietly, with no error — so the importer reads
 * headings instead. These tests are mostly about that file.
 *
 * In-memory sqlite. No real record touched.
 */

/** The current template's heading row, then the given data rows. */
function itemSheet(array $rows): array
{
    return array_merge([ItemMasterImport::COLUMNS], $rows);
}

/** The columns as they stood before the merge. */
function legacyItemColumns(): array
{
    return [
        'Item Name*', 'Brand', 'Size', 'Specification', 'Uom*', 'Category*',
        'Opening Stock', 'Counted On', 'Safety Stock', 'Re-order Level',
        'Lead Time', 'Remarks',
    ];
}

it('offers one merged column in place of the three', function () {
    expect(ItemMasterImport::COLUMNS)->toBe([
        'Item Name*',
        'Brand/Specification',
        'Uom*',
        'Category*',
        'Opening Stock',
        'Counted On',
        'Safety Stock',
        'Re-order Level',
        'Lead Time',
        'Unit Price',
        'Remarks',
    ]);

    // The downloadable template writes exactly those, with a full sample row.
    expect((new ItemMasterTemplateExport)->headings())->toBe(ItemMasterImport::COLUMNS)
        ->and(ItemMasterImport::SAMPLE_ROW)->toHaveCount(count(ItemMasterImport::COLUMNS));
});

it('reads a file written to the current template', function () {
    $result = ItemMasterImport::parse(itemSheet([
        ['Sewing Needle', 'Organ DPX17-14', 'Pkt', 'Needle', 25, '2026-08-01', '', '', 7, 12.5, 'Note'],
    ]));

    expect($result['errors'])->toBeEmpty()
        ->and($result['items'])->toHaveCount(1);

    $item = $result['items'][0];

    expect($item['name'])->toBe('Sewing Needle')
        ->and($item['brand'])->toBe('Organ DPX17-14')
        ->and($item['uom'])->toBe('Pkt')
        ->and($item['category'])->toBe('Needle')
        ->and($item['opening_qty'])->toBe(25.0)
        ->and($item['opening_as_on'])->toBe('2026-08-01')
        ->and($item['lead_time_days'])->toBe(7)
        ->and($item['unit_price'])->toBe(12.5)
        ->and($item['remarks'])->toBe('Note');
});

/**
 * Unit Price on the item master. Optional like every other number on this
 * import, and kept as a real number — the template writes a numeric example and
 * formats the column, so what comes back is not text that no arithmetic
 * understands.
 */
it('keeps a blank Unit Price blank rather than storing zero', function () {
    $result = ItemMasterImport::parse(itemSheet([
        ['Sewing Needle', 'Organ', 'Pkt', 'Needle', 25, '2026-08-01', '', '', 7, '', 'Note'],
    ]));

    // Null, not 0. An item whose price is not settled is ordinary; a stored 0
    // would read as "this costs nothing".
    expect($result['errors'])->toBeEmpty()
        ->and($result['items'][0]['unit_price'])->toBeNull();
});

it('refuses a Unit Price that is not a number, or is negative', function () {
    $bad = ItemMasterImport::parse(itemSheet([
        ['Sewing Needle', 'Organ', 'Pkt', 'Needle', 25, '2026-08-01', '', '', 7, 'about ten', 'Note'],
    ]));

    $negative = ItemMasterImport::parse(itemSheet([
        ['Sewing Thread', 'Organ', 'Cone', 'Needle', 25, '2026-08-01', '', '', 7, -5, 'Note'],
    ]));

    expect(implode(' ', $bad['errors']))->toContain('Unit Price must be a number')
        ->and(implode(' ', $negative['errors']))->toContain('Unit Price must be a number')
        ->and($bad['items'])->toBeEmpty()
        ->and($negative['items'])->toBeEmpty();
});

it('accepts the price header spellings a hand-typed file uses', function () {
    $headings = ItemMasterImport::COLUMNS;
    $headings[array_search('Unit Price', $headings, true)] = 'Rate';

    $result = ItemMasterImport::parse([
        $headings,
        ['Sewing Needle', 'Organ', 'Pkt', 'Needle', 25, '2026-08-01', '', '', 7, 9.75, 'Note'],
    ]);

    expect($result['errors'])->toBeEmpty()
        ->and($result['items'][0]['unit_price'])->toBe(9.75);
});

it('writes the template Unit Price as a formatted number, not text', function () {
    $export = new ItemMasterTemplateExport;

    $at = (int) array_search('Unit Price', ItemMasterImport::COLUMNS, true);

    // The example is a real number, so Excel does not treat the column as text
    // and turn what the user types under it into text as well.
    expect(ItemMasterImport::SAMPLE_ROW[$at])->toBeNumeric()
        ->and(ItemMasterImport::SAMPLE_ROW[$at])->not->toBeString();

    // And the column carries a number format, over a range that covers the
    // empty rows the user is going to fill in.
    expect($export->columnFormats()['J2:J1000'])->toBe('0.00');
});

/**
 * Counted On had the same text-date fault the issue and receiving templates
 * were fixed for: the example was written as the string '2026-08-01', so Excel
 * treated the column as text and every date typed under it came back as text.
 */
it('writes the template Counted On as a real Excel date value, formatted', function () {
    $export = new ItemMasterTemplateExport;

    $at = (int) array_search('Counted On', ItemMasterImport::COLUMNS, true);

    [$row] = $export->array();

    $serial = $row[$at];

    expect($serial)->toBeNumeric()
        ->and(Date::excelToDateTimeObject((float) $serial)->format('Y-m-d'))
        ->toBe(ItemMasterImport::SAMPLE_ROW[$at]);

    // ...and the column carries the same date format the other two use, or the
    // serial shows to the user as 46235.
    expect($export->columnFormats()['F2:F1000'])->toBe(IssueTemplateExport::DATE_FORMAT);
});

it('reads the template date serial back as the same day on re-upload', function () {
    // The round trip the fix exists for: what the template writes, the importer
    // must read as the day it means.
    $row = (new ItemMasterTemplateExport)->array()[0];
    $row[0] = 'Sewing Needle';

    $result = ItemMasterImport::parse([ItemMasterImport::COLUMNS, $row]);

    $at = (int) array_search('Counted On', ItemMasterImport::COLUMNS, true);

    expect($result['errors'])->toBeEmpty()
        ->and($result['items'])->toHaveCount(1)
        ->and($result['items'][0]['opening_as_on'])->toBe(ItemMasterImport::SAMPLE_ROW[$at]);
});

it('still reads a file that predates the Unit Price column', function () {
    // Ten columns, no price. Headings are matched by name, so the older file
    // simply has one the importer does not find, and every other value still
    // lands in the right field.
    $headings = [
        'Item Name*', 'Brand/Specification', 'Uom*', 'Category*', 'Opening Stock',
        'Counted On', 'Safety Stock', 'Re-order Level', 'Lead Time', 'Remarks',
    ];

    $result = ItemMasterImport::parse([
        $headings,
        ['Sewing Needle', 'Organ', 'Pkt', 'Needle', 25, '2026-08-01', '', '', 7, 'Note'],
    ]);

    expect($result['errors'])->toBeEmpty()
        ->and($result['items'][0]['remarks'])->toBe('Note')
        ->and($result['items'][0]['lead_time_days'])->toBe(7)
        ->and($result['items'][0]['unit_price'])->toBeNull();
});

it('reads a file written to the old three-column template', function () {
    // Uom and Category sit two columns further right here. Read by position
    // they would land in Opening Stock and Counted On.
    $result = ItemMasterImport::parse([
        legacyItemColumns(),
        ['Sewing Needle', 'Organ', '14', 'DPX17 FFG, chrome', 'Pkt', 'Needle', 25, '2026-08-01', '', '', 7, 'Note'],
    ]);

    expect($result['errors'])->toBeEmpty()
        ->and($result['items'])->toHaveCount(1);

    $item = $result['items'][0];

    expect($item['uom'])->toBe('Pkt')
        ->and($item['category'])->toBe('Needle')
        ->and($item['opening_qty'])->toBe(25.0)
        ->and($item['opening_as_on'])->toBe('2026-08-01')
        ->and($item['lead_time_days'])->toBe(7)
        // Brand wins; the old Size column is not read at all.
        ->and($item['brand'])->toBe('Organ');
});

it('falls back to the old Specification column when Brand is blank', function () {
    $result = ItemMasterImport::parse([
        legacyItemColumns(),
        ['Sewing Needle', '', '14', 'DPX17 FFG, chrome', 'Pkt', 'Needle', 0, '2026-08-01', '', '', '', ''],
    ]);

    expect($result['errors'])->toBeEmpty()
        ->and($result['items'][0]['brand'])->toBe('DPX17 FFG, chrome');
});

it('still reads a file with no heading row at all, by position', function () {
    $result = ItemMasterImport::parse([
        ['Sewing Needle', 'Organ DPX17-14', 'Pkt', 'Needle', 5, '2026-08-01', '', '', '', ''],
    ]);

    expect($result['errors'])->toBeEmpty()
        ->and($result['items'][0]['brand'])->toBe('Organ DPX17-14')
        ->and($result['items'][0]['uom'])->toBe('Pkt');
});

it('skips the template example row and still requires the mandatory fields', function () {
    $result = ItemMasterImport::parse(itemSheet([
        ItemMasterImport::SAMPLE_ROW,
        ['Missing Its Uom', 'Organ', '', 'Needle', '', '', '', '', '', ''],
    ]));

    expect($result['items'])->toBeEmpty()
        ->and(implode(' ', $result['skipped']))->toContain('template example row')
        ->and(implode(' ', $result['errors']))->toContain('Uom');
});
