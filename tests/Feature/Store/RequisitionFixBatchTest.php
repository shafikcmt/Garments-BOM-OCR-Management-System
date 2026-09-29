<?php

use App\Exports\MonthlyRequisitionReportExport;
use App\Models\IndentSection;
use App\Models\ItemCategory;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionAction;
use App\Models\PurchaseRequisitionItem;
use App\Models\StockItem;
use App\Models\User;
use App\Services\MonthlyRequisitionReportService as Report;
use App\Support\Quantity;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The 2026-09-29 browser-test fix batch.
 *
 *   1. The head's Approve / Return work through the HTTP routes, and the
 *      action modal is lifted to <body>, centred and scrollable.
 *   2. One quantity format: "10", "12.5" — never "10.0000" or "200.".
 *   3. The monthly report: sum per item first, subtract stock once; ALL /
 *      Section-wise / Category-wise add up; month = requisition date.
 *   4. A user holding store.requisition.review works the Store's side by
 *      permission, whatever their role.
 *
 * In-memory sqlite. No real record touched.
 */
function fbUser(string $role, array $permissions = [], array $attributes = []): User
{
    $user = User::factory()->create($attributes)->assignRole(Role::findOrCreate($role, 'web'));

    foreach (array_merge(['store.requisition.view'], $permissions) as $p) {
        $user->givePermissionTo(Permission::findOrCreate($p, 'web'));
    }

    return $user;
}

/** A counted sheet with one line per [item, qty, stock, rate] (a "stock" of null = no snapshot). */
function fbSheet(string $no, string $date, ?IndentSection $section, array $lines, array $attributes = []): PurchaseRequisition
{
    $sheet = PurchaseRequisition::create($attributes + [
        'requisition_no' => $no,
        'requisition_date' => $date,
        'mode' => PurchaseRequisition::MODE_MULTI,
        'level' => PurchaseRequisition::LEVEL_DEPARTMENT,
        'status' => PurchaseRequisition::STATUS_STORE_REVIEWED,
        'department_review_status' => PurchaseRequisition::DEPT_REVIEW_APPROVED,
        'indent_section_id' => $section?->id,
        'submitted_at' => now(),
        // A department user raised it, as the Requested queue expects.
        'created_by' => (User::where('email', 'fb-raiser@example.test')->first()
            ?? User::factory()->create(['email' => 'fb-raiser@example.test'])->assignRole(Role::findOrCreate('cutting', 'web')))->id,
    ]);

    foreach ($lines as $i => [$item, $qty, $stock, $rate]) {
        PurchaseRequisitionItem::create([
            'purchase_requisition_id' => $sheet->id,
            'stock_item_id' => $item->id,
            'item_category_id' => $item->item_category_id,
            'sort_order' => $i,
            'uom_snapshot' => $item->uom,
            'user_dept' => $section?->name,
            'qty_requested' => $qty,
            'stock_in_hand_snapshot' => $stock,
            // What the old per-line rule stored — the report must not reuse it.
            'to_be_procured_qty' => max(0, $qty - (float) $stock),
            'rate_appx' => $rate,
            'amount' => max(0, $qty - (float) $stock) * $rate,
        ]);
    }

    return $sheet;
}

function fbItem(string $name, string $category): StockItem
{
    $cat = ItemCategory::firstOrCreate(['name' => $category]);

    return StockItem::create(['name' => $name, 'uom' => 'Pcs', 'is_active' => true, 'category' => $category, 'item_category_id' => $cat->id]);
}

function fbSection(string $name): IndentSection
{
    return IndentSection::create(['name' => $name, 'is_active' => true]);
}

function fbRows(string $month, string $view = Report::VIEW_ALL)
{
    $report = app(Report::class);

    return $report->rows($report->resolveMonth($month), [], $view);
}

// --- 1. The head's Approve / Return, and the modal ----------------------------------

beforeEach(fn () => $this->approvers = requisitionApprovers());

