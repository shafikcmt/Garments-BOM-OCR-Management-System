<?php

use App\Models\ItemCategory;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Models\StockItem;
use App\Models\User;
use App\Services\CategorySummary;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;

/**
 * The Final Requisition by category: a Category column after Type, categories
 * largest spend first (the Category Summary's order), a highlighted
 * "Category Sum" row after each, and By Category downloads like the Monthly
 * Report's. The Category Summary reads the same stored lines, so its figures
 * are the Final Requisition's.
 *
 * In-memory sqlite. No real record touched.
 */
function fcFinal(): array
{
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('admin', 'web'));

    $sheet = PurchaseRequisition::create([
        'requisition_no' => 'HAPL/ALL/2026/October/01', 'requisition_date' => '2026-10-31', 'mode' => PurchaseRequisition::MODE_MULTI,
        'level' => PurchaseRequisition::LEVEL_FINAL, 'status' => PurchaseRequisition::STATUS_APPROVED,
        'submitted_at' => now(), 'created_by' => $admin->id,
    ]);

    // Stationery 100 (small), Chemical 300 (large): Chemical must come first.
    $lines = [
        ['Stationery', 'Pen', 10, 5],
        ['Chemical', 'Solvent', 10, 20],
        ['Chemical', 'Acid', 5, 20],
        ['Stationery', 'Marker', 10, 5],
    ];
    foreach ($lines as $i => [$category, $name, $qty, $rate]) {
        $cat = ItemCategory::firstOrCreate(['name' => $category]);
        $item = StockItem::create(['name' => $name, 'uom' => 'Piece', 'category' => $category, 'is_active' => true, 'item_category_id' => $cat->id]);
        PurchaseRequisitionItem::create([
            'purchase_requisition_id' => $sheet->id, 'stock_item_id' => $item->id, 'item_category_id' => $cat->id, 'sort_order' => $i,
            'qty_requested' => $qty, 'stock_in_hand_snapshot' => 0, 'to_be_procured_qty' => $qty, 'rate_appx' => $rate, 'amount' => $qty * $rate,
        ]);
    }

    return [$admin, $sheet];
}

it('reads a Final Requisition by category, largest spend first, each closed by its sum', function () {
    [$admin, $sheet] = fcFinal();

    $html = $this->actingAs($admin)->get(route('store.stock.requisitions.show', $sheet))->assertOk()->getContent();

    expect($html)->toContain('Category Sum: Chemical')->toContain('Category Sum: Stationery')
        ->and(strpos($html, 'Category Sum: Chemical'))->toBeLessThan(strpos($html, 'Category Sum: Stationery'))
        // Items A to Z inside the category: Acid before Solvent.
        ->and(strpos($html, 'Acid'))->toBeLessThan(strpos($html, 'Solvent'));
});

it('puts Category after Type in the Final Requisition Excel, with highlighted category sums', function () {
    [$admin, $sheet] = fcFinal();

    $response = $this->actingAs($admin)->get(route('store.stock.requisitions.excel', $sheet))->assertOk()->baseResponse;
    $ws = IOFactory::load($response->getFile()->getPathname())->getSheet(0);

    $headerRow = null;
    foreach ($ws->getRowIterator() as $row) {
        if (trim((string) $ws->getCell('A'.$row->getRowIndex())->getValue()) === 'SL') {
            $headerRow = $row->getRowIndex();
            break;
        }
    }
    expect($headerRow)->not->toBeNull();

    $headings = collect(range('A', 'G'))->map(fn ($c) => trim((string) $ws->getCell($c.$headerRow)->getValue()))->all();
    expect($headings[4])->toStartWith('Type')->and($headings[5])->toBe('Category');

    $sums = [];
    foreach ($ws->getRowIterator() as $row) {
        $label = trim((string) $ws->getCell('A'.$row->getRowIndex())->getValue());
        if (str_starts_with($label, 'Category Sum')) {
            $sums[$label] = $row->getRowIndex();
        }
    }
    expect(array_keys($sums))->toBe(['Category Sum: Chemical', 'Category Sum: Stationery']);
    $first = $sums['Category Sum: Chemical'];
    expect($ws->getStyle('A'.$first)->getFill()->getStartColor()->getARGB())->toBe('FFFFF4D6')
        ->and($ws->getStyle('A'.$first)->getFont()->getBold())->toBeTrue();
});

it('downloads the Final Requisition By Category: an ALL sheet then one per category', function () {
    [$admin, $sheet] = fcFinal();

    $response = $this->actingAs($admin)->get(route('store.stock.requisitions.excel', [$sheet, 'view' => 'category']))->assertOk()->baseResponse;
    $book = IOFactory::load($response->getFile()->getPathname());

    expect($book->getSheetNames())->toBe(['ALL', 'Chemical', 'Stationery']);
    $this->actingAs($admin)->get(route('store.stock.requisitions.pdf', [$sheet, 'view' => 'category']))->assertOk();
    $this->actingAs($admin)->get(route('store.stock.requisitions.pdf', $sheet))->assertOk();
});

it('matches the Category Summary to the Final Requisition, in the same category order', function () {
    Carbon::setTestNow('2026-11-02');
    [$admin, $sheet] = fcFinal();

    $summary = app(CategorySummary::class)->build(Carbon::parse('2026-10-01'));

    expect($summary['categories']->pluck('name')->all())->toBe(['Chemical', 'Stationery'])
        ->and($summary['categories']->pluck('amount')->all())->toBe([300.0, 100.0])
        ->and($summary['totals']['amount'])->toBe((float) $sheet->items()->sum('amount'))
        // Monthly + Individual add up to the total.
        ->and(collect($summary['kinds'])->sum('amount'))->toBe($summary['totals']['amount']);

    Carbon::setTestNow();
});

it('counts a draft Final Requisition once, not the department sheets merged into it', function () {
    Carbon::setTestNow('2026-11-02');
    [$admin, $final] = fcFinal();
    $final->update(['status' => PurchaseRequisition::STATUS_DRAFT]);

    // A department sheet already merged into that draft: it must not be counted as well.
    $dept = PurchaseRequisition::create([
        'requisition_no' => 'HAPL/CUT/2026/October/01', 'requisition_date' => '2026-10-10', 'mode' => PurchaseRequisition::MODE_MULTI,
        'level' => PurchaseRequisition::LEVEL_DEPARTMENT, 'status' => PurchaseRequisition::STATUS_STORE_REVIEWED,
        'submitted_at' => now(), 'created_by' => $admin->id,
    ]);
    $dept->forceFill(['merged_into_id' => $final->id])->save();
    PurchaseRequisitionItem::create(['purchase_requisition_id' => $dept->id, 'custom_item_name' => 'Pen', 'sort_order' => 1,
        'qty_requested' => 99, 'to_be_procured_qty' => 99, 'rate_appx' => 10, 'amount' => 990]);

    $summary = app(CategorySummary::class)->build(Carbon::parse('2026-10-01'));
    expect($summary['totals']['amount'])->toBe((float) $final->items()->sum('amount'))
        ->and($summary['totals']['requisitions'])->toBe(1);

    Carbon::setTestNow();
});
