<?php

use App\Models\AppSetting;
use App\Models\Department;
use App\Models\IndentSection;
use App\Models\ItemCategory;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Models\RequisitionSignatureBlock;
use App\Models\StockItem;
use App\Models\User;
use App\Services\MonthlyRequisitionReportService as Report;
use App\Services\RequisitionHeaderResolver as Header;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;

/**
 * The Monthly Requisition report's printed header, per tab type (owner,
 * 2026-09-29):
 *
 *   ALL / category tab: HAPL/Dept./ALL/<Year>/<Month>/01, Section ALL,
 *     Name of User ALL, Contact = Contact Person setting, FINAL boxes.
 *   Section tab: HAPL/Dept./<SECTION>/<Year>/<Month>/01, the section, its
 *     head (section head, else department head) from the head's profile,
 *     "—" when empty, DEPARTMENT boxes.
 *
 * Plus the screen's switch and tab in the URL, and the download following
 * the switch. In-memory sqlite. No real record touched.
 */
beforeEach(function () {
    $this->store = User::factory()->create()->assignRole(Role::findOrCreate('store', 'web'));
    $this->raiser = User::factory()->create()->assignRole(Role::findOrCreate('cutting', 'web'));
});

function mhSection(string $name, ?User $head = null, ?string $department = null): IndentSection
{
    return IndentSection::create(['name' => $name, 'is_active' => true, 'head_user_id' => $head?->id, 'department_key' => $department]);
}

function mhSheet(string $no, ?IndentSection $section, string $item, string $category, float $qty, ?float $stockInHand = null, string $date = '2026-09-10'): void
{
    $cat = ItemCategory::firstOrCreate(['name' => $category]);
    $stock = StockItem::firstOrCreate(['name' => $item], ['uom' => 'Pcs', 'is_active' => true, 'item_category_id' => $cat->id]);

    $sheet = PurchaseRequisition::create([
        'requisition_no' => $no, 'requisition_date' => $date, 'mode' => PurchaseRequisition::MODE_MULTI,
        'level' => PurchaseRequisition::LEVEL_DEPARTMENT, 'status' => PurchaseRequisition::STATUS_STORE_REVIEWED,
        'department_review_status' => PurchaseRequisition::DEPT_REVIEW_APPROVED, 'indent_section_id' => $section?->id,
        'submitted_at' => now(), 'created_by' => test()->raiser->id,
    ]);

    PurchaseRequisitionItem::create([
        'purchase_requisition_id' => $sheet->id, 'stock_item_id' => $stock->id, 'item_category_id' => $cat->id,
        'sort_order' => 1, 'qty_requested' => $qty, 'user_dept' => $section?->name, 'to_be_procured_qty' => $qty, 'rate_appx' => 10, 'amount' => $qty * 10,
        'stock_in_hand_snapshot' => $stockInHand,
    ]);
}

/** Download the workbook for a switch and read every sheet's header block: sheet => [label => value]. */
function mhWorkbook(array $query): array
{
    $file = test()->actingAs(test()->store)
        ->get(route('store.stock.requisitions.report.excel', $query + ['month' => '2026-09']))
        ->assertOk()->baseResponse->getFile()->getPathname();

    $out = [];
    foreach (IOFactory::load($file)->getAllSheets() as $sheet) {
        $header = [];
        foreach (range(3, 8) as $r) {
            $label = trim((string) $sheet->getCell('A'.$r)->getValue());
            if ($label !== '') {
                $header[$label] = trim((string) $sheet->getCell('E'.$r)->getValue());
            }
        }
        $text = collect($sheet->toArray(null, false, false))->flatten()->filter()->implode("\n");
        $out[$sheet->getTitle()] = ['header' => $header, 'text' => $text, 'boxes' => substr_count($text, 'Signature & Date')];
    }

    return $out;
}

it('prints the ALL number, Section ALL, Name of User ALL and the Contact Person on ALL and category tabs', function () {
    $contact = User::factory()->create(['phone' => '01711-000111', 'email' => 'contact@example.test']);
    AppSetting::put(Header::SETTING_CONTACT_PERSON, (string) $contact->id);

    mhSheet('CUT/01', mhSection('Cutting'), 'MS Tee-2/1', 'Electrical Components', 5);
    mhSheet('CUT/02', IndentSection::where('name', 'Cutting')->first(), 'Bal Pen', 'Stationery & Printing', 10);

    $book = mhWorkbook(['view' => Report::VIEW_CATEGORY]);

    expect(array_keys($book))->toBe(['ALL', 'Electrical Components', 'Stationery & Printing']);
    foreach ($book as $tab) {
        expect($tab['header'])->toMatchArray([
            'Requisition No' => 'HAPL/Dept./ALL/2026/September/01',
            'Section' => 'ALL',
            'Name of User' => 'ALL',
            'Contact No. / Email' => '01711-000111 / contact@example.test',
        ])->and($tab['text'])->not->toContain('CUT/01');
    }
});

