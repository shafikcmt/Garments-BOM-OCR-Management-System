<?php

use App\Exports\IssueTemplateExport;
use App\Imports\IssueImport;
use App\Models\IndentPerson;
use App\Models\IndentSection;
use App\Models\IssueApprover;
use App\Models\ItemCategory;
use App\Models\StockItem;
use App\Models\StockIssue;
use App\Models\StockPurchase;

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
        'Uom',
        'Category',
        'Issued Qty*',
        'Type',
        'Remarks',
    ]);
});

it('gives the template the same headings and two aligned example rows', function () {
    $export = new IssueTemplateExport();

    expect($export->headings())->toBe(IssueImport::COLUMNS)
        ->and(IssueImport::SAMPLE_ROW)->toHaveCount(count(IssueImport::COLUMNS));

    [$first, $second] = $export->array();

    $at = fn (string $h) => array_search($h, IssueImport::COLUMNS, true);

    expect($first)->toBe(IssueImport::SAMPLE_ROW)
        ->and($second[$at('Item Name*')])->toBe('EXAMPLE — a second item on the SAME requisition')
        ->and($second[$at('Issued Qty*')])->toBe(2)
        ->and($second[$at('Type')])->toBe('Replace')
        // Header fields are identical on both rows: that is what makes them
        // one requisition.
        ->and($second[$at('Requisition Number')])->toBe($first[$at('Requisition Number')]);
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

it('rejects unknown Section, Person, Approver and Category without creating them', function () {
    $item = issueItem();
    stockOnHand($item, 100);

    foreach ([
        ['Indent Section' => 'No Such Section', 'expect' => 'Indent Section', 'model' => IndentSection::class, 'name' => 'No Such Section'],
        ['Indent Person' => 'No Such Person', 'expect' => 'Indent Person', 'model' => IndentPerson::class, 'name' => 'No Such Person'],
        ['Approved By' => 'No Such Approver', 'expect' => 'Approved By', 'model' => IssueApprover::class, 'name' => 'No Such Approver'],
        ['Category' => 'No Such Category', 'expect' => 'Category', 'model' => ItemCategory::class, 'name' => 'No Such Category'],
    ] as $case) {
        $expect = $case['expect'];
        $model = $case['model'];
        $name = $case['name'];
        unset($case['expect'], $case['model'], $case['name']);

        $result = IssueImport::parse(issueSheet([
            issueRow($case + ['Issue Date*' => '2026-08-01', 'Item Name*' => 'Sewing Needle', 'Issued Qty*' => 1]),
        ]));

        expect($result['requisitions'])->toBeEmpty("{$expect} should have blocked the requisition");
        expect(implode(' ', $result['errors']))->toContain('is not in the '.$expect.' list');
        expect($model::where('name', $name)->exists())->toBeFalse("{$expect} must not be auto-created");
    }
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
    \Spatie\Permission\Models\Permission::findOrCreate('store.issues.view', 'web');
    \Spatie\Permission\Models\Permission::findOrCreate('store.issues.create', 'web');

    $user = \App\Models\User::factory()->create(['status' => 1]);
    $user->givePermissionTo(['store.issues.view', 'store.issues.create']);

    $csv = implode(',', IssueImport::COLUMNS)."\n"
        .'2026-08-01,,,,,REQ-77,Sewing Needle,,,12,,'."\n";

    $upload = fn () => $this->actingAs($user)->post(
        route('store.stock.issues.import'),
        ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('issues.csv', $csv)]
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

it('records imported issues through the controller, scoped by permission', function () {
    $item = issueItem();
    stockOnHand($item, 100);

    \Spatie\Permission\Models\Permission::findOrCreate('store.issues.view', 'web');
    \Spatie\Permission\Models\Permission::findOrCreate('store.issues.create', 'web');

    $viewer = \App\Models\User::factory()->create(['status' => 1]);
    $viewer->givePermissionTo('store.issues.view');

    $creator = \App\Models\User::factory()->create(['status' => 1]);
    $creator->givePermissionTo(['store.issues.view', 'store.issues.create']);

    $csv = implode(',', IssueImport::COLUMNS)."\n"
        .'2026-08-01,,,,,REQ-9,Sewing Needle,,,7,,'."\n";

    $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('issues.csv', $csv);

    // View-only cannot import.
    $this->actingAs($viewer)
        ->post(route('store.stock.issues.import'), ['file' => $file])
        ->assertForbidden();

    expect(StockIssue::count())->toBe(0);

    $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('issues.csv', $csv);

    $this->actingAs($creator)
        ->post(route('store.stock.issues.import'), ['file' => $file])
        ->assertRedirect();

    expect(StockIssue::count())->toBe(1);

    $issue = StockIssue::first();

    expect($issue->requisition_no)->toBe('REQ-9')
        ->and((float) $issue->qty)->toBe(7.0)
        ->and($issue->stock_item_id)->toBe($item->id);
});
