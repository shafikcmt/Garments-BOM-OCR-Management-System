<?php

use App\Exports\IssueTemplateExport;
use App\Exports\SkippedIssueRowsExport;
use App\Imports\IssueImport;
use App\Models\IndentPerson;
use App\Models\IndentSection;
use App\Models\IssueApprover;
use App\Models\ItemCategory;
use App\Models\StockIssue;
use App\Models\StockItem;
use App\Models\StockPurchase;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Spatie\Permission\Models\Permission;

/**
 * Bulk consumption upload.
 *
 * Mirrors the receiving import in shape, so most of these assert the same
 * guarantees. The two that are specific to issuing carry the weight:
 *
 *   - Category is STORED, not cross-checked. It is the one column that behaves
 *     differently from its receiving namesake, so it has a test of its own that
 *     contrasts the two.
 *   - Demand accumulates across the file. Several requisitions can draw on one
 *     item, and each looking affordable alone is exactly how a balance goes
 *     negative.
 *
 * In-memory sqlite. No real record touched.
 */
function issueItem(string $name = 'Sewing Needle', string $uom = 'Pkt'): StockItem
{
    return StockItem::create([
        'name' => $name,
        'uom' => $uom,
        'category' => 'Needle',
        'opening_qty' => 0,
    ]);
}

/** Stock has to come from somewhere the report service can see. */
function stockOnHand(StockItem $item, float $qty): StockPurchase
{
    return StockPurchase::create([
        'stock_item_id' => $item->id,
        'purchase_date' => now()->startOfMonth()->toDateString(),
        'rcv_date' => now()->startOfMonth()->toDateString(),
        'qty' => $qty,
        'unit_price' => 1,
    ]);
}

function issueSheet(array $rows): array
{
    return array_merge([IssueImport::COLUMNS], $rows);
}

/** One row keyed by heading, so a column insert cannot silently shift a test. */
function issueRow(array $values): array
{
    $row = array_fill(0, count(IssueImport::COLUMNS), null);

    foreach ($values as $heading => $value) {
        $i = array_search($heading, IssueImport::COLUMNS, true);
        expect($i)->not->toBeFalse("unknown column: {$heading}");
        $row[$i] = $value;
    }

    return $row;
}

it('has the agreed columns in the agreed order', function () {
    expect(IssueImport::COLUMNS)->toBe([
        'Issue Date*',
        'Month',
        'Indent Section',
        'Indent Person',
        'Approved By',
        'Requisition Number',
        'Item Name*',
        'Brand/Specification',
        'Uom',
        'Category',
        'Issued Qty*',
        'Type',
        'Remarks',
    ]);
});

/**
 * Brand/Specification on the issue bulk upload — the same reference column the
 * receiving template already carries, and the same rules: the item master is
 * the authority, a mismatch is a note rather than a refusal, and the column can
 * never create or change an item.
 */
it('notes a brand mismatch but still imports the issue', function () {
    $item = issueItem();
    $item->update(['brand' => 'Groz-Beckert DBx1 90/14']);
    stockOnHand($item, 50);

    $result = IssueImport::parse(issueSheet([
        issueRow([
            'Issue Date*' => now()->startOfMonth()->toDateString(),
            'Item Name*' => 'Sewing Needle',
            'Brand/Specification' => 'Organ 80/12 sharp point',
            'Issued Qty*' => 4,
        ]),
    ]));

    expect($result['errors'])->toBeEmpty();

    $notes = implode(' | ', $result['notes']);

    expect($notes)->toContain('Brand/Specification in the file is "Organ 80/12 sharp point"')
        ->and($notes)->toContain('The item master was used.');

    // The column is reference-only: the master must be untouched.
    expect($item->fresh()->brand)->toBe('Groz-Beckert DBx1 90/14');
});

it('says nothing when the brand matches, is blank, or the master has none', function () {
    $item = issueItem();
    $item->update(['brand' => 'Groz-Beckert DBx1 90/14']);
    stockOnHand($item, 50);

    // Matching, ignoring case and surrounding space.
    $matched = IssueImport::parse(issueSheet([
        issueRow([
            'Issue Date*' => now()->startOfMonth()->toDateString(),
            'Item Name*' => 'Sewing Needle',
            'Brand/Specification' => '  groz-beckert dbx1 90/14  ',
            'Issued Qty*' => 1,
        ]),
    ]));

    // Left blank — the column is optional.
    $blank = IssueImport::parse(issueSheet([
        issueRow([
            'Issue Date*' => now()->startOfMonth()->toDateString(),
            'Item Name*' => 'Sewing Needle',
            'Issued Qty*' => 1,
        ]),
    ]));

    expect($matched['notes'])->toBeEmpty()
        ->and($blank['notes'])->toBeEmpty();

    // Master has no brand recorded: the file's value contradicts nothing, so a
    // note would be noise.
    $item->update(['brand' => null]);

    $noMaster = IssueImport::parse(issueSheet([
        issueRow([
            'Issue Date*' => now()->startOfMonth()->toDateString(),
            'Item Name*' => 'Sewing Needle',
            'Brand/Specification' => 'Organ',
            'Issued Qty*' => 1,
        ]),
    ]));

    expect($noMaster['notes'])->toBeEmpty();
});

it('accepts the brand header spellings a legacy workbook uses', function () {
    $item = issueItem();
    $item->update(['brand' => 'Groz-Beckert DBx1 90/14']);
    stockOnHand($item, 50);

    $headings = IssueImport::COLUMNS;
    $headings[array_search('Brand/Specification', $headings, true)] = 'Brand Name';

    $row = issueRow([
        'Issue Date*' => now()->startOfMonth()->toDateString(),
        'Item Name*' => 'Sewing Needle',
        'Brand/Specification' => 'Organ',
        'Issued Qty*' => 2,
    ]);

    $result = IssueImport::parse(array_merge([$headings], [$row]));

    expect(implode(' | ', $result['notes']))->toContain('Brand/Specification in the file is "Organ"');
});

it('gives the template the same headings and two aligned example rows', function () {
    $export = new IssueTemplateExport;

    expect($export->headings())->toBe(IssueImport::COLUMNS)
        ->and(IssueImport::SAMPLE_ROW)->toHaveCount(count(IssueImport::COLUMNS));

    [$first, $second] = $export->array();

    $at = fn (string $h) => array_search($h, IssueImport::COLUMNS, true);

    expect($first)->toHaveCount(count(IssueImport::COLUMNS))
        ->and($second[$at('Item Name*')])->toBe('EXAMPLE — a second item on the SAME requisition')
        ->and($second[$at('Issued Qty*')])->toBe(2)
        ->and($second[$at('Type')])->toBe('Replace')
        // Header fields are identical on both rows: that is what makes them
        // one requisition.
        ->and($second[$at('Requisition Number')])->toBe($first[$at('Requisition Number')]);
});