function fbDepartmentSheet(): array
{
    $head = fbUser('cutting');
    $section = IndentSection::create(['name' => 'Cutting', 'department_key' => 'production', 'head_user_id' => $head->id, 'is_active' => true]);
    $raiser = fbUser('cutting', ['store.requisition.create'], ['requisition_section_id' => $section->id]);
    $item = StockItem::firstOrCreate(['name' => 'Advestor Rope'], ['uom' => 'Coil', 'is_active' => true]);

    test()->actingAs($raiser)->post(route('store.stock.requisitions.store'), [
        'action' => 'submit', 'mode' => PurchaseRequisition::MODE_MULTI, 'requisition_date' => '2026-09-29',
        'items' => [['stock_item_id' => $item->id, 'qty_requested' => 10]],
    ])->assertRedirect();

    return [$head, $raiser, PurchaseRequisition::latest('id')->firstOrFail()];
}

it('lets the department head approve a department sheet through the route', function () {
    [$head, , $sheet] = fbDepartmentSheet();

    expect($sheet->awaitingDepartmentHead())->toBeTrue();

    $this->actingAs($head)->get(route('store.stock.requisitions.show', $sheet))
        ->assertOk()
        // The modal the Approve button opens: lifted to <body> on load,
        // centred, scrollable.
        ->assertSee('id="stageApprove'.$sheet->id.'"', false)
        ->assertSee('data-body-modal', false)
        ->assertSee('modal-dialog modal-dialog-centered modal-dialog-scrollable', false)
        ->assertSee('document.body.appendChild(modal)', false);

    $this->actingAs($head)->post(route('store.stock.requisitions.stage-approve', $sheet), ['comment' => 'Checked'])
        ->assertRedirect()->assertSessionHas('success');

    $sheet->refresh();
    expect($sheet->department_review_status)->toBe(PurchaseRequisition::DEPT_REVIEW_APPROVED)
        ->and($sheet->current_stage)->toBe(3)
        ->and(PurchaseRequisitionAction::where('purchase_requisition_id', $sheet->id)->where('action', 'head_approved')->sole()->comment)->toBe('Checked');
});

it('lets the department head return a department sheet to its raiser through the route', function () {
    [$head, $raiser, $sheet] = fbDepartmentSheet();

    $this->actingAs($head)->post(route('store.stock.requisitions.stage-return', $sheet), ['comment' => 'Add another'])
        ->assertRedirect()->assertSessionHas('success');

    $sheet->refresh();
    expect($sheet->rejectedByDepartmentHead())->toBeTrue()
        ->and($sheet->returnedToRaiser())->toBeTrue()
        ->and($sheet->isEditable())->toBeTrue();

    $this->actingAs($raiser)->get(route('store.stock.requisitions.edit', $sheet))->assertOk();
});

// --- 2. One quantity format ----------------------------------------------------------

it('writes whole quantities without decimals and trims the rest to four', function () {
    expect(Quantity::format('10.0000'))->toBe('10')
        ->and(Quantity::format(12.5))->toBe('12.5')
        ->and(Quantity::format('0.2500'))->toBe('0.25')
        ->and(Quantity::format(1200))->toBe('1,200')
        ->and(Quantity::format(1234.56789))->toBe('1,234.5679')
        ->and(Quantity::format(0))->toBe('0')
        ->and(Quantity::format(-0.00001))->toBe('0')
        ->and(Quantity::format(null))->toBe('—')
        ->and(Quantity::plain('1200.0000'))->toBe('1200')
        ->and(Quantity::plain(null))->toBe('')
        ->and(Quantity::excelFormat(200))->toBe('#,##0')
        ->and(Quantity::excelFormat(12.5))->toBe('#,##0.00##');
});