it('prints the section, its head from the profile, and the section number on section tabs', function () {
    $head = User::factory()->create(['name' => 'Kazi Md Oliur Rahman', 'designation' => 'Section Head', 'phone' => '01819-222333', 'email' => 'cutting@example.test']);
    mhSheet('CUT/01', mhSection('Cutting', $head), 'MS Tee-2/1', 'Electrical Components', 5);

    $cutting = mhWorkbook(['view' => Report::VIEW_SECTION])['Cutting'];

    expect($cutting['header'])->toMatchArray([
        'Requisition No' => 'HAPL/Dept./CUTTING/2026/September/01',
        'Section' => 'Cutting',
        'Name of User' => 'Kazi Md Oliur Rahman',
        'Contact No. / Email' => '01819-222333 / cutting@example.test',
    ]);

    $header = app(Report::class)->tabHeader(Report::TAB_SECTION, 'Cutting', collect(), now()->setDate(2026, 9, 1));
    expect($header['designation'])->toBe('Section Head');
});

it('falls back to the department head, and prints "—" for an empty profile value', function () {
    // No section head: the department's head, whose profile has an email only.
    $deptHead = User::factory()->create(['name' => 'Production Head', 'phone' => null, 'email' => 'prod@example.test']);
    Department::updateOrCreate(['key' => 'production'], ['label' => 'Production', 'head_user_id' => $deptHead->id, 'is_active' => true]);
    mhSheet('SEW/01', mhSection('Sewing', null, 'production'), 'MS Tee-2/1', 'Electrical Components', 5);

    // Nobody at all.
    mhSheet('FIN/01', mhSection('Finishing'), 'Bal Pen', 'Stationery & Printing', 5);

    $book = mhWorkbook(['view' => Report::VIEW_SECTION]);

    expect($book['Sewing']['header'])->toMatchArray([
        'Section' => 'Sewing', 'Name of User' => 'Production Head', 'Contact No. / Email' => 'prod@example.test',
    ])->and($book['Finishing']['header'])->toMatchArray([
        'Requisition No' => 'HAPL/Dept./FINISHING/2026/September/01',
        'Section' => 'Finishing', 'Name of User' => '—', 'Contact No. / Email' => '—',
    ]);
});

it('prints the final sheet boxes on ALL and category tabs, the department boxes on section tabs, and follows the settings', function () {
    mhSheet('CUT/01', mhSection('Cutting'), 'MS Tee-2/1', 'Electrical Components', 5);

    $final = RequisitionSignatureBlock::query()->forLevel(RequisitionSignatureBlock::LEVEL_FINAL)->count();
    $department = RequisitionSignatureBlock::query()->forLevel(RequisitionSignatureBlock::LEVEL_DEPARTMENT)->count();
    expect($final)->toBe(4)->and($department)->toBe(3);

    $book = mhWorkbook(['view' => Report::VIEW_CATEGORY]);
    expect($book['ALL']['boxes'])->toBe($final)
        ->and($book['Electrical Components']['boxes'])->toBe($final)
        ->and(mhWorkbook(['view' => Report::VIEW_SECTION])['Cutting']['boxes'])->toBe($department);

    // Admin drops a final box and adds a department one: the report follows.
    RequisitionSignatureBlock::query()->forLevel(RequisitionSignatureBlock::LEVEL_FINAL)->get()->last()->delete();
    RequisitionSignatureBlock::create([
        'level' => RequisitionSignatureBlock::LEVEL_DEPARTMENT, 'label' => 'Verified by', 'assignee_type' => RequisitionSignatureBlock::ASSIGNEE_ROLE,
        'role_name' => 'store', 'sequence' => 99,
    ]);

    expect(mhWorkbook(['view' => Report::VIEW_ALL])['ALL']['boxes'])->toBe($final - 1)
        ->and(mhWorkbook(['view' => Report::VIEW_SECTION])['Cutting']['boxes'])->toBe($department + 1)
        ->and(mhWorkbook(['view' => Report::VIEW_SECTION])['Cutting']['text'])->toContain('Verified by');
});

