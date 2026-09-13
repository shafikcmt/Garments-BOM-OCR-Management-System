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

// --- Live SUBTOTAL totals in the workbook -------------------------------------

/**
 * Download the real workbook through the real route and hand back the sheet.
 *
 * Deliberately NOT Excel::fake() here: these assertions are about the bytes a
 * user actually opens — the formulas, their ranges and the AutoFilter are all
 * applied in the AfterSheet hook, which a faked writer never runs.
 */
function exportedSheet(array $query = []): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
{
    $response = test()->actingAs(exportUser())
        ->get(route('store.stock.ledger.excel', $query))
        ->assertOk()
        ->baseResponse;

    $raw = $response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse
        ? file_get_contents($response->getFile()->getPathname())
        : $response->getContent();

    $path = tempnam(sys_get_temp_dir(), 'stockreport').'.xlsx';
    file_put_contents($path, $raw);

    return \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getSheet(0);
}

/** The row carrying the headings, wherever the letterhead has pushed it to. */
function headerRowOf(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): ?int
{
    for ($row = 1; $row <= 40; $row++) {
        if (trim((string) $sheet->getCell('C'.$row)->getValue()) === 'Item Name') {
            return $row;
        }
    }

    return null;
}

it('writes live SUBTOTAL formulas above the five figure columns', function () {
    exportItem();

    $sheet = exportedSheet(['month' => '2026-08']);
    $header = headerRowOf($sheet);
    $strip = $header - 1;

    // SUBTOTAL(9, …), not SUM: 9 sums only the rows Excel's filter leaves
    // visible, which is the whole point of the client's format.
    expect($sheet->getCell('G'.$strip)->getValue())->toStartWith('=SUBTOTAL(9,G')
        ->and($sheet->getCell('L'.$strip)->getValue())->toStartWith('=SUBTOTAL(9,L')
        ->and($sheet->getCell('N'.$strip)->getValue())->toStartWith('=SUBTOTAL(9,N')
        ->and($sheet->getCell('P'.$strip)->getValue())->toStartWith('=SUBTOTAL(9,P')
        ->and($sheet->getCell('R'.$strip)->getValue())->toStartWith('=SUBTOTAL(9,R')
        // Named plainly: the Sub-Total row is filter-aware too now, so calling
        // this one "Visible Total" would imply a difference that is not there.
        ->and($sheet->getCell('F'.$strip)->getValue())->toBe('Total');
});

it('computes the same figures the report does', function () {
    // A formula that is syntactically fine but points at the wrong rows would
    // still open cleanly in Excel and quietly show the wrong number.
    exportItem();

    $sheet = exportedSheet(['month' => '2026-08']);
    $strip = headerRowOf($sheet) - 1;

    expect($sheet->getCell('G'.$strip)->getCalculatedValue())->toEqual(20.0)  // Opening
        ->and($sheet->getCell('N'.$strip)->getCalculatedValue())->toEqual(10.0)  // Consumption
        ->and($sheet->getCell('P'.$strip)->getCalculatedValue())->toEqual(10.0); // Stock as on
});

it('ranges the formulas over the real data rows, wherever the header lands', function () {
    // A Filters line or the not-counted Note pushes the header down. Hardcoding
    // the reference workbook's own row 4 / row 6294 would break on both.
    exportItem();

    $plain = exportedSheet(['month' => '2026-08']);
    $shifted = exportedSheet(['month' => '2026-08', 'category' => 'Stationery']);

    $plainHeader = headerRowOf($plain);
    $shiftedHeader = headerRowOf($shifted);

    // Whatever row the header is on, the range starts on the row after it.
    expect($plain->getCell('G'.($plainHeader - 1))->getValue())
        ->toContain('G'.($plainHeader + 1).':')
        ->and($shifted->getCell('G'.($shiftedHeader - 1))->getValue())
        ->toContain('G'.($shiftedHeader + 1).':');
});

it('makes the bottom Sub-Total row filter-aware too', function () {
    // Two totals that behave differently on one sheet is a support call. The
    // Blade still writes this row as static figures — the same partial renders
    // the PDF, which has no formula engine — so the conversion is workbook-only.
    exportItem();

    $sheet = exportedSheet(['month' => '2026-08']);
    $header = headerRowOf($sheet);

    $subTotal = null;
    for ($row = $header + 1; $row <= $sheet->getHighestDataRow(); $row++) {
        if (strcasecmp(trim((string) $sheet->getCell('A'.$row)->getValue()), 'Sub-Total') === 0) {
            $subTotal = $row;
            break;
        }
    }

    expect($subTotal)->not->toBeNull();

    // The three the Blade actually totals, now live.
    expect($sheet->getCell('L'.$subTotal)->getValue())->toStartWith('=SUBTOTAL(9,L')
        ->and($sheet->getCell('N'.$subTotal)->getValue())->toStartWith('=SUBTOTAL(9,N')
        ->and($sheet->getCell('R'.$subTotal)->getValue())->toStartWith('=SUBTOTAL(9,R')
        // ...and it still adds up.
        ->and($sheet->getCell('N'.$subTotal)->getCalculatedValue())->toEqual(10.0);

    // Opening Stock and Stock as on Date are left out of this row on purpose —
    // summing a brought-forward balance across unrelated items says nothing.
    // Writing a formula into those blanks would invent a total.
    expect($sheet->getCell('G'.$subTotal)->getValue())->toBeEmpty()
        ->and($sheet->getCell('P'.$subTotal)->getValue())->toBeEmpty();
});

it('enables AutoFilter over the header and data, excluding the Sub-Total row', function () {
    // SUBTOTAL is only useful beside a filter the reader can apply, and a
    // Sub-Total row inside the filter range would be treated as data.
    exportItem();

    $sheet = exportedSheet(['month' => '2026-08']);
    $header = headerRowOf($sheet);

    $range = $sheet->getAutoFilter()->getRange();

    expect($range)->toStartWith('A'.$header.':');

    // The last filtered row is the last data row — the row above Sub-Total.
    preg_match('/:([A-Z]+)(\d+)$/', $range, $m);
    $lastFiltered = (int) $m[2];

    expect(trim((string) $sheet->getCell('A'.($lastFiltered + 1))->getValue()))->toBe('Sub-Total');
});

it('writes no totals strip when the report has no rows', function () {
    // No Sub-Total row exists on an empty report, so there is nothing to range
    // over. It must degrade quietly rather than emit =SUBTOTAL(9,G9:G8).
    $sheet = exportedSheet(['month' => '2026-08', 'search' => 'zzz-no-such-item-zzz']);
    $header = headerRowOf($sheet);

    expect($sheet->getCell('G'.($header - 1))->getValue())->toBeEmpty()
        ->and($sheet->getAutoFilter()->getRange())->toBeEmpty();
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
        // The Excel button is the combined workbook now — the two Excel buttons
        // this screen used to carry were merged into one. The single-sheet
        // route it used to point at is still live and still exercised by the
        // export tests above; what the TOOLBAR offers is the workbook.
        ->assertSee(e(route('store.stock.ledger.excel.all', ['month' => '2026-08', 'category' => 'Stationery'])), false)
        ->assertSee(e(route('store.stock.ledger.pdf', ['month' => '2026-08', 'category' => 'Stationery'])), false);
});