it('opens the edit form with "10", not "10.0000"', function () {
    $clerk = fbUser('store', ['store.requisition.create']);
    $item = StockItem::firstOrCreate(['name' => 'Machine Oil'], ['uom' => 'Ltr', 'is_active' => true]);

    $this->actingAs($clerk)->post(route('store.stock.requisitions.store'), [
        'action' => 'draft', 'mode' => PurchaseRequisition::MODE_MULTI, 'requisition_date' => '2026-09-22',
        'items' => [['stock_item_id' => $item->id, 'qty_requested' => 10]],
    ])->assertRedirect();

    $this->actingAs($clerk)->get(route('store.stock.requisitions.edit', PurchaseRequisition::latest('id')->first()))
        ->assertOk()
        ->assertSee('"qty_requested":"10"', false)
        ->assertDontSee('10.0000');
});

it('writes real numbers to the monthly Excel, with no trailing dot on whole quantities', function () {
    $section = fbSection('Cutting');
    fbSheet('HAPL/CUT/01', '2026-09-10', $section, [
        [fbItem('MS Tee-2/1', 'Electrical'), 200, 6, 0],
        [fbItem('Thread Oil', 'Chemical'), 12.5, 0, 0],
    ]);

    $this->actingAs(fbUser('store'));

    // Excel::download returns a BinaryFileResponse; read its file.
    $response = $this->get(route('store.stock.requisitions.report.excel', ['month' => '2026-09']));
    $sheet = IOFactory::load($response->baseResponse->getFile()->getPathname())->getSheetByName('ALL');

    // Row numbers, not Cell objects: PhpSpreadsheet detaches a Cell once
    // another cell is read.
    $rowOf = [];
    foreach ($sheet->getRowIterator() as $row) {
        $name = trim((string) $sheet->getCell('B'.$row->getRowIndex())->getValue());
        if (in_array($name, ['MS Tee-2/1', 'Thread Oil'], true)) {
            $rowOf[$name] = $row->getRowIndex();
        }
    }
    $qty = fn (string $name) => $sheet->getCell('G'.$rowOf[$name]);

    // The cell holds exactly the item name — no requisition number under it.
    expect($rowOf)->toHaveKeys(['MS Tee-2/1', 'Thread Oil'])
        ->and($qty('MS Tee-2/1')->getValue())->toBe(200)
        ->and($qty('MS Tee-2/1')->getStyle()->getNumberFormat()->getFormatCode())->toBe('#,##0')
        ->and($qty('MS Tee-2/1')->getFormattedValue())->toBe('200')
        ->and($qty('Thread Oil')->getValue())->toBe(12.5)
        ->and($qty('Thread Oil')->getStyle()->getNumberFormat()->getFormatCode())->toBe('#,##0.00##');

    // "Safety Stock" spelled right, no "Listing" row, and the sheet's number
    // nowhere in the file — the header prints the report's own number.
    $text = collect($sheet->toArray(null, false, false))->flatten()->filter()->implode("\n");
    expect($text)->toContain('Safety Stock')->not->toContain('Saftey')->not->toContain('Listing')
        ->not->toContain('HAPL/CUT/01')->toContain('HAPL/Dept./ALL/2026/September/01');
});

