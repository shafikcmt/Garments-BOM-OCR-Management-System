<?php

use App\Exports\GeneralStockCombinedExport;
use App\Exports\GeneralStockReportExport;
use App\Exports\IssueReportExport;
use App\Exports\ReceivingReportExport;
use App\Models\GeneralStockSupplier;
use App\Models\ItemCategory;
use App\Models\StockIssue;
use App\Models\StockItem;
use App\Models\StockPurchase;
use App\Models\User;
use App\Services\IssueReportService;
use App\Services\ReceivingReportService;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * "Download Excel (3 Reports)" — Stock, Receiving and Issues in one workbook.
 *
 * The workbook composes only: each tab is the same export class its own screen
 * downloads. So what is worth pinning here is not the spreadsheet, it is the
 * two things composition cannot answer by itself —
 *
 *   1. WHICH SHEETS a user may receive. The three reports sit behind three
 *      different section guards, and this download must never become the way
 *      around store.receiving.view or store.issues.view.
 *   2. HOW a filter written for the Stock Report is read against receipts and
 *      issues. Category is a string here and a foreign key there; supplier is
 *      an id here and a fuzzy name there; and some filters cannot cross at all.
 *
 * In-memory sqlite. No real record touched.
 */

/**
 * A user carrying exactly the named General Stock permissions and NO role, so
 * each section guard is tested on its own. The `store` role would satisfy all
 * three at once and prove nothing.
 *
 * @param  list<string>  $permissions
 */
function combinedUser(array $permissions): User
{
    $role = Role::findOrCreate('stock-viewer', 'web');

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return User::factory()->create()->assignRole('stock-viewer');
}

/** Someone who holds all three sections, via the plain store role. */
function combinedStoreUser(): User
{
    Role::findOrCreate('store', 'web');

    return User::factory()->create()->assignRole('store');
}

/**
 * One item, one receipt and one issue in August 2026, all in the same category
 * and from the same supplier — so a single filter can be followed across all
 * three sheets.
 *
 * @return array{item: StockItem, supplier: GeneralStockSupplier, category: ItemCategory}
 */
function combinedFixture(): array
{
    $category = ItemCategory::create(['name' => 'Machine Needles']);
    $supplier = GeneralStockSupplier::create(['name' => 'Star Traders']);

    $item = StockItem::create([
        'name' => 'Needle DBx1 90/14',
        'uom' => 'Pcs',
        // BOTH ways of saying the category. The Stock Report filters the
        // string; the Issue report filters the foreign key.
        'category' => 'Machine Needles',
        'item_category_id' => $category->id,
        'is_active' => true,
        'opening_qty' => 100,
        'opening_as_on' => '2026-08-01',
    ]);

    StockPurchase::create([
        'stock_item_id' => $item->id,
        'general_stock_supplier_id' => $supplier->id,
        'supplier_name' => 'Star Traders',
        'purchase_date' => '2026-08-10',
        'rcv_date' => '2026-08-11',
        'challan_no' => 'CH-100',
        'rv_no' => 'RV-100',
        'qty' => 50,
        'unit_price' => 4,
    ]);

    StockIssue::create([
        'stock_item_id' => $item->id,
        'item_category_id' => $category->id,
        'issue_date' => '2026-08-15',
        'qty' => 20,
    ]);

    return ['item' => $item, 'supplier' => $supplier, 'category' => $category];
}

/**
 * The sheet objects the workbook was built from, in order.
 *
 * @return list<object>
 */
function combinedSheets(): array
{
    $captured = [];

    // Matched by pattern, because the filename carries the period and these
    // tests use several. The exact name is asserted on its own below.
    Excel::matchByRegex();

    Excel::assertDownloaded(
        '/^Stock-Report-.*-All\.xlsx$/',
        function (GeneralStockCombinedExport $export) use (&$captured) {
            $captured = $export->sheets();

            return true;
        }
    );

    return $captured;
}

