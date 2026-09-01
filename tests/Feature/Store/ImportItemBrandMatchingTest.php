<?php

use App\Imports\IssueImport;
use App\Imports\ReceivingImport;
use App\Models\StockItem;
use App\Models\StockPurchase;
use App\Support\StockItemLookup;

/**
 * Which item master row a bulk-upload line actually posts to.
 *
 * THE BUG THESE PIN. Both importers resolved an item with
 * `StockItem::get()->keyBy(name)`, and `keyBy` keeps only the LAST row on a
 * duplicate key. Duplicate names are deliberate — ItemMasterImport dedups on
 * name AND brand so one part can be stocked under several makes — so one of
 * every duplicate pair was unreachable and every line naming it posted its
 * quantity to whichever row survived. Wrong item, no error, and the figure goes
 * on to feed the Stock Report and the re-order levels.
 *
 * On the live master this is 107 names of 1241, almost all of them a "Generic"
 * row beside a real make (Organ, Groz-Beckert), so it is a live data-integrity
 * fault rather than a theoretical one.
 *
 * Both importers are asserted from one file because they must not drift: they
 * were the same copy when they were both wrong, and a fix applied to one is
 * worth nothing on a store that uploads through the other.
 *
 * In-memory sqlite. No real record touched.
 */
function bmItem(string $name, ?string $brand, string $uom = 'Pkt'): StockItem
{
    return StockItem::create([
        'name' => $name,
        'brand' => $brand,
        'uom' => $uom,
        'category' => 'Needle',
        'opening_qty' => 0,
    ]);
}

/** Issue lines need something to draw down, or affordability rejects them first. */
function bmStock(StockItem $item, float $qty): StockPurchase
{
    return StockPurchase::create([
        'stock_item_id' => $item->id,
        'purchase_date' => now()->startOfMonth()->toDateString(),
        'rcv_date' => now()->startOfMonth()->toDateString(),
        'qty' => $qty,
        'unit_price' => 1,
    ]);
}

/** @param array<string, mixed> $values */
function bmReceiving(array $values): array
{
    $row = array_fill(0, count(ReceivingImport::COLUMNS), null);

    foreach ($values as $heading => $value) {
        $i = array_search($heading, ReceivingImport::COLUMNS, true);
        expect($i)->not->toBeFalse("unknown column: {$heading}");
        $row[$i] = $value;
    }

    return $row;
}

/** @param array<string, mixed> $values */
function bmIssue(array $values): array
{
    $row = array_fill(0, count(IssueImport::COLUMNS), null);

    foreach ($values as $heading => $value) {
        $i = array_search($heading, IssueImport::COLUMNS, true);
        expect($i)->not->toBeFalse("unknown column: {$heading}");
        $row[$i] = $value;
    }

    return $row;
}

/** Every stock_item_id the parsed deliveries would write, in file order. */
function bmReceivedIds(array $result): array
{
    $ids = [];

    foreach ($result['challans'] as $challan) {
        foreach ($challan['lines'] as $line) {
            $ids[] = $line['stock_item_id'];
        }
    }

    return $ids;
}

/*
|--------------------------------------------------------------------------
| The fix: name + brand, on both importers
|--------------------------------------------------------------------------
*/

it('posts two brands of one name to their own items on receiving', function () {
    $generic = bmItem('B/S Needle DPX17-09', 'Generic');
    $organ = bmItem('B/S Needle DPX17-09', 'Organ');

    $result = ReceivingImport::parse(array_merge([ReceivingImport::COLUMNS], [
        bmReceiving([
            'Challan Date*' => '2026-08-01',
            'Challan No/Invoice No' => 'CH-1',
            'Item Name*' => 'B/S Needle DPX17-09',
            'Brand/Specification' => 'Generic',
            'Purchased Qty*' => 10,
        ]),
        bmReceiving([
            'Challan Date*' => '2026-08-02',
            'Challan No/Invoice No' => 'CH-2',
            'Item Name*' => 'B/S Needle DPX17-09',
            'Brand/Specification' => 'Organ',
            'Purchased Qty*' => 7,
        ]),
    ]));

    expect($result['errors'])->toBeEmpty();

    // The whole point: two different ids, each the one the file named. Before
    // the fix BOTH rows resolved to whichever survived keyBy.
    expect(bmReceivedIds($result))->toBe([$generic->id, $organ->id]);
});

it('issues two brands of one name against their own items', function () {
    $generic = bmItem('B/S Needle DPX17-09', 'Generic');
    $organ = bmItem('B/S Needle DPX17-09', 'Organ');

    bmStock($generic, 100);
    bmStock($organ, 100);

    $result = IssueImport::parse(array_merge([IssueImport::COLUMNS], [
        bmIssue([
            'Issue Date*' => now()->startOfMonth()->toDateString(),
            'Requisition Number' => 'RQ-1',
            'Item Name*' => 'B/S Needle DPX17-09',
            'Brand/Specification' => 'Organ',
            'Issued Qty*' => 3,
        ]),
    ]));

    expect($result['errors'])->toBeEmpty();

    $lines = collect($result['requisitions'])->flatMap(fn ($r) => $r['lines']);

    expect($lines->pluck('stock_item_id')->all())->toBe([$organ->id])
        ->and($lines->pluck('stock_item_id')->all())->not->toContain($generic->id);
});