it('formats money — rate, amount, value, price — as "#,##0.00" in every export', function () {
    // Real cells: the monthly workbook's Rate Appx. (O) and Amount (P).
    fbSheet('CUT/01', '2026-09-10', fbSection('Cutting'), [[fbItem('MS Tee-2/1', 'Electrical'), 7, 0, 12.5]]);
    $this->actingAs(fbUser('store'));
    $sheet = IOFactory::load($this->get(route('store.stock.requisitions.report.excel', ['month' => '2026-09']))
        ->baseResponse->getFile()->getPathname())->getSheetByName('ALL');
    $row = collect(range(1, 30))->first(fn ($r) => $sheet->getCell('B'.$r)->getValue() === 'MS Tee-2/1');

    foreach (['O' => '12.50', 'P' => '87.50'] as $column => $shown) {
        expect($sheet->getCell($column.$row)->getStyle()->getNumberFormat()->getFormatCode())->toBe('#,##0.00')
            ->and($sheet->getCell($column.$row)->getFormattedValue())->toBe($shown);
    }

    // Exports that set their own formats point money at the one constant.
    expect(Quantity::EXCEL_MONEY)->toBe('#,##0.00')
        ->and((new \App\Exports\ItemMasterExport())->columnFormats())->toContain(Quantity::EXCEL_MONEY);
    $receiving = (new ReflectionClass(\App\Exports\MaterialReceivingExport::class))->getConstant('NUMBER_FORMATS');
    expect($receiving['W'])->toBe('#,##0.00')->and($receiving['X'])->toBe('#,##0.00');

    // And none picks its own: no "0.00" without separators, no 4-decimal rate.
    foreach (glob(app_path('Exports').'/{,Concerns/}*.php', GLOB_BRACE) as $file) {
        expect(file_get_contents($file))->not->toContain('FORMAT_NUMBER_00')->not->toMatch("/'#,##0\\.0/");
    }
});

// --- 3. The monthly report ------------------------------------------------------------

it('sums the same item on two sheets of one section into one row and takes stock off once', function () {
    $cutting = fbSection('Cutting');
    $tee = fbItem('MS Tee-2/1', 'Electrical');

    fbSheet('CUT/03', '2026-09-05', $cutting, [[$tee, 12, 6, 10]]);
    fbSheet('CUT/04', '2026-09-20', $cutting, [[$tee, 8, 6, 10]]);

    $rows = fbRows('2026-09');

    // Per line the old rule bought 6 + 2 = 8; the right answer is 20 − 6 = 14.
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['qty_requested'])->toBe(20.0)
        ->and($rows[0]['stock_in_hand'])->toBe(6.0)
        ->and($rows[0]['to_be_procured'])->toBe(14.0)
        ->and($rows[0]['amount'])->toBe(140.0)
        ->and($rows[0]['line_count'])->toBe(2);
});

it('gives ALL one row and Section-wise one row per section, adding up to the same total', function () {
    $cutting = fbSection('Cutting');
    $sewing = fbSection('Sewing');
    $tee = fbItem('MS Tee-2/1', 'Electrical');

    fbSheet('CUT/01', '2026-09-03', $cutting, [[$tee, 6, 10, 5]]);
    fbSheet('SEW/01', '2026-09-08', $sewing, [[$tee, 8, 10, 5]]);

    $all = fbRows('2026-09');
    $bySection = fbRows('2026-09', Report::VIEW_SECTION)->keyBy('section');

    expect($all)->toHaveCount(1)
        ->and($all[0]['qty_requested'])->toBe(14.0)
        ->and($all[0]['to_be_procured'])->toBe(4.0)
        ->and($all[0]['user_dept'])->toBe('Cutting/Sewing');

    // The stock (10) is allocated in request order: Cutting (first) gets 6
    // and buys 0; Sewing gets the other 4 and buys 4. The ALL row keeps the
    // full stock.
    expect($bySection)->toHaveCount(2)
        ->and($bySection['Cutting']['qty_requested'])->toBe(6.0)
        ->and($bySection['Cutting']['stock_in_hand'])->toBe(6.0)
        ->and($bySection['Cutting']['to_be_procured'])->toBe(0.0)
        ->and($bySection['Sewing']['stock_in_hand'])->toBe(4.0)
        ->and($bySection['Sewing']['to_be_procured'])->toBe(4.0)
        ->and($bySection['Sewing']['item_stock_in_hand'])->toBe(10.0)
        ->and($all[0]['stock_in_hand'])->toBe(10.0)
        ->and($bySection->sum('amount'))->toBe($all->sum('amount'));
});