/** @param list<object> $sheets */
function sheetClasses(array $sheets): array
{
    return array_map(fn ($sheet) => class_basename($sheet), $sheets);
}

// --- 1. Which sheets a user may receive --------------------------------------

it('gives a full-access user all three sheets, in order', function () {
    combinedFixture();
    Excel::fake();

    $this->actingAs(combinedStoreUser())
        ->get(route('store.stock.ledger.excel.all', ['month' => '2026-08']))
        ->assertOk();

    expect(sheetClasses(combinedSheets()))->toBe([
        'GeneralStockReportExport',
        'ReceivingReportExport',
        'IssueReportExport',
    ]);
});

it('gives a stock-only user the Stock sheet alone', function () {
    // The whole point of the per-sheet gate: this user can open the Stock
    // Report screen and nothing else, so the workbook must not hand them
    // receipts and issues they cannot otherwise reach.
    combinedFixture();
    Excel::fake();

    $this->actingAs(combinedUser(['store.stock_report.view']))
        ->get(route('store.stock.ledger.excel.all', ['month' => '2026-08']))
        ->assertOk();

    expect(sheetClasses(combinedSheets()))->toBe(['GeneralStockReportExport']);
});

it('gives a stock plus receiving user two sheets and no Issues', function () {
    combinedFixture();
    Excel::fake();

    $this->actingAs(combinedUser(['store.stock_report.view', 'store.receiving.view']))
        ->get(route('store.stock.ledger.excel.all', ['month' => '2026-08']))
        ->assertOk();

    expect(sheetClasses(combinedSheets()))->toBe([
        'GeneralStockReportExport',
        'ReceivingReportExport',
    ]);
});

it('gives a stock plus issues user the Issues sheet and no Receiving', function () {
    combinedFixture();
    Excel::fake();

    $this->actingAs(combinedUser(['store.stock_report.view', 'store.issues.view']))
        ->get(route('store.stock.ledger.excel.all', ['month' => '2026-08']))
        ->assertOk();

    expect(sheetClasses(combinedSheets()))->toBe([
        'GeneralStockReportExport',
        'IssueReportExport',
    ]);
});

it('refuses the route outright to someone without the stock report section', function () {
    combinedFixture();

    $this->actingAs(combinedUser(['store.items.view']))
        ->get(route('store.stock.ledger.excel.all', ['month' => '2026-08']))
        ->assertForbidden();
});

// --- 2. A withheld sheet is named, never silently missing --------------------

it('names the withheld sheets on the Stock sheet', function () {
    // A workbook quietly short of a tab reads as "there were no receipts that
    // month". It has to say so on its own face.
    combinedFixture();
    Excel::fake();

    $this->actingAs(combinedUser(['store.stock_report.view']))
        ->get(route('store.stock.ledger.excel.all', ['month' => '2026-08']))
        ->assertOk();

    $stock = combinedSheets()[0];
    $html = $stock->view()->render();

    expect($html)->toContain('Not included')
        ->toContain('Receiving')
        ->toContain('Issues')
        ->toContain('does not have access');
});

it('says nothing about omissions when every sheet is present', function () {
    combinedFixture();
    Excel::fake();

    $this->actingAs(combinedStoreUser())
        ->get(route('store.stock.ledger.excel.all', ['month' => '2026-08']))
        ->assertOk();

    expect(combinedSheets()[0]->view()->render())->not->toContain('Not included');
});

// --- 3. Filter translation ---------------------------------------------------