it('matches case and spacing loosely on the brand, as the item master does', function () {
    $organ = bmItem('B/S Needle DPX17-09', 'Organ');
    bmItem('B/S Needle DPX17-09', 'Generic');

    $result = ReceivingImport::parse(array_merge([ReceivingImport::COLUMNS], [
        bmReceiving([
            'Challan Date*' => '2026-08-01',
            'Item Name*' => 'B/S Needle DPX17-09',
            'Brand/Specification' => '  ORGAN  ',
            'Purchased Qty*' => 5,
        ]),
    ]));

    expect($result['errors'])->toBeEmpty()
        ->and(bmReceivedIds($result))->toBe([$organ->id]);
});

/*
|--------------------------------------------------------------------------
| No regression: the ordinary single-item case is untouched
|--------------------------------------------------------------------------
*/

it('still matches a unique name whose brand agrees', function () {
    $item = bmItem('Sewing Needle', 'Groz-Beckert DBx1 90/14');

    $result = ReceivingImport::parse(array_merge([ReceivingImport::COLUMNS], [
        bmReceiving([
            'Challan Date*' => '2026-08-01',
            'Item Name*' => 'Sewing Needle',
            'Brand/Specification' => 'Groz-Beckert DBx1 90/14',
            'Purchased Qty*' => 4,
        ]),
    ]));

    expect($result['errors'])->toBeEmpty()
        ->and($result['notes'])->toBeEmpty()
        ->and(bmReceivedIds($result))->toBe([$item->id]);
});

/**
 * RUNG 2, and the reason the fix is a ladder rather than a straight swap. The
 * legacy workbooks write brand text freely — "Organ Needle Co." against a
 * master holding "Organ" — and only one item bears the name, so there is
 * nothing to be ambiguous about. Refusing these would reject deliveries that
 * are not in doubt.
 */
it('still imports a unique name whose brand is worded differently, with a note', function () {
    $item = bmItem('Sewing Needle', 'Organ');

    $result = ReceivingImport::parse(array_merge([ReceivingImport::COLUMNS], [
        bmReceiving([
            'Challan Date*' => '2026-08-01',
            'Item Name*' => 'Sewing Needle',
            'Brand/Specification' => 'Organ Needle Co.',
            'Purchased Qty*' => 4,
        ]),
    ]));

    expect($result['errors'])->toBeEmpty()
        ->and(bmReceivedIds($result))->toBe([$item->id])
        ->and(implode(' | ', $result['notes']))
        ->toContain('Brand/Specification in the file is "Organ Needle Co."');
});

it('still reports a name that is in no item at all', function () {
    bmItem('Sewing Needle', 'Organ');

    $result = ReceivingImport::parse(array_merge([ReceivingImport::COLUMNS], [
        bmReceiving([
            'Challan Date*' => '2026-08-01',
            'Item Name*' => 'Nothing Like This',
            'Purchased Qty*' => 4,
        ]),
    ]));

    expect($result['challans'])->toBeEmpty()
        ->and(implode(' | ', $result['errors']))->toContain('is not in the item master');
});

/*
|--------------------------------------------------------------------------
| Blank brand is a value, never a wildcard
|--------------------------------------------------------------------------
*/

it('matches a blank brand to the blank-brand item of that name', function () {
    $blank = bmItem('Marking Chalk', null);

    $result = ReceivingImport::parse(array_merge([ReceivingImport::COLUMNS], [
        bmReceiving([
            'Challan Date*' => '2026-08-01',
            'Item Name*' => 'Marking Chalk',
            'Purchased Qty*' => 6,
        ]),
    ]));

    expect($result['errors'])->toBeEmpty()
        ->and(bmReceivedIds($result))->toBe([$blank->id]);
});

/**
 * The strict rule doing its job. Permissive, this line would match both branded
 * items and the importer would pick one — which is the original bug wearing a
 * different hat.
 */
it('refuses a blank brand when the name is held under several brands', function () {
    bmItem('B/S Needle DPX17-09', 'Generic');
    bmItem('B/S Needle DPX17-09', 'Organ');

    $result = ReceivingImport::parse(array_merge([ReceivingImport::COLUMNS], [
        bmReceiving([
            'Challan Date*' => '2026-08-01',
            'Item Name*' => 'B/S Needle DPX17-09',
            'Purchased Qty*' => 6,
        ]),
    ]));

    expect($result['challans'])->toBeEmpty();

    $errors = implode(' | ', $result['errors']);

    expect($errors)->toContain('is held under more than one brand')
        ->and($errors)->toContain('Generic')
        ->and($errors)->toContain('Organ');
});