it('allocates stock to sections in request order, as the owner\'s example', function () {
    $tee = fbItem('MS Tee-2/1', 'Electrical');

    fbSheet('CUT/01', '2026-09-02', fbSection('Cutting'), [[$tee, 20, 6, 1]]);
    fbSheet('SEW/01', '2026-09-09', fbSection('Sewing'), [[$tee, 5, 6, 1]]);

    $bySection = fbRows('2026-09', Report::VIEW_SECTION)->keyBy('section');

    // Stock 6; Cutting 20 (first), Sewing 5 → Cutting: allocated 6, TBP 14;
    // Sewing: allocated 0, TBP 5.
    expect($bySection['Cutting']['stock_in_hand'])->toBe(6.0)
        ->and($bySection['Cutting']['to_be_procured'])->toBe(14.0)
        ->and($bySection['Sewing']['stock_in_hand'])->toBe(0.0)
        ->and($bySection['Sewing']['to_be_procured'])->toBe(5.0)
        ->and(fbRows('2026-09')[0]['to_be_procured'])->toBe(19.0);

    // "Stock Allocated" and the note on the screen, the PDF source and every
    // Section-wise tab; the ALL view keeps "Stock in Hand".
    $this->actingAs(fbUser('store'));
    $this->get(route('store.stock.requisitions.report', ['month' => '2026-09', 'view' => 'section']))
        ->assertOk()->assertSee('Stock Allocated')->assertSee(Report::SECTION_STOCK_NOTE);
    $this->get(route('store.stock.requisitions.report', ['month' => '2026-09']))
        ->assertOk()->assertSee('Stock in Hand')->assertDontSee('Stock Allocated')->assertDontSee(Report::SECTION_STOCK_NOTE);

    $book = IOFactory::load($this->get(route('store.stock.requisitions.report.excel', ['month' => '2026-09', 'view' => 'section']))->baseResponse->getFile()->getPathname());
    // ALL first (full Stock in Hand), then the section tabs (Stock Allocated).
    expect($book->getSheetNames())->toBe(['ALL', 'Cutting', 'Sewing']);
    foreach ($book->getAllSheets() as $sheet) {
        $text = collect($sheet->toArray(null, false, false))->flatten()->filter()->implode("\n");
        $sheet->getTitle() === 'ALL'
            ? expect($text)->toContain('Stock in Hand')->not->toContain('Stock Allocated')
            : expect($text)->toContain('Stock Allocated')->toContain(Report::SECTION_STOCK_NOTE)->not->toContain('Stock in Hand');
    }
});

it('makes the ALL total equal the category tabs and the section tabs', function () {
    $cutting = fbSection('Cutting');
    $sewing = fbSection('Sewing');
    $tee = fbItem('MS Tee-2/1', 'Electrical');
    $rope = fbItem('Advestor Rope', 'Electrical');
    $pen = fbItem('Bal Pen', 'Stationery');

    fbSheet('CUT/01', '2026-09-03', $cutting, [[$tee, 6, 10, 5], [$rope, 10, 2, 30], [$pen, 50, 5, 12]]);
    fbSheet('CUT/02', '2026-09-15', $cutting, [[$tee, 9, 10, 5.5]]);
    fbSheet('SEW/01', '2026-09-08', $sewing, [[$tee, 8, 10, 5], [$pen, 20, 5, 12]]);

    $report = app(Report::class);
    $all = fbRows('2026-09');
    $sections = $report->bySection(fbRows('2026-09', Report::VIEW_SECTION));
    $categories = $report->byCategory($all);

    $total = round($all->sum('amount'), 4);
    expect(round($categories->sum(fn ($rows) => $rows->sum('amount')), 4))->toBe($total)
        ->and(round($sections->sum(fn ($rows) => $rows->sum('amount')), 4))->toBe($total)
        ->and(round($sections->sum(fn ($rows) => $rows->sum('to_be_procured')), 4))->toBe(round($all->sum('to_be_procured'), 4))
        ->and($sections->keys()->all())->toBe(['Cutting', 'Sewing']);

    // The tee: 6 + 9 + 8 = 23 against stock 10 → 13, at the LATEST rate (5.5).
    $teeRow = $all->firstWhere('item_name', 'MS Tee-2/1');
    expect($teeRow['to_be_procured'])->toBe(13.0)
        ->and($teeRow['rate_appx'])->toBe(5.5)
        ->and($teeRow['amount'])->toBe(71.5);
});