it('translates the Stock category string into the Issue report category id', function () {
    // The two reports store the same vocabulary two ways: stock_items.category
    // is a string, stock_issues.item_category_id is a foreign key.
    $f = combinedFixture();
    Excel::fake();

    $this->actingAs(combinedStoreUser())
        ->get(route('store.stock.ledger.excel.all', [
            'month' => '2026-08',
            'category' => 'Machine Needles',
        ]))
        ->assertOk();

    $sheets = combinedSheets();
    $issues = collect($sheets)->first(fn ($s) => $s instanceof IssueReportExport);

    $filters = $issues->view()->getData()['filters'];

    expect($filters['category'])->toBe($f['category']->id)
        // The id resolved, so the sheet can name it — a consumption figure
        // narrowed to one category must admit that it is narrowed.
        ->and($issues->view()->render())->toContain('Machine Needles');
});

it('translates the Stock supplier id into the Receiving supplier name', function () {
    combinedFixture();
    Excel::fake();

    $supplier = GeneralStockSupplier::first();

    $this->actingAs(combinedStoreUser())
        ->get(route('store.stock.ledger.excel.all', [
            'month' => '2026-08',
            'supplier_id' => $supplier->id,
        ]))
        ->assertOk();

    $receiving = collect(combinedSheets())->first(fn ($s) => $s instanceof ReceivingReportExport);

    expect($receiving->view()->getData()['filters']['supplier'])->toBe('Star Traders');
});

it('drops the filters that cannot cross to receipts and issues', function () {
    // status and include_inactive describe an ITEM's stock position, which a
    // delivery and an issue line do not have. Supplier cannot reach Issues at
    // all: stock_issues carries no vendor.
    combinedFixture();
    Excel::fake();

    $supplier = GeneralStockSupplier::first();

    $this->actingAs(combinedStoreUser())
        ->get(route('store.stock.ledger.excel.all', [
            'month' => '2026-08',
            'status' => 'ok',
            'include_inactive' => 1,
            'supplier_id' => $supplier->id,
            'category' => 'Machine Needles',
        ]))
        ->assertOk();

    $sheets = collect(combinedSheets());
    $receiving = $sheets->first(fn ($s) => $s instanceof ReceivingReportExport)->view()->getData()['filters'];
    $issues = $sheets->first(fn ($s) => $s instanceof IssueReportExport)->view()->getData()['filters'];

    expect($receiving)->not->toHaveKey('status')
        ->and($receiving)->not->toHaveKey('include_inactive')
        // Receiving has no category filter at all.
        ->and($receiving)->not->toHaveKey('category')
        ->and($issues)->not->toHaveKey('status')
        ->and($issues)->not->toHaveKey('include_inactive')
        // Issues can never be supplier-filtered.
        ->and($issues)->not->toHaveKey('supplier');
});

it('carries month, dates and search through to both sheets unchanged', function () {
    combinedFixture();
    Excel::fake();

    $this->actingAs(combinedStoreUser())
        ->get(route('store.stock.ledger.excel.all', [
            'from' => '2026-08-01',
            'to' => '2026-08-31',
            'search' => 'needle',
        ]))
        ->assertOk();

    $sheets = collect(combinedSheets());

    foreach ([ReceivingReportExport::class, IssueReportExport::class] as $class) {
        $filters = $sheets->first(fn ($s) => $s instanceof $class)->view()->getData()['filters'];

        expect($filters['from'])->toBe('2026-08-01')
            ->and($filters['to'])->toBe('2026-08-31')
            ->and($filters['search'])->toBe('needle');
    }
});

// --- 4. The payload extraction did not change the standalone reports ---------

/**
 * The refactor moved the report half of build() out of the two controllers and
 * into their services. These pin the property that made that safe: the screen
 * and the service must produce the SAME report, so the combined workbook and
 * the standalone download cannot drift apart.
 */
it('feeds the Receiving screen the same report the service builds', function () {
    combinedFixture();

    $filters = ['month' => '2026-08'];

    $fromService = app(ReceivingReportService::class)->reportPayload($filters);

    $viewData = $this->actingAs(combinedStoreUser())
        ->get(route('store.stock.purchases.report', $filters))
        ->assertOk()
        ->viewData('rows');

    expect($viewData->toArray())->toEqual($fromService['rows']->toArray());
});

