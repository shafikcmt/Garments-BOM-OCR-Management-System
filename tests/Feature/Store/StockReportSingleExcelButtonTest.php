<?php

use App\Models\StockIssue;
use App\Models\StockItem;
use App\Models\User;
use App\Services\IssueReportService;
use Spatie\Permission\Models\Role;

/**
 * Stock Report toolbar — ONE Excel button, and it is the combined workbook.
 *
 * There used to be two: a single-sheet "Download Excel" beside "Download Excel
 * (3 Reports)". The workbook is a superset of the single sheet, so the toolbar
 * now offers only the workbook. The single-sheet ROUTE is deliberately still
 * alive — StockReportExportFiltersTest still covers it — so this pins the
 * button, not the route.
 *
 * Also pins the UOM column on the Issues report, which is tab 3 of that
 * workbook: it prints a quantity per issue line and used to name no unit.
 *
 * In-memory sqlite. No real record touched.
 */

function singleExcelUser(): User
{
    Role::findOrCreate('store', 'web');

    return User::factory()->create()->assignRole('store');
}

it('offers one Excel button on the Stock Report, pointing at the workbook', function () {
    $html = $this->actingAs(singleExcelUser())
        ->get(route('store.stock.ledger', ['month' => '2026-08']))
        ->assertOk()
        ->getContent();

    // The old second button is gone, label and all.
    expect($html)->not->toContain('Download Excel (3 Reports)');

    // Exactly one Excel button is left...
    expect(substr_count($html, '>Download Excel'))->toBe(1);

    // ...and it downloads the three-tab workbook, carrying the filter the page
    // was opened with.
    expect($html)->toContain(route('store.stock.ledger.excel.all', ['month' => '2026-08']));

    // Its neighbours are untouched.
    expect($html)->toContain(route('store.stock.ledger.pdf', ['month' => '2026-08']))
        ->and($html)->toContain('Download PDF');
});

it('names the unit on every issue line, on screen and in the workbook tab', function () {
    $item = StockItem::create(['name' => 'Sewing Thread 40/2', 'uom' => 'Cone', 'is_active' => true]);

    StockIssue::create([
        'stock_item_id' => $item->id,
        'issue_date' => '2026-08-15',
        'qty' => 25,
    ]);

    $user = singleExcelUser();

    // The screen.
    $screen = $this->actingAs($user)
        ->get(route('store.stock.issues.report', ['month' => '2026-08']))
        ->assertOk()
        ->getContent();

    expect($screen)->toContain('>UOM<')->toContain('Cone');

    // The PDF and the Excel tab, from the one blade both are rendered from.
    $data = app(IssueReportService::class)->reportPayload(['month' => '2026-08']);

    foreach ([false, true] as $forExcel) {
        $html = view('store.stock.issue-report-pdf', $data + ['forExcel' => $forExcel])->render();

        expect($html)->toContain('>UOM<')->toContain('Cone');
    }
});