it('reports a sheet dated 30 September in September, not October', function () {
    fbSheet('CUT/30', '2026-09-30', fbSection('Cutting'), [[fbItem('MS Tee-2/1', 'Electrical'), 5, 0, 1]]);

    expect(fbRows('2026-09'))->toHaveCount(1)
        ->and(fbRows('2026-10'))->toHaveCount(0);
});

it('leaves drafts, sheets still with the head, and cancelled sheets out', function () {
    $cutting = fbSection('Cutting');
    $tee = fbItem('MS Tee-2/1', 'Electrical');

    fbSheet('OK/1', '2026-09-05', $cutting, [[$tee, 5, 0, 1]]);
    fbSheet('DRAFT/1', '2026-09-05', $cutting, [[$tee, 100, 0, 1]], ['status' => PurchaseRequisition::STATUS_DRAFT]);
    fbSheet('HEAD/1', '2026-09-05', $cutting, [[$tee, 200, 0, 1]], ['status' => PurchaseRequisition::STATUS_SUBMITTED, 'department_review_status' => PurchaseRequisition::DEPT_REVIEW_PENDING]);
    fbSheet('CANC/1', '2026-09-05', $cutting, [[$tee, 400, 0, 1]], ['status' => PurchaseRequisition::STATUS_CANCELLED]);

    $rows = fbRows('2026-09');
    expect($rows->sum('qty_requested'))->toBe(5.0)
        ->and(app(Report::class)->requisitionNosLabel($rows))->toBe('OK/1');
});

it('lists the source sheets on the screen only, first five then "and N more"', function () {
    $cutting = fbSection('Cutting');
    $tee = fbItem('MS Tee-2/1', 'Electrical');

    foreach (range(1, 7) as $n) {
        fbSheet('CUT/0'.$n, '2026-09-0'.$n, $cutting, [[$tee, 1, 0, 1]]);
    }

    $this->actingAs(fbUser('store'))
        ->get(route('store.stock.requisitions.report', ['month' => '2026-09']))
        ->assertOk()
        ->assertSee('From: CUT/01, CUT/02, CUT/03, CUT/04, CUT/05 and 2 more');

    // Never in the printed header.
    $file = $this->get(route('store.stock.requisitions.report.excel', ['month' => '2026-09']))->baseResponse->getFile()->getPathname();
    $text = collect(IOFactory::load($file)->getSheetByName('ALL')->toArray(null, false, false))->flatten()->filter()->implode("\n");
    expect($text)->not->toContain('CUT/01');
});

it('exports ALL plus one tab per category, and ALL plus one tab per section Section-wise', function () {
    $cutting = fbSection('Cutting');
    $sewing = fbSection('Sewing');
    fbSheet('CUT/01', '2026-09-03', $cutting, [[fbItem('MS Tee-2/1', 'Electrical'), 6, 0, 5]]);
    fbSheet('SEW/01', '2026-09-08', $sewing, [[fbItem('Bal Pen', 'Stationery'), 8, 0, 5]]);

    $this->actingAs(fbUser('store'));

    $titles = function (array $query) {
        $file = $this->get(route('store.stock.requisitions.report.excel', $query))->baseResponse->getFile()->getPathname();

        return IOFactory::load($file)->getSheetNames();
    };

    expect($titles(['month' => '2026-09']))->toBe(['ALL', 'Electrical', 'Stationery'])
        ->and($titles(['month' => '2026-09', 'view' => 'category']))->toBe(['ALL', 'Electrical', 'Stationery'])
        ->and($titles(['month' => '2026-09', 'view' => 'section']))->toBe(['ALL', 'Cutting', 'Sewing']);
});