it('feeds the Issue screen the same report the service builds', function () {
    combinedFixture();

    $filters = ['month' => '2026-08'];

    $fromService = app(IssueReportService::class)->reportPayload($filters);

    $viewData = $this->actingAs(combinedStoreUser())
        ->get(route('store.stock.issues.report', $filters))
        ->assertOk()
        ->viewData('rows');

    expect($viewData->pluck('id')->all())->toEqual($fromService['rows']->pluck('id')->all());
});

/**
 * The strongest form of the non-regression check that can live in the suite.
 *
 * Proving equivalence with the PRE-refactor code needs the pre-refactor code,
 * so that comparison was run once, from git, across 18 filter combinations —
 * it cannot be a committed test, because the old code is gone. What CAN be
 * pinned forever is the property that made the extraction safe and that a
 * future edit could silently break: the standalone screen and the combined
 * workbook must render the same report, byte for byte, from the same filters.
 * If anyone ever reintroduces payload assembly in a controller, this fails.
 */
it('renders byte-identical Receiving output from the screen and the workbook paths', function () {
    combinedFixture();

    foreach ([[], ['month' => '2026-08'], ['from' => '2026-08-01', 'to' => '2026-08-31']] as $filters) {
        $screenData = $this->actingAs(combinedStoreUser())
            ->get(route('store.stock.purchases.report', $filters))
            ->assertOk()
            ->original->getData();

        $fromScreen = view('store.stock.receiving-report-pdf', $screenData + ['forExcel' => true])->render();

        $fromService = view('store.stock.receiving-report-pdf',
            app(ReceivingReportService::class)->reportPayload($filters) + ['forExcel' => true]
        )->render();

        expect(md5($fromService))->toBe(md5($fromScreen));
    }
});

it('renders byte-identical Issue output from the screen and the workbook paths', function () {
    $f = combinedFixture();

    $combos = [
        [],
        ['month' => '2026-08'],
        // The category case specifically: it is the one the combined workbook
        // translates into, and the one whose banner this change corrected.
        ['category' => $f['category']->id],
    ];

    foreach ($combos as $filters) {
        $screenData = $this->actingAs(combinedStoreUser())
            ->get(route('store.stock.issues.report', $filters))
            ->assertOk()
            ->original->getData();

        $fromScreen = view('store.stock.issue-report-pdf', $screenData + ['forExcel' => true])->render();

        $fromService = view('store.stock.issue-report-pdf',
            app(IssueReportService::class)->reportPayload($filters) + ['forExcel' => true]
        )->render();

        expect(md5($fromService))->toBe(md5($fromScreen));
    }
});

it('names the category on a category-filtered Issue report', function () {
    // Previously this sheet named none of its item/section/person/category
    // filters, so a consumption figure narrowed to one category did not admit
    // it was narrowed — and gets quoted as the month's total.
    $f = combinedFixture();

    $html = view('store.stock.issue-report-pdf',
        app(IssueReportService::class)->reportPayload(['category' => $f['category']->id])
        + ['forExcel' => true]
    )->render();

    expect($html)->toContain('Machine Needles');
});

it('keeps the screen-only dropdown masters on the standalone screens', function () {
    // reportPayload() deliberately does not carry them — they are filter-form
    // furniture, not part of the report — so the controllers must still add
    // them or the screens lose their dropdowns.
    combinedFixture();
    $user = combinedStoreUser();

    $this->actingAs($user)->get(route('store.stock.purchases.report'))->assertOk()
        ->assertViewHas('suppliers');

    $response = $this->actingAs($user)->get(route('store.stock.issues.report'))->assertOk();

    foreach (['items', 'sections', 'persons', 'categories', 'requisitionTypes'] as $key) {
        $response->assertViewHas($key);
    }
});

