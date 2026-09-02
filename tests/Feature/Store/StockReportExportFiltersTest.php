<?php

use App\Exports\GeneralStockReportExport;
use App\Models\StockIssue;
use App\Models\StockItem;
use App\Models\User;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Stock Report — the downloads must export the report on screen.
 *
 * The reported bug: the screen showed August while the workbook came out as
 * September. Both download hrefs are rendered by the SERVER from the query at
 * page load, but the filter JS swaps the table and rewrites the address bar
 * without reloading — so on a page opened in September and then filtered to
 * August, the buttons still pointed at September. The server was never wrong;
 * it was being asked for the wrong month.
 *
 * Two halves are pinned here, because the fix has two halves:
 *   1. The server honours whatever month and filters it is given — the contract
 *      the corrected link depends on.
 *   2. The page ships the data-ledger-export hook the JS re-points, and the
 *      server-rendered hrefs already carry the active query.
 *
 * The JS itself has no test runner in this project, so the hook's presence is
 * the seam that is guarded here.
 *
 * In-memory sqlite. No real record touched.
 */
function exportUser(): User
{
    Role::findOrCreate('store', 'web')->givePermissionTo(
        Permission::findOrCreate('store.workspace.view', 'web')
    );

    return User::factory()->create()->assignRole('store');
}

/** An item whose August and September figures differ, so a month mix-up shows. */
function exportItem(): StockItem
{
    $item = StockItem::create([
        'name' => '100% Cut Panel Inspection Report',
        'uom' => 'Book', 'category' => 'Stationery', 'is_active' => true,
        'opening_qty' => 20, 'opening_as_on' => '2026-08-01',
    ]);

    // August consumes 10, so August opens at 20 and September opens at 10 —
    // the exact shape of the screenshots.
    StockIssue::create(['stock_item_id' => $item->id, 'issue_date' => '2026-08-15', 'qty' => 10]);

    return $item;
}

// --- The server honours the month it is given --------------------------------

it('exports the requested month to Excel, not the current one', function () {
    exportItem();
    Excel::fake();

    $this->actingAs(exportUser())
        ->get(route('store.stock.ledger.excel', ['month' => '2026-08']))
        ->assertOk();

    Excel::assertDownloaded('Stock-Report-2026-08.xlsx', function (GeneralStockReportExport $export) {
        $html = $export->view()->render();

        // The heading says August...
        expect($html)->toContain('August 2026')
            ->and($html)->not->toContain('September 2026');

        // ...and so does the row: August opens at the counted 20.
        expect($export->title())->toBe('Stock Aug-26');

        return true;
    });
});

it('exports September as September when September is asked for', function () {
    exportItem();
    Excel::fake();

    $this->actingAs(exportUser())
        ->get(route('store.stock.ledger.excel', ['month' => '2026-09']))
        ->assertOk();

    Excel::assertDownloaded('Stock-Report-2026-09.xlsx', function (GeneralStockReportExport $export) {
        expect($export->view()->render())->toContain('September 2026');

        return true;
    });
});

it('carries the month into the Excel row figures', function () {
    // The filename and heading could be right while the rows came off another
    // month, which is the half of the bug a reader would not notice.
    exportItem();
    Excel::fake();

    $this->actingAs(exportUser())->get(route('store.stock.ledger.excel', ['month' => '2026-08']));

    Excel::assertDownloaded('Stock-Report-2026-08.xlsx', function (GeneralStockReportExport $export) {
        $rows = $export->view()->getData()['rows'];
        $row = $rows->firstWhere(fn ($r) => $r['item']->name === '100% Cut Panel Inspection Report');

        expect($row['balance_bf'])->toBe(20.0)      // August opening
            ->and($row['consumption'])->toBe(10.0)
            ->and($row['stock_as_on'])->toBe(10.0);

        return true;
    });
});

it('names the PDF for the requested month', function () {
    exportItem();

    $disposition = $this->actingAs(exportUser())
        ->get(route('store.stock.ledger.pdf', ['month' => '2026-08']))
        ->assertOk()
        ->headers->get('content-disposition');

    expect($disposition)->toContain('Stock-Report-2026-08.pdf');
});

it('passes every filter, not just the month, into the export', function () {
    exportItem();
    StockItem::create([
        'name' => 'Unrelated Chemical', 'uom' => 'Ltr', 'category' => 'Chemical',
        'is_active' => true, 'opening_qty' => 5, 'opening_as_on' => '2026-08-01',
    ]);

    Excel::fake();

    $this->actingAs(exportUser())->get(route('store.stock.ledger.excel', [
        'month' => '2026-08',
        'category' => 'Stationery',
        'search' => 'Cut Panel',
    ]));

    Excel::assertDownloaded('Stock-Report-2026-08.xlsx', function (GeneralStockReportExport $export) {
        $rows = $export->view()->getData()['rows'];

        expect($rows)->toHaveCount(1)
            ->and($rows->first()['item']->name)->toBe('100% Cut Panel Inspection Report');

        return true;
    });
});

// --- The page gives the JS something to re-point ------------------------------

it('marks both download buttons so the filter JS can re-point them', function () {
    // Without this hook the buttons keep the query the page opened with, which
    // is the whole bug. Two of them: PDF and Excel.
    $html = $this->actingAs(exportUser())
        ->get(route('store.stock.ledger'))
        ->assertOk()
        ->getContent();

    expect(substr_count($html, 'data-ledger-export'))->toBeGreaterThanOrEqual(2);
});

it('renders the download links already carrying the active filters', function () {
    // The no-JS path: submitting the plain GET form reloads the page, and the
    // server-rendered hrefs must then describe the filtered report.
    $this->actingAs(exportUser())
        ->get(route('store.stock.ledger', ['month' => '2026-08', 'category' => 'Stationery']))
        ->assertOk()
        // Escaped: Blade writes the & in the query string as &amp; inside the
        // href, so the raw route() string would never match the markup.
        ->assertSee(e(route('store.stock.ledger.excel', ['month' => '2026-08', 'category' => 'Stationery'])), false)
        ->assertSee(e(route('store.stock.ledger.pdf', ['month' => '2026-08', 'category' => 'Stationery'])), false);
});