// --- 4. The Store's side by permission -------------------------------------------------

it('shows a Store clerk on another role what has reached the Store, their own sheets, and the Requested tab', function () {
    $clerk = fbUser('store_general_stock', ['store.requisition.review', 'store.requisition.create']);
    $cutting = fbSection('Cutting');
    $tee = fbItem('MS Tee-2/1', 'Electrical');

    $reached = fbSheet('REACHED/1', '2026-09-05', $cutting, [[$tee, 1, 0, 1]], ['status' => PurchaseRequisition::STATUS_SUBMITTED]);
    $noHead = fbSheet('DIRECT/1', '2026-09-05', $cutting, [[$tee, 1, 0, 1]], ['status' => PurchaseRequisition::STATUS_SUBMITTED, 'department_review_status' => null]);
    $final = fbSheet('FINAL/1', '2026-09-05', null, [[$tee, 1, 0, 1]], ['level' => PurchaseRequisition::LEVEL_FINAL, 'department_review_status' => null, 'status' => PurchaseRequisition::STATUS_SUBMITTED]);
    $ownDraft = fbSheet('OWN/1', '2026-09-05', null, [[$tee, 1, 0, 1]], ['status' => PurchaseRequisition::STATUS_DRAFT, 'created_by' => $clerk->id]);
    $otherDraft = fbSheet('DRAFT/1', '2026-09-05', null, [[$tee, 1, 0, 1]], ['level' => PurchaseRequisition::LEVEL_FINAL, 'status' => PurchaseRequisition::STATUS_DRAFT]);
    $withHead = fbSheet('HEAD/1', '2026-09-05', $cutting, [[$tee, 1, 0, 1]], ['status' => PurchaseRequisition::STATUS_SUBMITTED, 'department_review_status' => PurchaseRequisition::DEPT_REVIEW_PENDING]);

    expect(PurchaseRequisition::worksStoreDesk($clerk))->toBeTrue()
        ->and(PurchaseRequisition::seesEveryRequisition($clerk))->toBeFalse()
        ->and(PurchaseRequisition::query()->visibleTo($clerk)->pluck('requisition_no')->sort()->values()->all())
            ->toBe(['DIRECT/1', 'FINAL/1', 'OWN/1', 'REACHED/1']);

    foreach ([$reached, $noHead, $final, $ownDraft] as $sheet) {
        $this->actingAs($clerk)->get(route('store.stock.requisitions.show', $sheet))->assertOk();
    }
    foreach ([$otherDraft, $withHead] as $sheet) {
        $this->actingAs($clerk)->get(route('store.stock.requisitions.show', $sheet))->assertNotFound();
    }

    $this->actingAs($clerk)->get(route('store.stock.requisitions.index'))
        ->assertOk()->assertSee('All Requisitions')->assertSee(route('store.stock.requisitions.requested'), false)
        ->assertSee('REACHED/1')->assertDontSee('DRAFT/1')->assertDontSee('HEAD/1');

    $this->actingAs($clerk)->get(route('store.stock.requisitions.requested'))
        ->assertOk()->assertSee('REACHED/1')->assertDontSee('HEAD/1');
});

it('keeps a department user without the Store permission on their own sheets', function () {
    $user = fbUser('merchant', ['store.requisition.create']);
    $tee = fbItem('MS Tee-2/1', 'Electrical');

    fbSheet('REACHED/1', '2026-09-05', fbSection('Cutting'), [[$tee, 1, 0, 1]]);
    fbSheet('MINE/1', '2026-09-05', null, [[$tee, 1, 0, 1]], ['created_by' => $user->id]);

    expect(PurchaseRequisition::worksStoreDesk($user))->toBeFalse()
        ->and(PurchaseRequisition::query()->visibleTo($user)->pluck('requisition_no')->all())->toBe(['MINE/1']);

    $this->actingAs($user)->get(route('store.stock.requisitions.requested'))->assertForbidden();
});