it('names the configured people under the boxes, as the single sheet does before signing', function () {
    $head = User::factory()->create(['name' => 'Cutting Head Person']);
    $storeHead = User::factory()->create(['name' => 'Store Head Person']);
    Department::updateOrCreate(['key' => 'store'], ['label' => 'Store', 'head_user_id' => $storeHead->id, 'is_active' => true]);
    mhSheet('CUT/01', mhSection('Cutting', $head), 'MS Tee-2/1', 'Electrical Components', 5);

    $boxes = app(Report::class)->tabHeader(Report::TAB_SECTION, 'Cutting', collect(), now()->setDate(2026, 9, 1))['signatures'];
    $names = collect($boxes)->pluck('name')->all();

    // The department sheet's Store box is the Store team, not a named
    // person: any Store user receives it, so nobody is printed under it.
    expect($names)->toContain('Cutting Head Person')->not->toContain('Store Head Person')
        ->and(collect($boxes)->pluck('title')->all())->toContain('Store')
        ->and(collect($boxes)->pluck('signed_at')->filter()->all())->toBe([]);
});

it('keeps the switch and the selected tab in the URL', function () {
    mhSheet('CUT/01', mhSection('Cutting'), 'MS Tee-2/1', 'Electrical Components', 5);
    mhSheet('SEW/01', mhSection('Sewing'), 'Bal Pen', 'Stationery & Printing', 5);

    $page = fn (array $q) => $this->actingAs($this->store)->get(route('store.stock.requisitions.report', $q + ['month' => '2026-09']))->assertOk();

    // The switch: three links carrying the month and the view.
    $page([])
        ->assertSee(e(route('store.stock.requisitions.report', ['month' => '2026-09', 'view' => 'category'])), false)
        ->assertSee(e(route('store.stock.requisitions.report', ['month' => '2026-09', 'view' => 'section'])), false)
        ->assertSee('data-report-tab="all"', false)
        ->assertDontSee('data-report-tab="electrical-components"', false);

    // By Category: ALL + each category; ?tab= opens that one.
    $page(['view' => 'category', 'tab' => 'stationery-printing'])
        ->assertSee('data-report-tab="all"', false)
        ->assertSee('class="tab-pane fade show active" id="tab-stationery-printing"', false)
        ->assertSee('class="tab-pane fade" id="tab-all"', false);

    // By Section: ALL, then one per section; ?tab= opens a section, and an
    // unknown tab falls back to the first (ALL).
    $page(['view' => 'section', 'tab' => 'cutting'])
        ->assertSee('class="tab-pane fade show active" id="tab-cutting"', false)
        ->assertSee('class="tab-pane fade" id="tab-all"', false);
    $page(['view' => 'section', 'tab' => 'nope'])
        ->assertSee('class="tab-pane fade show active" id="tab-all"', false)
        ->assertSee('class="tab-pane fade" id="tab-cutting"', false)
        ->assertSee('data-report-tab="sewing"', false);
});

it('prints the Store Personnel box in the PDF header, with no item count or amount', function () {
    mhSheet('CUT/01', mhSection('Cutting'), 'MS Tee-2/1', 'Electrical Components', 5);
    $report = app(Report::class);
    $month = $report->resolveMonth('2026-09');

    $html = view('store.stock.requisitions.report-pdf', [
        'tabs' => $report->exportTabs($month, Report::VIEW_ALL), 'rows' => $report->rows($month),
        'month' => '2026-09', 'monthLabel' => 'September 2026', 'title' => 'Purchase Requisition',
    ])->render();

    preg_match_all('/<table class="head-block">(.*?)<\/table>/s', $html, $blocks);
    expect($blocks[1])->not->toBeEmpty();
    foreach ($blocks[1] as $block) {
        expect($block)->toContain('Store Personnel')->toContain('Designation')
            ->not->toContain('Distinct Items')->not->toContain('Total Amount');
    }
    // The total stays on the Total- row.
    expect($html)->toContain('Total-');
});