/*
|--------------------------------------------------------------------------
| Rung 3: refused, never silently posted
|--------------------------------------------------------------------------
*/

it('refuses a receiving line whose brand names no item of that name', function () {
    bmItem('B/S Needle DPX17-09', 'Generic');
    bmItem('B/S Needle DPX17-09', 'Organ');

    $result = ReceivingImport::parse(array_merge([ReceivingImport::COLUMNS], [
        bmReceiving([
            'Challan Date*' => '2026-08-01',
            'Item Name*' => 'B/S Needle DPX17-09',
            'Brand/Specification' => 'Schmetz',
            'Purchased Qty*' => 6,
        ]),
    ]));

    // Refused rather than posted to one of them at random.
    expect($result['challans'])->toBeEmpty()
        ->and(implode(' | ', $result['errors']))->toContain('is held under more than one brand');
});

it('leaves the other deliveries in the file importable when one is refused', function () {
    bmItem('B/S Needle DPX17-09', 'Generic');
    bmItem('B/S Needle DPX17-09', 'Organ');
    $clean = bmItem('Sewing Needle', 'Organ');

    $result = ReceivingImport::parse(array_merge([ReceivingImport::COLUMNS], [
        bmReceiving([
            'Challan Date*' => '2026-08-01',
            'Challan No/Invoice No' => 'CH-BAD',
            'Item Name*' => 'B/S Needle DPX17-09',
            'Purchased Qty*' => 6,
        ]),
        bmReceiving([
            'Challan Date*' => '2026-08-02',
            'Challan No/Invoice No' => 'CH-GOOD',
            'Item Name*' => 'Sewing Needle',
            'Brand/Specification' => 'Organ',
            'Purchased Qty*' => 9,
        ]),
    ]));

    // A delivery is the unit of failure, so the good one still lands.
    expect(bmReceivedIds($result))->toBe([$clean->id])
        ->and(implode(' | ', $result['errors']))->toContain('CH-BAD');
});

it('refuses an ambiguous issue line and says why on the skipped row', function () {
    $generic = bmItem('B/S Needle DPX17-09', 'Generic');
    $organ = bmItem('B/S Needle DPX17-09', 'Organ');

    bmStock($generic, 100);
    bmStock($organ, 100);

    $result = IssueImport::parse(array_merge([IssueImport::COLUMNS], [
        bmIssue([
            'Issue Date*' => now()->startOfMonth()->toDateString(),
            'Requisition Number' => 'RQ-1',
            'Item Name*' => 'B/S Needle DPX17-09',
            'Issued Qty*' => 3,
        ]),
    ]));

    expect($result['requisitions'])->toBeEmpty()
        ->and(implode(' | ', $result['errors']))->toContain('is held under more than one brand');

    // The downloadable skipped-rows sheet has to carry the reason too, or the
    // store is told to fix a file without being told what to fix.
    $reasons = collect($result['skipped_rows'])->pluck('reason')->implode(' | ');

    expect($reasons)->toContain('is held under more than one brand');
});

/**
 * The master holding true duplicates — same name, same brand — cannot be
 * resolved by anything typed in the sheet, so the message points at Items
 * rather than at the Brand column. "probe" is three such rows on the live data.
 */
it('refuses a line matching several identical items and points at Items', function () {
    bmItem('probe', null);
    bmItem('probe', null);

    $result = ReceivingImport::parse(array_merge([ReceivingImport::COLUMNS], [
        bmReceiving([
            'Challan Date*' => '2026-08-01',
            'Item Name*' => 'probe',
            'Purchased Qty*' => 2,
        ]),
    ]));

    expect($result['challans'])->toBeEmpty();

    $errors = implode(' | ', $result['errors']);

    expect($errors)->toContain('matches more than one item in the item master')
        ->and($errors)->toContain('Remove the duplicate under Items first');
});

/*
|--------------------------------------------------------------------------
| The shared identity rule
|--------------------------------------------------------------------------
*/

it('treats a blank brand as a value and collapses whitespace, on one definition', function () {
    // Blank is an empty half, compared like any other value.
    expect(StockItemLookup::identity('Probe', null))->toBe('probe|')
        ->and(StockItemLookup::identity('Probe', ''))->toBe('probe|');

    // A double space is a typing slip, not a different part — the rule
    // ItemMasterImport has always applied when refusing to create a second row.
    expect(StockItemLookup::identity('Circuit  Breaker', 'ABB'))
        ->toBe(StockItemLookup::identity(' circuit breaker ', ' abb '));

    // Different brands are different items, which is the whole fix.
    expect(StockItemLookup::identity('Needle', 'Organ'))
        ->not->toBe(StockItemLookup::identity('Needle', 'Generic'));
});