it('names the file after the period, marked as the combined one', function () {
    combinedFixture();
    Excel::fake();

    $this->actingAs(combinedStoreUser())
        ->get(route('store.stock.ledger.excel.all', ['month' => '2026-08']))
        ->assertOk();

    // Sits beside the single-sheet Stock-Report-2026-08.xlsx in a downloads
    // folder without either being mistaken for the other.
    Excel::assertDownloaded('Stock-Report-2026-08-All.xlsx');
});

it('keeps the sheet names the standalone downloads already use', function () {
    combinedFixture();
    Excel::fake();

    $this->actingAs(combinedStoreUser())
        ->get(route('store.stock.ledger.excel.all', ['month' => '2026-08']))
        ->assertOk();

    expect(array_map(fn ($s) => $s->title(), combinedSheets()))
        ->toBe(['Stock Aug-26', 'Receiving', 'Issues']);
});

// --- 5. The button that reaches it ------------------------------------------

it('offers the combined download on the Stock Report screen', function () {
    combinedFixture();

    // The toolbar carries ONE Excel button and it is this workbook — the
    // separate single-sheet "Download Excel (3 Reports)" pair was merged, so
    // what is asserted is the route behind the plain "Download Excel" label.
    // The button itself is pinned in StockReportSingleExcelButtonTest.
    $this->actingAs(combinedStoreUser())
        ->get(route('store.stock.ledger'))
        ->assertOk()
        ->assertSee(route('store.stock.ledger.excel.all'), false)
        // The filter JS re-points every [data-ledger-export] href, so without
        // this hook the button would download the month the page opened with.
        ->assertSee('data-ledger-export', false);
});

it('writes real dates and a Received Value formula in the Receiving and Issues tabs', function () {
    combinedFixture();

    $response = $this->actingAs(combinedStoreUser())
        ->get(route('store.stock.ledger.excel.all', ['month' => '2026-08']))
        ->assertOk()
        ->baseResponse;

    $raw = $response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse
        ? file_get_contents($response->getFile()->getPathname())
        : $response->getContent();
    $path = tempnam(sys_get_temp_dir(), 'combined').'.xlsx';
    file_put_contents($path, $raw);
    $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);

    $find = function ($sheet, string $heading): array {
        for ($row = 1; $row <= 30; $row++) {
            for ($col = 1; $col <= 20; $col++) {
                $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                if (trim((string) $sheet->getCell($letter.$row)->getValue()) === $heading) {
                    return [$letter, $row];
                }
            }
        }

        return [null, null];
    };

    $serial = fn (string $date) => (float) \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(\Illuminate\Support\Carbon::parse($date));

    // Receiving: Challan Date, RCV Date, and Received Value = Qty x Unit Price.
    $receiving = $book->getSheetByName('Receiving');
    [$challan, $header] = $find($receiving, 'Challan Date');
    [$rcv] = $find($receiving, 'RCV Date');
    [$qty] = $find($receiving, 'Received Qty');
    [$price] = $find($receiving, 'Unit Price');
    [$value] = $find($receiving, 'Received Value');
    $row = $header + 1;

    expect((float) $receiving->getCell($challan.$row)->getValue())->toBe($serial('2026-08-10'))
        ->and((float) $receiving->getCell($rcv.$row)->getValue())->toBe($serial('2026-08-11'))
        ->and($receiving->getStyle($challan.$row)->getNumberFormat()->getFormatCode())->toBe('dd-mmm-yy')
        ->and($receiving->getCell($value.$row)->getValue())->toBe("={$qty}{$row}*{$price}{$row}")
        ->and((float) $receiving->getCell($value.$row)->getCalculatedValue())->toBe(200.0);

    // Issues: Date.
    $issues = $book->getSheetByName('Issues');
    [$date, $issueHeader] = $find($issues, 'Date');

    expect((float) $issues->getCell($date.($issueHeader + 1))->getValue())->toBe($serial('2026-08-15'));
});
