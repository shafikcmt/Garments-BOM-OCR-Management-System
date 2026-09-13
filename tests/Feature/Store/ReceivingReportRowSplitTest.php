<?php

use App\Models\StockItem;
use App\Models\StockPurchase;
use App\Models\User;
use App\Services\ReceivingReportService;
use Spatie\Permission\Models\Role;

/**
 * Receiving report — one table row per ITEM RECEIVED.
 *
 * A challan carrying three items used to print one row with the three names
 * stacked inside a single cell, which nobody could filter, sort or read across.
 * It now prints three rows, each repeating the delivery's GRN, challan, dates
 * and supplier, and each carrying that item's own UOM.
 *
 * Screen and PDF/Excel render from two different blades, so both are pinned —
 * a fix applied to only one of them is exactly the drift this checks for.
 *
 * In-memory sqlite. No real record touched.
 */

/** A three-item delivery and a one-item delivery, in the same month. */
function receivingSplitFixture(): void
{
    $needle = StockItem::create(['name' => 'Needle DBx1 90/14', 'uom' => 'Pcs', 'is_active' => true]);
    $oil = StockItem::create(['name' => 'Machine Oil', 'uom' => 'Ltr', 'is_active' => true]);
    $thread = StockItem::create(['name' => 'Sewing Thread 40/2', 'uom' => 'Cone', 'is_active' => true]);

    foreach ([[$needle, 10, 4], [$oil, 5, 200], [$thread, 25, 60]] as [$item, $qty, $price]) {
        StockPurchase::create([
            'stock_item_id' => $item->id,
            'supplier_name' => 'Star Traders',
            'purchase_date' => '2026-08-10',
            'rcv_date' => '2026-08-11',
            'challan_no' => 'CH-100',
            'rv_no' => 'RV-100',
            'qty' => $qty,
            'unit_price' => $price,
        ]);
    }

    StockPurchase::create([
        'stock_item_id' => $needle->id,
        'supplier_name' => 'Delta Supply',
        'purchase_date' => '2026-08-12',
        'rcv_date' => '2026-08-12',
        'challan_no' => 'CH-200',
        'rv_no' => 'RV-200',
        'qty' => 7,
        'unit_price' => 4,
    ]);
}

function receivingSplitUser(): User
{
    Role::findOrCreate('store', 'web');

    return User::factory()->create()->assignRole('store');
}

/** Rows of the report table, each as its list of cell texts. */
function receivingTableRows(string $html): array
{
    // The report table is the last one on the page; its body rows are the only
    // ones carrying a GRN number, which is enough to pick them out.
    preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/s', $html, $matches);

    $rows = [];

    foreach ($matches[1] as $row) {
        if (! str_contains($row, 'RV-')) {
            continue;
        }

        preg_match_all('/<td\b[^>]*>(.*?)<\/td>/s', $row, $cells);
        $rows[] = array_map(fn ($c) => trim(html_entity_decode(strip_tags($c))), $cells[1]);
    }

    return $rows;
}

it('gives every item on a challan its own row on the screen', function () {
    receivingSplitFixture();

    $html = $this->actingAs(receivingSplitUser())
        ->get(route('store.stock.purchases.report', ['month' => '2026-08']))
        ->assertOk()
        ->getContent();

    $rows = receivingTableRows($html);

    // Four item lines across two deliveries — not two rows.
    expect($rows)->toHaveCount(4);

    // The three-item challan repeats its GRN, challan, dates and supplier on
    // each of its rows, and each row names one item with its own UOM.
    $ch100 = array_values(array_filter($rows, fn ($r) => $r[1] === 'RV-100'));

    expect($ch100)->toHaveCount(3);
    expect(array_column($ch100, 6))
        ->toBe(['Needle DBx1 90/14', 'Machine Oil', 'Sewing Thread 40/2']);
    // UOM sits at 8 now: Brand/Specification was inserted after Item Name, and
    // Category after UOM, to match Purchase History's column set.
    expect(array_column($ch100, 8))->toBe(['Pcs', 'Ltr', 'Cone']);

    foreach ($ch100 as $row) {
        expect($row[2])->toBe('CH-100')
            ->and($row[3])->toBe('10-Aug-2026')
            ->and($row[4])->toBe('11-Aug-2026')
            ->and($row[5])->toBe('Star Traders');
    }

    // Each row carries its own quantity, not the delivery total. Received Qty
    // is column 10 since Brand/Specification and Category were added.
    expect(array_column($ch100, 10))->toBe(['10', '5', '25']);

    // And the footer still totals the whole filtered set: 10+5+25+7.
    expect($html)->toContain('>47<');
});

it('splits the same way on the PDF and Excel view, with a UOM column', function () {
    receivingSplitFixture();

    $data = app(ReceivingReportService::class)->reportPayload(['month' => '2026-08']);

    foreach ([false, true] as $forExcel) {
        $html = view('store.stock.receiving-report-pdf', $data + ['forExcel' => $forExcel])->render();
        $rows = receivingTableRows($html);

        expect($rows)->toHaveCount(4);
        expect($html)->toContain('>UOM<')->not->toContain('>Items<');

        // The same column set Purchase History carries.
        expect($html)->toContain('>Brand/Specification<')
            ->and($html)->toContain('>Category<')
            ->and($html)->toContain('>Received Qty<')
            ->and($html)->toContain('>Received Value<');

        $ch100 = array_values(array_filter($rows, fn ($r) => $r[1] === 'RV-100'));

        expect(array_column($ch100, 8))->toBe(['Pcs', 'Ltr', 'Cone']);
        expect(array_column($ch100, 10))->toBe(['10', '5', '25']);
        expect(array_column($ch100, 2))->toBe(['CH-100', 'CH-100', 'CH-100']);
    }
});