it('starts the section-wise export with an ALL tab that the section tabs add up to', function () {
    // One item, stock 6: Cutting asks 20 first, Sewing 5 later. A second item
    // only Sewing asks for.
    $cutting = mhSection('Cutting');
    $sewing = mhSection('Sewing');
    mhSheet('CUT/01', $cutting, 'MS Tee-2/1', 'Electrical Components', 20, 6, '2026-09-03');
    mhSheet('SEW/01', $sewing, 'MS Tee-2/1', 'Electrical Components', 5, 6, '2026-09-12');
    mhSheet('SEW/02', $sewing, 'Bal Pen', 'Stationery & Printing', 8, 2, '2026-09-12');

    $file = test()->actingAs(test()->store)
        ->get(route('store.stock.requisitions.report.excel', ['month' => '2026-09', 'view' => Report::VIEW_SECTION]))
        ->baseResponse->getFile()->getPathname();
    $book = IOFactory::load($file);

    expect($book->getSheetNames())->toBe(['ALL', 'Cutting', 'Sewing']);

    // Each tab's rows by item: [qty, stock, to be procured, amount], and its Total- amount.
    $read = function (string $title) use ($book) {
        $sheet = $book->getSheetByName($title);
        $rows = [];
        $total = null;
        // Calculated, so the ALL tab's To Be Procured / Amount formulas read as numbers.
        foreach ($sheet->toArray(null, true, false) as $row) {
            // The "Total-" label sits in the last cell before the amount (no merged cells).
            if (in_array('Total-', array_slice($row, 0, 15), true)) {
                $total = (float) $row[15];
            } elseif (is_numeric($row[0] ?? null) && ! empty($row[1])) {
                $rows[$row[1]] = [(float) $row[6], (float) $row[7], (float) $row[13], (float) $row[15], $row[5]];
            }
        }

        return ['rows' => $rows, 'total' => $total, 'text' => collect($sheet->toArray(null, false, false))->flatten()->filter()->implode("\n")];
    };

    $all = $read('ALL');
    $cut = $read('Cutting');
    $sew = $read('Sewing');

    // ALL: one row per item, sections joined, full stock, stock taken off once.
    expect($all['rows']['MS Tee-2/1'])->toBe([25.0, 6.0, 19.0, 190.0, 'Cutting/Sewing'])
        ->and($all['rows']['Bal Pen'])->toBe([8.0, 2.0, 6.0, 60.0, 'Sewing'])
        // Sections: stock allocated in request order — Cutting 6, Sewing 0.
        ->and($cut['rows']['MS Tee-2/1'])->toBe([20.0, 6.0, 14.0, 140.0, 'Cutting'])
        ->and($sew['rows']['MS Tee-2/1'])->toBe([5.0, 0.0, 5.0, 50.0, 'Sewing']);

    // Per item and in the Total row, ALL = the sum of the section tabs.
    foreach (['MS Tee-2/1', 'Bal Pen'] as $item) {
        $sum = fn (int $i) => ($cut['rows'][$item][$i] ?? 0) + ($sew['rows'][$item][$i] ?? 0);
        expect($sum(2))->toBe($all['rows'][$item][2])->and($sum(3))->toBe($all['rows'][$item][3]);
    }
    expect($cut['total'] + $sew['total'])->toBe($all['total'])->and($all['total'])->toBe(250.0);

    // The ALL tab's header and boxes are the category export's; the section
    // tabs keep theirs.
    expect($all['text'])->toContain('HAPL/Dept./ALL/2026/September/01')->toContain('Stock in Hand')
        ->not->toContain('Stock Allocated')->not->toContain(Report::SECTION_STOCK_NOTE)
        ->and(substr_count($all['text'], 'Signature & Date'))->toBe(4)
        ->and($cut['text'])->toContain('HAPL/Dept./CUTTING/2026/September/01')->toContain('Stock Allocated')
        ->and(substr_count($cut['text'], 'Signature & Date'))->toBe(3);
});

it('makes the downloads follow the switch', function () {
    mhSheet('CUT/01', mhSection('Cutting'), 'MS Tee-2/1', 'Electrical Components', 5);
    mhSheet('SEW/01', mhSection('Sewing'), 'Bal Pen', 'Stationery & Printing', 5);

    expect(array_keys(mhWorkbook(['view' => Report::VIEW_ALL])))->toBe(['ALL', 'Electrical Components', 'Stationery & Printing'])
        ->and(array_keys(mhWorkbook(['view' => Report::VIEW_CATEGORY])))->toBe(['ALL', 'Electrical Components', 'Stationery & Printing'])
        ->and(array_keys(mhWorkbook(['view' => Report::VIEW_SECTION])))->toBe(['ALL', 'Cutting', 'Sewing']);

    // The PDF is rendered from the same download tabs.
    foreach ([Report::VIEW_ALL, Report::VIEW_CATEGORY, Report::VIEW_SECTION] as $view) {
        $this->actingAs($this->store)->get(route('store.stock.requisitions.report.pdf', ['month' => '2026-09', 'view' => $view]))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }
});