/**
 * The template's Issue Date has to be a real Excel date, not the look of one.
 * Written as text it comes back from the user as text, and the date is lost on
 * re-upload — which is what this pair of tests exists to stop happening again.
 */
it('writes Issue Date as a real Excel date value, formatted, with Month derived from it', function () {
    $export = new IssueTemplateExport;

    $at = fn (string $h) => array_search($h, IssueImport::COLUMNS, true);

    [$first, $second] = $export->array();

    $serial = $first[$at('Issue Date*')];

    expect($serial)->toBeNumeric()
        ->and(Date::excelToDateTimeObject((float) $serial)->format('Y-m-d'))
        ->toBe(IssueImport::SAMPLE_ROW[$at('Issue Date*')])
        ->and($second[$at('Issue Date*')])->toBe($serial)
        // Derived, so it can never disagree with the date beside it.
        ->and($first[$at('Month')])->toBe('=TEXT(A2,"MMM-YY")')
        ->and($second[$at('Month')])->toBe('=TEXT(A3,"MMM-YY")');

    // And the column carries a date format, or the serial shows as 46235.
    expect($export->columnFormats())->toBe(['A2:A1000' => IssueTemplateExport::DATE_FORMAT]);
});

it('reads the template date serial back as the same day on re-upload', function () {
    $at = fn (string $h) => array_search($h, IssueImport::COLUMNS, true);

    issueItem();
    IndentSection::create(['name' => 'Cutting']);
    IndentPerson::create(['name' => 'Rafiq Islam']);
    IssueApprover::create(['name' => 'Store Manager']);
    ItemCategory::create(['name' => 'Needle']);
    stockOnHand(StockItem::first(), 100);

    // The row exactly as the template writes it — serial date and all — with
    // only the example marker replaced by a real item.
    $row = (new IssueTemplateExport)->array()[0];
    $row[$at('Item Name*')] = 'Sewing Needle';

    $result = IssueImport::parse([IssueImport::COLUMNS, $row]);

    expect($result['errors'])->toBe([])
        ->and($result['requisitions'])->toHaveCount(1)
        ->and($result['requisitions'][0]['issue_date'])
        ->toBe(IssueImport::SAMPLE_ROW[$at('Issue Date*')]);
});

it('groups rows sharing the five header fields into one requisition', function () {
    $needle = issueItem();
    $thread = issueItem('Sewing Thread', 'Cone');
    stockOnHand($needle, 100);
    stockOnHand($thread, 100);

    IndentSection::create(['name' => 'Cutting']);
    IndentSection::create(['name' => 'Sewing']);
    IndentPerson::create(['name' => 'Rafiq Islam']);

    $result = IssueImport::parse(issueSheet([
        // Requisition 1 — two items.
        issueRow(['Issue Date*' => '2026-08-01', 'Requisition Number' => 'REQ-1',
            'Indent Section' => 'Cutting', 'Indent Person' => 'Rafiq Islam',
            'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 5]),
        issueRow(['Issue Date*' => '2026-08-01', 'Requisition Number' => 'REQ-1',
            'Indent Section' => 'Cutting', 'Indent Person' => 'Rafiq Islam',
            'Item Name*' => 'Sewing Thread', 'Issued Qty*' => 3]),
        // Requisition 2 — different section, so a separate requisition.
        issueRow(['Issue Date*' => '2026-08-01', 'Requisition Number' => 'REQ-2',
            'Indent Section' => 'Sewing', 'Indent Person' => 'Rafiq Islam',
            'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 2]),
    ]));

    expect($result['errors'])->toBeEmpty()
        ->and($result['requisitions'])->toHaveCount(2);

    expect($result['requisitions'][0]['lines'])->toHaveCount(2)
        ->and($result['requisitions'][0]['requisition_no'])->toBe('REQ-1')
        ->and($result['requisitions'][1]['lines'])->toHaveCount(1)
        ->and($result['requisitions'][1]['requisition_no'])->toBe('REQ-2');
});

it('groups on the other four fields when the requisition number is blank', function () {
    $item = issueItem();
    stockOnHand($item, 100);
    IndentSection::create(['name' => 'Cutting']);

    $result = IssueImport::parse(issueSheet([
        issueRow(['Issue Date*' => '2026-08-01', 'Indent Section' => 'Cutting',
            'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 4]),
        issueRow(['Issue Date*' => '2026-08-01', 'Indent Section' => 'Cutting',
            'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 6]),
    ]));

    expect($result['errors'])->toBeEmpty()
        ->and($result['requisitions'])->toHaveCount(1)
        ->and($result['requisitions'][0]['lines'])->toHaveCount(2);
});

it('rejects an unknown item without creating it', function () {
    $item = issueItem();
    stockOnHand($item, 100);

    $result = IssueImport::parse(issueSheet([
        issueRow(['Issue Date*' => '2026-08-01', 'Item Name*' => 'Not In The Master', 'Issued Qty*' => 1]),
    ]));

    expect($result['requisitions'])->toBeEmpty();
    expect(implode(' ', $result['errors']))->toContain('is not in the item master');
    expect(StockItem::where('name', 'Not In The Master')->exists())->toBeFalse();
});

/**
 * The three header masters are created on demand, the way the manual form
 * creates them; Item and Category are still matched strictly. This pair of
 * tests is the line between the two, and the reasoning is in the class comment
 * on IssueImport.
 */
it('creates an unknown Section, Person and Approver instead of rejecting the requisition', function () {
    $item = issueItem();
    stockOnHand($item, 100);

    $result = IssueImport::parse(issueSheet([
        issueRow([
            'Issue Date*' => '2026-08-01',
            'Indent Section' => 'Finishing-2',
            'Indent Person' => 'Mr. Niranjan- Quality Inspection',
            'Approved By' => 'Md. Rasel (Store In-charge)',
            'Item Name*' => 'Sewing Needle',
            'Issued Qty*' => 1,
        ]),
    ]));

    expect($result['errors'])->toBe([])
        ->and($result['requisitions'])->toHaveCount(1);

    $section = IndentSection::where('name', 'Finishing-2')->first();
    $person = IndentPerson::where('name', 'Mr. Niranjan- Quality Inspection')->first();
    $approver = IssueApprover::where('name', 'Md. Rasel (Store In-charge)')->first();

    // Created, active, and actually attached to the requisition.
    expect($section?->is_active)->toBeTrue()
        ->and($person?->is_active)->toBeTrue()
        ->and($approver?->is_active)->toBeTrue()
        ->and($result['requisitions'][0]['indent_section_id'])->toBe($section->id)
        ->and($result['requisitions'][0]['indent_person_id'])->toBe($person->id)
        ->and($result['requisitions'][0]['issue_approver_id'])->toBe($approver->id);

    // ...and the file says so, naming each one.
    $notes = implode(' | ', $result['notes']);

    expect($notes)->toContain('1 new Indent Section entry was added to Issue Setup')
        ->and($notes)->toContain('Finishing-2')
        ->and($notes)->toContain('Mr. Niranjan- Quality Inspection')
        ->and($notes)->toContain('Md. Rasel (Store In-charge)');
});

it('still refuses an unknown Category, and never creates one', function () {
    $item = issueItem();
    stockOnHand($item, 100);

    $result = IssueImport::parse(issueSheet([
        issueRow([
            'Issue Date*' => '2026-08-01',
            'Category' => 'No Such Category',
            'Item Name*' => 'Sewing Needle',
            'Issued Qty*' => 1,
        ]),
    ]));

    expect($result['requisitions'])->toBeEmpty()
        ->and(implode(' ', $result['errors']))->toContain('is not in the Category list')
        ->and(ItemCategory::where('name', 'No Such Category')->exists())->toBeFalse();
});

it('creates one master however many rows and files name it', function () {
    $item = issueItem();
    stockOnHand($item, 100);

    // Same approver, three spellings that differ only by case and spacing, on
    // three separate requisitions.
    $sheet = issueSheet([
        issueRow(['Issue Date*' => '2026-08-01', 'Requisition Number' => 'REQ-1', 'Approved By' => 'Md. Rasel (Store In-charge)', 'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 1]),
        issueRow(['Issue Date*' => '2026-08-02', 'Requisition Number' => 'REQ-2', 'Approved By' => 'md. rasel (store in-charge)', 'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 1]),
        issueRow(['Issue Date*' => '2026-08-03', 'Requisition Number' => 'REQ-3', 'Approved By' => '  Md.  Rasel   (Store In-charge)  ', 'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 1]),
    ]);

    $first = IssueImport::parse($sheet);

    expect($first['requisitions'])->toHaveCount(3)
        ->and(IssueApprover::count())->toBe(1);

    // Every requisition points at the one row.
    $ids = array_unique(array_column($first['requisitions'], 'issue_approver_id'));
    expect($ids)->toHaveCount(1);

    // Re-uploading the same file adds nothing and claims nothing.
    $second = IssueImport::parse($sheet);

    expect(IssueApprover::count())->toBe(1)
        ->and(implode(' ', $second['notes']))->not->toContain('added to Issue Setup');
});

it('brings a deactivated master back rather than making a second one', function () {
    $item = issueItem();
    stockOnHand($item, 100);

    $approver = IssueApprover::create(['name' => 'Store Manager', 'is_active' => false]);

    $result = IssueImport::parse(issueSheet([
        issueRow([
            'Issue Date*' => '2026-08-01',
            'Approved By' => 'store manager',
            'Item Name*' => 'Sewing Needle',
            'Issued Qty*' => 1,
        ]),
    ]));

    expect($result['requisitions'])->toHaveCount(1)
        ->and(IssueApprover::count())->toBe(1)
        ->and($approver->fresh()->is_active)->toBeTrue()
        ->and($result['requisitions'][0]['issue_approver_id'])->toBe($approver->id);
});

it('stores Category as data, unlike Uom which only cross-checks', function () {
    // The one column that behaves differently from its receiving namesake.
    $item = issueItem('Sewing Needle', 'Pkt');
    stockOnHand($item, 100);
    $category = ItemCategory::create(['name' => 'Consumable']);

    $result = IssueImport::parse(issueSheet([
        issueRow([
            'Issue Date*' => '2026-08-01',
            'Item Name*' => 'Sewing Needle',
            'Category' => 'Consumable',
            // Deliberately disagrees with the item master.
            'Uom' => 'Box',
            'Issued Qty*' => 1,
        ]),
    ]));

    expect($result['requisitions'])->toHaveCount(1);

    $line = $result['requisitions'][0]['lines'][0];

    // Category is kept, resolved to its master row.
    expect($line['item_category_id'])->toBe($category->id);

    // Uom is not kept anywhere — it only produced a note.
    expect($line)->not->toHaveKey('uom');
    expect(implode(' ', $result['notes']))->toContain('Uom in the file is "Box"')
        ->and(implode(' ', $result['notes']))->toContain('The item master was used.');
});

it('refuses requisitions that collectively overdraw one item', function () {
    // 100 in stock. Three requisitions of 40 each: every one affordable alone,
    // and together 20 more than exists.
    $item = issueItem();
    stockOnHand($item, 100);
    IndentSection::create(['name' => 'Cutting']);

    $result = IssueImport::parse(issueSheet([
        issueRow(['Issue Date*' => '2026-08-01', 'Requisition Number' => 'REQ-1',
            'Indent Section' => 'Cutting', 'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 40]),
        issueRow(['Issue Date*' => '2026-08-01', 'Requisition Number' => 'REQ-2',
            'Indent Section' => 'Cutting', 'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 40]),
        issueRow(['Issue Date*' => '2026-08-01', 'Requisition Number' => 'REQ-3',
            'Indent Section' => 'Cutting', 'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 40]),
    ]));

    // The first two fit; the third does not.
    expect($result['requisitions'])->toHaveCount(2);

    $accepted = collect($result['requisitions'])->pluck('requisition_no')->all();

    expect($accepted)->toBe(['REQ-1', 'REQ-2']);

    $errors = implode(' ', $result['errors']);

    expect($errors)->toContain('REQ-3')
        ->toContain('not enough stock')
        ->toContain('already issued by earlier rows in this file');

    // The total accepted never exceeds what exists.
    $total = collect($result['requisitions'])
        ->flatMap(fn ($r) => $r['lines'])
        ->sum('qty');

    expect($total)->toBeLessThanOrEqual(100.0);
});

it('sums the same item listed twice inside one requisition', function () {
    $item = issueItem();
    stockOnHand($item, 50);

    $result = IssueImport::parse(issueSheet([
        issueRow(['Issue Date*' => '2026-08-01', 'Requisition Number' => 'REQ-1',
            'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 30]),
        issueRow(['Issue Date*' => '2026-08-01', 'Requisition Number' => 'REQ-1',
            'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 30]),
    ]));

    // 30 + 30 against 50 — individually fine, together not.
    expect($result['requisitions'])->toBeEmpty();
    expect(implode(' ', $result['errors']))->toContain('not enough stock');
});

it('lets a good requisition through while a broken one is reported', function () {
    $item = issueItem();
    stockOnHand($item, 100);

    $result = IssueImport::parse(issueSheet([
        issueRow(['Issue Date*' => '2026-08-01', 'Requisition Number' => 'GOOD',
            'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 5]),
        issueRow(['Issue Date*' => '2026-08-01', 'Requisition Number' => 'BAD',
            'Item Name*' => 'Ghost Item', 'Issued Qty*' => 5]),
    ]));

    expect($result['requisitions'])->toHaveCount(1)
        ->and($result['requisitions'][0]['requisition_no'])->toBe('GOOD');

    expect(implode(' ', $result['errors']))->toContain('BAD');
});

it('imports cleanly with every optional column blank', function () {
    $item = issueItem();
    stockOnHand($item, 100);

    $result = IssueImport::parse(issueSheet([
        issueRow(['Issue Date*' => '2026-08-01', 'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 3]),
    ]));

    expect($result['errors'])->toBeEmpty()
        ->and($result['notes'])->toBeEmpty()
        ->and($result['requisitions'])->toHaveCount(1);

    $req = $result['requisitions'][0];

    expect($req['requisition_no'])->toBeNull()
        ->and($req['indent_section_id'])->toBeNull()
        ->and($req['indent_person_id'])->toBeNull()
        ->and($req['issue_approver_id'])->toBeNull()
        ->and($req['lines'][0]['item_category_id'])->toBeNull()
        ->and($req['lines'][0]['requisition_type'])->toBeNull();
});

it('accepts New and Replace in any case and refuses anything else', function () {
    $item = issueItem();
    stockOnHand($item, 100);

    $ok = IssueImport::parse(issueSheet([
        issueRow(['Issue Date*' => '2026-08-01', 'Requisition Number' => 'A',
            'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 1, 'Type' => 'replace']),
    ]));

    expect($ok['requisitions'][0]['lines'][0]['requisition_type'])->toBe('Replace');

    $bad = IssueImport::parse(issueSheet([
        issueRow(['Issue Date*' => '2026-08-01', 'Requisition Number' => 'B',
            'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 1, 'Type' => 'Borrowed']),
    ]));

    expect($bad['requisitions'])->toBeEmpty();
    expect(implode(' ', $bad['errors']))->toContain('Type must be New or Replace');
});

it('skips the template example row', function () {
    $item = issueItem();
    stockOnHand($item, 100);

    $result = IssueImport::parse(issueSheet([
        IssueImport::SAMPLE_ROW,
        issueRow(['Issue Date*' => '2026-08-01', 'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 1]),
    ]));

    expect($result['requisitions'])->toHaveCount(1);
    expect(implode(' ', $result['skipped']))->toContain('example row was ignored');
});

it('accepts legacy header spellings', function () {
    $item = issueItem();
    stockOnHand($item, 100);
    IndentSection::create(['name' => 'Cutting']);

    $headings = ['Date', 'Month', 'Department', 'Issued To', 'Approver', 'Req No',
        'Particulars', 'Unit', 'Item Category', 'Qty', 'Requisition Type', 'Note'];

    $row = array_fill(0, count($headings), null);
    $row[0] = '2026-08-01';
    $row[2] = 'Cutting';
    $row[6] = 'Sewing Needle';
    $row[9] = 4;

    $result = IssueImport::parse(array_merge([$headings], [$row]));

    expect($result['errors'])->toBeEmpty()
        ->and($result['requisitions'])->toHaveCount(1)
        ->and($result['requisitions'][0]['lines'][0]['qty'])->toBe(4.0);
});

/**
 * Write a parsed requisition the way the controller does, so the duplicate
 * tests compare against rows that look exactly like imported ones.
 */
function recordParsed(array $requisitions): int
{
    $lines = 0;

    foreach ($requisitions as $requisition) {
        foreach ($requisition['lines'] as $line) {
            StockIssue::create([
                'issue_date' => $requisition['issue_date'],
                'requisition_no' => $requisition['requisition_no'],
                'indent_section_id' => $requisition['indent_section_id'],
                'indent_person_id' => $requisition['indent_person_id'],
                'issue_approver_id' => $requisition['issue_approver_id'],
                'stock_item_id' => $line['stock_item_id'],
                'qty' => $line['qty'],
                'item_category_id' => $line['item_category_id'],
                'requisition_type' => $line['requisition_type'],
                'remarks' => $line['remarks'],
            ]);

            $lines++;
        }
    }

    return $lines;
}

it('skips everything on a second import of the same file', function () {
    $needle = issueItem();
    $thread = issueItem('Sewing Thread', 'Cone');
    stockOnHand($needle, 100);
    stockOnHand($thread, 100);
    IndentSection::create(['name' => 'Cutting']);

    $sheet = issueSheet([
        issueRow(['Issue Date*' => '2026-08-01', 'Requisition Number' => 'REQ-1',
            'Indent Section' => 'Cutting', 'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 5]),
        issueRow(['Issue Date*' => '2026-08-01', 'Requisition Number' => 'REQ-1',
            'Indent Section' => 'Cutting', 'Item Name*' => 'Sewing Thread', 'Issued Qty*' => 3]),
        issueRow(['Issue Date*' => '2026-08-02', 'Requisition Number' => 'REQ-2',
            'Indent Section' => 'Cutting', 'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 2]),
    ]);

    // First pass.
    $first = IssueImport::parse($sheet);

    expect($first['requisitions'])->toHaveCount(2)
        ->and($first['skipped'])->toBeEmpty();

    recordParsed($first['requisitions']);

    $countAfterFirst = StockIssue::count();
    $issuedAfterFirst = (float) StockIssue::sum('qty');

    expect($countAfterFirst)->toBe(3)
        ->and($issuedAfterFirst)->toBe(10.0);

    // Second pass, identical file.
    $second = IssueImport::parse($sheet);

    expect($second['requisitions'])->toBeEmpty()
        ->and($second['errors'])->toBeEmpty()
        ->and($second['skipped'])->toHaveCount(2);

    expect(implode(' | ', $second['skipped']))
        ->toContain('REQ-1')
        ->toContain('REQ-2')
        ->toContain('already recorded in Issue History and was skipped');

    // Nothing to write, so nothing changed.
    recordParsed($second['requisitions']);

    expect(StockIssue::count())->toBe($countAfterFirst)
        ->and((float) StockIssue::sum('qty'))->toBe($issuedAfterFirst);
});

it('still imports a genuinely different requisition on the same date', function () {
    $item = issueItem();
    stockOnHand($item, 100);
    IndentSection::create(['name' => 'Cutting']);

    $recorded = IssueImport::parse(issueSheet([
        issueRow(['Issue Date*' => '2026-08-01', 'Requisition Number' => 'REQ-1',
            'Indent Section' => 'Cutting', 'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 5]),
    ]));

    recordParsed($recorded['requisitions']);

    // Same date, same section, different number.
    $next = IssueImport::parse(issueSheet([
        issueRow(['Issue Date*' => '2026-08-01', 'Requisition Number' => 'REQ-1',
            'Indent Section' => 'Cutting', 'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 5]),
        issueRow(['Issue Date*' => '2026-08-01', 'Requisition Number' => 'REQ-2',
            'Indent Section' => 'Cutting', 'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 4]),
    ]));

    expect($next['requisitions'])->toHaveCount(1)
        ->and($next['requisitions'][0]['requisition_no'])->toBe('REQ-2')
        ->and($next['skipped'])->toHaveCount(1);
});

it('does not re-import a numbered requisition that has gained a line', function () {
    // The reason the item set is excluded from a numbered key: adding a
    // forgotten line must not make the whole requisition look new.
    $needle = issueItem();
    $thread = issueItem('Sewing Thread', 'Cone');
    stockOnHand($needle, 100);
    stockOnHand($thread, 100);
    IndentSection::create(['name' => 'Cutting']);

    $recorded = IssueImport::parse(issueSheet([
        issueRow(['Issue Date*' => '2026-08-01', 'Requisition Number' => 'REQ-1',
            'Indent Section' => 'Cutting', 'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 5]),
    ]));

    recordParsed($recorded['requisitions']);

    // Same requisition, now with a second item on it.
    $again = IssueImport::parse(issueSheet([
        issueRow(['Issue Date*' => '2026-08-01', 'Requisition Number' => 'REQ-1',
            'Indent Section' => 'Cutting', 'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 5]),
        issueRow(['Issue Date*' => '2026-08-01', 'Requisition Number' => 'REQ-1',
            'Indent Section' => 'Cutting', 'Item Name*' => 'Sewing Thread', 'Issued Qty*' => 2]),
    ]));

    expect($again['requisitions'])->toBeEmpty()
        ->and($again['skipped'])->toHaveCount(1);

    // The needle was not issued a second time.
    expect((float) StockIssue::sum('qty'))->toBe(5.0);
});

it('imports two unnumbered same-day issues carrying DIFFERENT items', function () {
    // The item set is what tells them apart. Without it the second would be
    // swallowed as a duplicate, losing a real issue.
    $needle = issueItem();
    $thread = issueItem('Sewing Thread', 'Cone');
    stockOnHand($needle, 100);
    stockOnHand($thread, 100);
    IndentSection::create(['name' => 'Cutting']);

    $recorded = IssueImport::parse(issueSheet([
        issueRow(['Issue Date*' => '2026-08-01', 'Indent Section' => 'Cutting',
            'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 5]),
    ]));

    recordParsed($recorded['requisitions']);

    $next = IssueImport::parse(issueSheet([
        issueRow(['Issue Date*' => '2026-08-01', 'Indent Section' => 'Cutting',
            'Item Name*' => 'Sewing Thread', 'Issued Qty*' => 3]),
    ]));

    expect($next['requisitions'])->toHaveCount(1)
        ->and($next['skipped'])->toBeEmpty();
});

it('skips two unnumbered same-day issues carrying the SAME items', function () {
    // The honest weak spot, asserted rather than left to chance: two separate
    // slips from one section on one day for one item are indistinguishable,
    // and the second is treated as a duplicate. That is the designed trade —
    // the alternative is double-counting every genuine re-upload.
    $item = issueItem();
    stockOnHand($item, 100);
    IndentSection::create(['name' => 'Cutting']);

    $recorded = IssueImport::parse(issueSheet([
        issueRow(['Issue Date*' => '2026-08-01', 'Indent Section' => 'Cutting',
            'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 5]),
    ]));

    recordParsed($recorded['requisitions']);

    // A genuinely separate slip — different quantity, same item and header.
    $next = IssueImport::parse(issueSheet([
        issueRow(['Issue Date*' => '2026-08-01', 'Indent Section' => 'Cutting',
            'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 9]),
    ]));

    expect($next['requisitions'])->toBeEmpty()
        ->and($next['skipped'])->toHaveCount(1);

    expect(implode(' ', $next['skipped']))->toContain('already recorded in Issue History');

    // Stock reflects only the first: the second was not issued.
    expect((float) StockIssue::sum('qty'))->toBe(5.0);
});

it('checks for duplicates before the stock check, so a repeat frees no headroom', function () {
    // 100 in stock, 60 already issued by an earlier import. A re-uploaded copy
    // of that 60 must not consume the remaining 40 and push a genuine new
    // requisition into a shortfall.
    $item = issueItem();
    stockOnHand($item, 100);
    IndentSection::create(['name' => 'Cutting']);

    $recorded = IssueImport::parse(issueSheet([
        issueRow(['Issue Date*' => '2026-08-01', 'Requisition Number' => 'OLD',
            'Indent Section' => 'Cutting', 'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 60]),
    ]));

    recordParsed($recorded['requisitions']);

    $mixed = IssueImport::parse(issueSheet([
        // The duplicate, first in the file.
        issueRow(['Issue Date*' => '2026-08-01', 'Requisition Number' => 'OLD',
            'Indent Section' => 'Cutting', 'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 60]),
        // A new requisition for what is genuinely left.
        issueRow(['Issue Date*' => '2026-08-02', 'Requisition Number' => 'NEW',
            'Indent Section' => 'Cutting', 'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 40]),
    ]));

    expect($mixed['skipped'])->toHaveCount(1)
        ->and($mixed['errors'])->toBeEmpty()
        ->and($mixed['requisitions'])->toHaveCount(1)
        ->and($mixed['requisitions'][0]['requisition_no'])->toBe('NEW');
});

it('uploading the same file twice through the screen issues nothing extra', function () {
    // End to end, through the real endpoint, because every other duplicate
    // test writes the rows itself and could agree with a mistake the
    // controller does not make.
    $item = issueItem();
    stockOnHand($item, 100);

    // Both rights: the section guard needs view to enter Issues at all, and
    // the import route needs create on top of it.
    Permission::findOrCreate('store.issues.view', 'web');
    Permission::findOrCreate('store.issues.create', 'web');

    $user = User::factory()->create(['status' => 1]);
    $user->givePermissionTo(['store.issues.view', 'store.issues.create']);

    // Built through issueRow() rather than typed as a comma string: a
    // hand-counted row silently puts the quantity under the wrong heading the
    // next time a column is added.
    $csv = implode(',', IssueImport::COLUMNS)."\n"
        .implode(',', issueRow([
            'Issue Date*' => '2026-08-01',
            'Requisition Number' => 'REQ-77',
            'Item Name*' => 'Sewing Needle',
            'Issued Qty*' => 12,
        ]))."\n";

    $upload = fn () => $this->actingAs($user)->post(
        route('store.stock.issues.import'),
        ['file' => UploadedFile::fake()->createWithContent('issues.csv', $csv)]
    );

    $upload()->assertRedirect()->assertSessionHas('success');

    expect(StockIssue::count())->toBe(1)
        ->and((float) StockIssue::sum('qty'))->toBe(12.0);

    // Same file again.
    $second = $upload()->assertRedirect();

    $second->assertSessionHas('import_skipped');

    expect(implode(' ', session('import_skipped') ?? []))
        ->toContain('REQ-77')
        ->toContain('already recorded in Issue History');

    // Nothing added, nothing issued twice.
    expect(StockIssue::count())->toBe(1)
        ->and((float) StockIssue::sum('qty'))->toBe(12.0);
});

/**
 * The skipped-rows download: the rows an import could not take, handed back in
 * the upload's own format so they can be fixed and uploaded again.
 *
 * The load-bearing guarantee is the ROUND TRIP — this file's whole purpose is
 * to be re-uploaded, so the tests that matter most are the ones that read it
 * back in rather than the ones that inspect its cells.
 */
it('hands back every row of a rejected requisition, not only the faulty one', function () {
    $item = issueItem();
    stockOnHand($item, 100);

    $result = IssueImport::parse(issueSheet([
        // One requisition, three lines, one of them unknown. All three were
        // refused, so all three have to come back — re-uploading only the bad
        // line would record a third of a slip.
        issueRow(['Issue Date*' => '2026-08-01', 'Requisition Number' => 'REQ-1',
            'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 5]),
        issueRow(['Issue Date*' => '2026-08-01', 'Requisition Number' => 'REQ-1',
            'Item Name*' => 'Ghost Item', 'Issued Qty*' => 2]),
        issueRow(['Issue Date*' => '2026-08-01', 'Requisition Number' => 'REQ-1',
            'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 1]),
    ]));

    expect($result['requisitions'])->toBeEmpty()
        ->and($result['skipped_rows'])->toHaveCount(3);

    $reasons = array_column($result['skipped_rows'], 'reason');

    // The offending line says what is wrong with it; the other two are told
    // which line to go and look at.
    expect($reasons[1])->toContain('Item not in item master')
        ->and($reasons[0])->toContain('row 3 of the same requisition was rejected')
        ->and($reasons[2])->toContain('row 3 of the same requisition was rejected');

    // In file order, whatever order the groups were settled in.
    expect(array_column($result['skipped_rows'], 'line'))->toBe([2, 3, 4]);
});

it('leaves an imported requisition out of the skipped rows entirely', function () {
    $item = issueItem();
    stockOnHand($item, 100);

    $result = IssueImport::parse(issueSheet([
        issueRow(['Issue Date*' => '2026-08-01', 'Requisition Number' => 'GOOD',
            'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 5]),
        issueRow(['Issue Date*' => '2026-08-01', 'Requisition Number' => 'BAD',
            'Item Name*' => 'Ghost Item', 'Issued Qty*' => 5]),
    ]));

    expect($result['requisitions'])->toHaveCount(1)
        ->and($result['skipped_rows'])->toHaveCount(1)
        ->and($result['skipped_rows'][0]['line'])->toBe(3);
});

it('includes duplicates and shortfalls, each saying which it is', function () {
    $item = issueItem();
    stockOnHand($item, 100);
    IndentSection::create(['name' => 'Cutting']);

    // Recorded first, so the same requisition comes back as a duplicate.
    $recorded = IssueImport::parse(issueSheet([
        issueRow(['Issue Date*' => '2026-08-01', 'Requisition Number' => 'OLD',
            'Indent Section' => 'Cutting', 'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 60]),
    ]));

    recordParsed($recorded['requisitions']);

    $result = IssueImport::parse(issueSheet([
        issueRow(['Issue Date*' => '2026-08-01', 'Requisition Number' => 'OLD',
            'Indent Section' => 'Cutting', 'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 60]),
        // 100 in stock, 60 already gone: this cannot be afforded.
        issueRow(['Issue Date*' => '2026-08-02', 'Requisition Number' => 'TOO-BIG',
            'Indent Section' => 'Cutting', 'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 90]),
    ]));

    expect($result['skipped_rows'])->toHaveCount(2);

    $reasons = array_column($result['skipped_rows'], 'reason');

    expect($reasons[0])->toBe('Already recorded in Issue History.')
        ->and($reasons[1])->toContain('Not enough stock')
        ->and($reasons[1])->toContain('Sewing Needle');
});

it('keeps the template example row out of the skipped rows', function () {
    $item = issueItem();
    stockOnHand($item, 100);

    $result = IssueImport::parse(issueSheet([
        IssueImport::SAMPLE_ROW,
        issueRow(['Issue Date*' => '2026-08-01', 'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 1]),
    ]));

    // Reported on screen as skipped, but never offered back for re-upload:
    // it is placeholder junk, not something to fix.
    expect(implode(' ', $result['skipped']))->toContain('example row was ignored')
        ->and($result['skipped_rows'])->toBeEmpty();
});

it('rewrites a skipped row under the template headings whatever the file used', function () {
    $item = issueItem();
    stockOnHand($item, 100);

    // A legacy workbook: its own heading spellings, and only some of the
    // columns. What comes back must still be in template shape.
    $headings = ['Date', 'Particulars', 'Qty', 'Department'];

    $result = IssueImport::parse([
        $headings,
        ['2026-08-01', 'Ghost Item', 4, 'Cutting'],
    ]);

    expect($result['skipped_rows'])->toHaveCount(1);

    $values = $result['skipped_rows'][0]['values'];
    $at = fn (string $h) => array_search($h, IssueImport::COLUMNS, true);

    expect($values)->toHaveCount(count(IssueImport::COLUMNS))
        ->and($values[$at('Item Name*')])->toBe('Ghost Item')
        ->and($values[$at('Issued Qty*')])->toBe(4)
        ->and($values[$at('Indent Section')])->toBe('Cutting')
        ->and($values[$at('Remarks')])->toBeNull();
});

it('writes the skipped-rows file as the template plus a reason column', function () {
    $item = issueItem();
    stockOnHand($item, 100);

    $result = IssueImport::parse(issueSheet([
        issueRow(['Issue Date*' => '2026-08-01', 'Item Name*' => 'Ghost Item', 'Issued Qty*' => 3]),
    ]));

    $export = new SkippedIssueRowsExport($result['skipped_rows']);

    // Same columns as the upload template, in the same order, with the reason
    // APPENDED — so the importer, which maps by name, simply ignores it and the
    // user need not delete it before uploading.
    expect($export->headings())->toBe([...IssueImport::COLUMNS, 'Reason Skipped']);

    [$row] = $export->array();
    $at = fn (string $h) => array_search($h, IssueImport::COLUMNS, true);

    expect($row)->toHaveCount(count(IssueImport::COLUMNS) + 1)
        ->and(end($row))->toContain('Item not in item master')
        ->and($row[$at('Item Name*')])->toBe('Ghost Item');

    // The date is a real Excel value carrying the template's own format, not
    // text — this file exists to be re-uploaded, and text dates are the exact
    // bug the template was fixed for.
    expect($row[$at('Issue Date*')])->toBeNumeric()
        ->and(Date::excelToDateTimeObject((float) $row[$at('Issue Date*')])->format('Y-m-d'))->toBe('2026-08-01')
        ->and($row[$at('Month')])->toBe('=TEXT(A2,"MMM-YY")')
        ->and($export->columnFormats())->toBe(['A2:A2' => IssueTemplateExport::DATE_FORMAT]);
});

it('leaves an unreadable date exactly as the user typed it', function () {
    issueItem();

    $result = IssueImport::parse(issueSheet([
        issueRow(['Issue Date*' => 'not a date', 'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 1]),
    ]));

    [$row] = (new SkippedIssueRowsExport($result['skipped_rows']))->array();
    $at = fn (string $h) => array_search($h, IssueImport::COLUMNS, true);

    // Not blanked and not guessed at: that cell is the fault, and the user has
    // to be able to see what they wrote to correct it.
    expect($row[$at('Issue Date*')])->toBe('not a date')
        ->and(end($row))->toContain('Issue Date is not a valid date');
});

it('offers the skipped rows for download after an import, and not before', function () {
    $item = issueItem();
    stockOnHand($item, 100);

    Permission::findOrCreate('store.issues.view', 'web');
    Permission::findOrCreate('store.issues.create', 'web');

    $user = User::factory()->create(['status' => 1]);
    $user->givePermissionTo(['store.issues.view', 'store.issues.create']);

    // Nothing imported yet, so nothing to hand back.
    $this->actingAs($user)
        ->get(route('store.stock.issues.skipped-rows'))
        ->assertRedirect()
        ->assertSessionHas('warning');

    $csv = implode(',', IssueImport::COLUMNS)."\n"
        .implode(',', issueRow([
            'Issue Date*' => '2026-08-01', 'Requisition Number' => 'GOOD',
            'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 5,
        ]))."\n"
        .implode(',', issueRow([
            'Issue Date*' => '2026-08-01', 'Requisition Number' => 'BAD',
            'Item Name*' => 'Ghost Item', 'Issued Qty*' => 5,
        ]))."\n";

    $page = $this->actingAs($user)
        ->from(route('store.stock.issues.index'))
        ->post(
            route('store.stock.issues.import'),
            ['file' => UploadedFile::fake()->createWithContent('issues.csv', $csv)]
        )
        ->assertRedirect()
        ->assertSessionHas('success');

    // The good one went in; the bad one is waiting to be downloaded.
    expect(StockIssue::count())->toBe(1)
        ->and(session('import_skipped_rows'))->toHaveCount(1)
        ->and(session('import_skipped_row_count'))->toBe(1);

    // And the screen the user lands on offers it, with a visible label rather
    // than a bare icon.
    $this->actingAs($user)->get($page->headers->get('Location'))
        ->assertOk()
        ->assertSee('1 row was not imported.')
        ->assertSee('Download Skipped Rows')
        ->assertSee(route('store.stock.issues.skipped-rows'), false);

    $this->actingAs($user)
        ->get(route('store.stock.issues.skipped-rows'))
        ->assertOk()
        ->assertDownload();

    // A clean import clears it, so a stale file is never offered.
    $clean = implode(',', IssueImport::COLUMNS)."\n"
        .implode(',', issueRow([
            'Issue Date*' => '2026-08-05', 'Requisition Number' => 'CLEAN',
            'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 1,
        ]))."\n";

    $this->actingAs($user)->post(
        route('store.stock.issues.import'),
        ['file' => UploadedFile::fake()->createWithContent('clean.csv', $clean)]
    )->assertRedirect();

    expect(session('import_skipped_rows'))->toBeNull();
});

it('needs the create right to download the skipped rows', function () {
    Permission::findOrCreate('store.issues.view', 'web');
    Permission::findOrCreate('store.issues.create', 'web');

    $viewer = User::factory()->create(['status' => 1]);
    $viewer->givePermissionTo('store.issues.view');

    $this->actingAs($viewer)
        ->get(route('store.stock.issues.skipped-rows'))
        ->assertForbidden();
});

/**
 * The round trip, end to end, and the reason this feature exists: fix the
 * downloaded file, upload it, and the rows land — WITHOUT the Issue Setup
 * entries the first pass created being created a second time.
 *
 * That last part is the risk worth asserting. The first import auto-creates
 * Section, Person and Approver from names the file introduced; the download
 * carries those same names back out, and the re-upload looks them up again. If
 * the normalised matching did not hold, every skipped-rows re-upload would
 * quietly double the Issue Setup lists.
 */
it('re-imports a fixed skipped-rows file without duplicating Issue Setup entries', function () {
    $needle = issueItem();
    stockOnHand($needle, 200);

    // Nothing in Issue Setup to begin with: the file introduces all three.
    expect(IndentSection::count())->toBe(0)
        ->and(IndentPerson::count())->toBe(0)
        ->and(IssueApprover::count())->toBe(0);

    $header = [
        'Indent Section' => 'Finishing-2',
        'Indent Person' => 'Mr. Niranjan- Quality Inspection',
        'Approved By' => 'Md. Rasel (Store In-charge)',
    ];

    // Pass one: one requisition imports, one is rejected for an unknown item.
    $first = IssueImport::parse(issueSheet([
        issueRow($header + ['Issue Date*' => '2026-08-01', 'Requisition Number' => 'REQ-1',
            'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 5]),
        issueRow($header + ['Issue Date*' => '2026-08-02', 'Requisition Number' => 'REQ-2',
            'Item Name*' => 'Sewing Neddle', 'Issued Qty*' => 7]),
    ]));

    recordParsed($first['requisitions']);

    expect($first['requisitions'])->toHaveCount(1)
        ->and($first['skipped_rows'])->toHaveCount(1);

    // The three masters exist now, created by that first pass.
    expect(IndentSection::count())->toBe(1)
        ->and(IndentPerson::count())->toBe(1)
        ->and(IssueApprover::count())->toBe(1);

    $sectionId = IndentSection::first()->id;
    $personId = IndentPerson::first()->id;
    $approverId = IssueApprover::first()->id;

    // The downloaded file, read back exactly as Excel would hand it over.
    $download = (new SkippedIssueRowsExport($first['skipped_rows']))->array();
    $sheet = array_merge(
        [[...IssueImport::COLUMNS, SkippedIssueRowsExport::REASON_COLUMN]],
        $download
    );

    $at = fn (string $h) => array_search($h, IssueImport::COLUMNS, true);

    // The fix the user makes: correct the typo that caused the skip.
    expect($sheet[1][$at('Item Name*')])->toBe('Sewing Neddle');
    $sheet[1][$at('Item Name*')] = 'Sewing Needle';

    // The Month cell is a formula in the file; Excel hands over its VALUE.
    $sheet[1][$at('Month')] = 'Aug-26';

    $second = IssueImport::parse($sheet);

    // It imports, on the date the serial carried — the round trip's whole point.
    expect($second['errors'])->toBe([])
        ->and($second['requisitions'])->toHaveCount(1)
        ->and($second['requisitions'][0]['issue_date'])->toBe('2026-08-02')
        ->and($second['requisitions'][0]['requisition_no'])->toBe('REQ-2')
        ->and($second['skipped_rows'])->toBeEmpty();

    // THE CHECK THAT MATTERS: the same three masters, matched, not remade.
    expect(IndentSection::count())->toBe(1)
        ->and(IndentPerson::count())->toBe(1)
        ->and(IssueApprover::count())->toBe(1)
        ->and($second['requisitions'][0]['indent_section_id'])->toBe($sectionId)
        ->and($second['requisitions'][0]['indent_person_id'])->toBe($personId)
        ->and($second['requisitions'][0]['issue_approver_id'])->toBe($approverId);

    // ...and the second pass claims to have created nothing.
    expect(implode(' ', $second['notes']))->not->toContain('added to Issue Setup');

    recordParsed($second['requisitions']);

    // Both requisitions are now recorded, each exactly once.
    expect(StockIssue::count())->toBe(2)
        ->and((float) StockIssue::sum('qty'))->toBe(12.0);
});

it('creates a master only for a name the user genuinely changed while fixing the file', function () {
    // The other half of the rule: matching must not be so eager that a real
    // correction is swallowed into the wrong entry.
    $item = issueItem();
    stockOnHand($item, 100);

    $first = IssueImport::parse(issueSheet([
        issueRow(['Issue Date*' => '2026-08-01', 'Indent Section' => 'Finishing-2',
            'Item Name*' => 'Ghost Item', 'Issued Qty*' => 1]),
    ]));

    expect(IndentSection::count())->toBe(1)
        ->and($first['skipped_rows'])->toHaveCount(1);

    $sheet = array_merge(
        [[...IssueImport::COLUMNS, SkippedIssueRowsExport::REASON_COLUMN]],
        (new SkippedIssueRowsExport($first['skipped_rows']))->array()
    );

    $at = fn (string $h) => array_search($h, IssueImport::COLUMNS, true);

    $sheet[1][$at('Item Name*')] = 'Sewing Needle';
    // Case and spacing differ — the same section, so no second row.
    $sheet[1][$at('Indent Section')] = '  finishing-2 ';

    IssueImport::parse($sheet);

    expect(IndentSection::count())->toBe(1);

    // A genuinely different name, though, is a genuinely new section.
    $sheet[1][$at('Indent Section')] = 'Finishing-3';
    $sheet[1][$at('Requisition Number')] = 'REQ-NEW';

    IssueImport::parse($sheet);

    expect(IndentSection::count())->toBe(2)
        ->and(IndentSection::where('name', 'Finishing-3')->exists())->toBeTrue();
});

/**
 * The two failures where the message IS the help: a file whose headings the
 * importer cannot find at all. Both used to return their message through
 * `$blank + ['errors' => ...]`, and PHP's + keeps the LEFT side on a duplicate
 * key — so the empty array in $blank won and the explanation was discarded,
 * leaving the screen saying only that nothing was imported.
 */
it('explains a sheet with no heading row', function () {
    $result = IssueImport::parse([
        ['Some notes about this workbook'],
        ['Sewing Needle', 5],
    ]);

    expect($result['requisitions'])->toBeEmpty()
        ->and($result['errors'])->toHaveCount(1);

    expect($result['errors'][0])->toContain('No heading row was found')
        ->toContain('download the sample template');
});

it('explains a heading row that is missing a required column', function () {
    // Real headings, but no Issued Qty among them. locateHeader() only accepts
    // a row carrying ALL the required headings, so this is not recognised as a
    // heading row at all and the same message answers it — which is why the
    // message names the three columns rather than just saying "no headings".
    $result = IssueImport::parse([
        ['Issue Date*', 'Item Name*', 'Remarks'],
        ['2026-08-01', 'Sewing Needle', 'note'],
    ]);

    expect($result['requisitions'])->toBeEmpty()
        ->and($result['errors'])->toHaveCount(1);

    expect($result['errors'][0])->toContain('No heading row was found')
        ->toContain('Issue Date, Item Name and Issued Qty');
});

it('shows the heading-row error on screen instead of a bare failure notice', function () {
    Permission::findOrCreate('store.issues.view', 'web');
    Permission::findOrCreate('store.issues.create', 'web');

    $user = User::factory()->create(['status' => 1]);
    $user->givePermissionTo(['store.issues.view', 'store.issues.create']);

    // A perfectly readable CSV that simply is not the template.
    $csv = "Product,Amount\nSewing Needle,5\n";

    $this->actingAs($user)->post(
        route('store.stock.issues.import'),
        ['file' => UploadedFile::fake()->createWithContent('wrong.csv', $csv)]
    )->assertRedirect()->assertSessionHas('import_errors');

    expect(implode(' ', session('import_errors')))->toContain('No heading row was found');
});

it('records imported issues through the controller, scoped by permission', function () {
    $item = issueItem();
    stockOnHand($item, 100);

    Permission::findOrCreate('store.issues.view', 'web');
    Permission::findOrCreate('store.issues.create', 'web');

    $viewer = User::factory()->create(['status' => 1]);
    $viewer->givePermissionTo('store.issues.view');

    $creator = User::factory()->create(['status' => 1]);
    $creator->givePermissionTo(['store.issues.view', 'store.issues.create']);

    $csv = implode(',', IssueImport::COLUMNS)."\n"
        .implode(',', issueRow([
            'Issue Date*' => '2026-08-01',
            'Requisition Number' => 'REQ-9',
            'Item Name*' => 'Sewing Needle',
            'Issued Qty*' => 7,
        ]))."\n";

    $file = UploadedFile::fake()->createWithContent('issues.csv', $csv);

    // View-only cannot import.
    $this->actingAs($viewer)
        ->post(route('store.stock.issues.import'), ['file' => $file])
        ->assertForbidden();

    expect(StockIssue::count())->toBe(0);

    $file = UploadedFile::fake()->createWithContent('issues.csv', $csv);

    $this->actingAs($creator)
        ->post(route('store.stock.issues.import'), ['file' => $file])
        ->assertRedirect();

    expect(StockIssue::count())->toBe(1);

    $issue = StockIssue::first();

    expect($issue->requisition_no)->toBe('REQ-9')
        ->and((float) $issue->qty)->toBe(7.0)
        ->and($issue->stock_item_id)->toBe($item->id);
});
