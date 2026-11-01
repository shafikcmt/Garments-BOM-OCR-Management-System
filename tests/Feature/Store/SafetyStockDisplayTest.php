<?php

use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Models\StockIssue;
use App\Models\StockItem;
use App\Models\User;
use App\Support\Quantity;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Safety Stock is shown as a round figure (owner, 2026-11-01; was 2
 * decimals): 2.6923 → 3, 42.54 → 43 — on the Stock Report and the
 * requisitions, screen, PDF and Excel. The figure itself — last month's
 * consumption / 26 × 7 — and every stored value are unchanged; a Stock
 * Report Excel cell keeps the real number and shows it rounded.
 *
 * To Be Procured adds the rounded Safety Stock: Qty − Stock + Safety.
 *
 * In-memory sqlite. No real record touched.
 */
function ssUser(): User
{
    Role::findOrCreate('store', 'web')->givePermissionTo(Permission::findOrCreate('store.workspace.view', 'web'));

    return User::factory()->create()->assignRole('store');
}

/** Consumed 10 in August, so September's Safety Stock is 10 / 26 × 7 = 2.6923. */
function ssItem(): StockItem
{
    $item = StockItem::create([
        'name' => '100% Cut Panel Inspection Report', 'uom' => 'Book', 'category' => 'Stationery',
        'is_active' => true, 'opening_qty' => 20, 'opening_as_on' => '2026-08-01',
    ]);
    StockIssue::create(['stock_item_id' => $item->id, 'issue_date' => '2026-08-15', 'qty' => 10]);

    return $item;
}

it('writes Safety Stock as a round figure', function () {
    expect(Quantity::safety(2.6923))->toBe('3')
        ->and(Quantity::safety(42.54))->toBe('43')
        ->and(Quantity::safety(4.04))->toBe('4')
        ->and(Quantity::safety(0.3))->toBe('0')
        ->and(Quantity::safety(null))->toBe('—')
        ->and(Quantity::whole(1199.6))->toBe('1,200')
        ->and(Quantity::wholePlain(12.5))->toBe('13')
        // Other quantities outside requisitions keep up to 4.
        ->and(Quantity::format(2.6923))->toBe('2.6923')
        ->and(Quantity::excelFormat(2.6923, Quantity::SAFETY_DECIMALS))->toBe('#,##0');
});

it('adds the rounded Safety Stock to To Be Procured', function () {
    $service = app(\App\Services\PurchaseRequisitionService::class);

    // The owner's example: 420 − 180 = 240, + 43 safety = 283.
    expect($service->toBeProcured(420, 180, 42.54))->toBe(283.0)
        // Stock covers the request but not the safety level: buy the gap.
        ->and($service->toBeProcured(5, 6, 2.69))->toBe(2.0)
        // Stock covers request and safety: nothing to buy.
        ->and($service->toBeProcured(10, 15, 4.04))->toBe(0.0)
        // No safety figure: as before, rounded.
        ->and($service->toBeProcured(20, 15, null))->toBe(5.0)
        ->and($service->toBeProcured(10, null, 0))->toBe(10.0);
});

it('shows 3 on the Stock Report screen and keeps the real figure in its Excel', function () {
    ssItem();
    $user = ssUser();

    $this->actingAs($user)->get(route('store.stock.ledger', ['month' => '2026-09']))
        ->assertOk()->assertDontSee('2.69');
    $response = $this->actingAs($user)->get(route('store.stock.ledger.excel', ['month' => '2026-09']))->assertOk()->baseResponse;
    $sheet = IOFactory::load($response->getFile()->getPathname())->getSheet(0);

    $found = false;
    foreach ($sheet->getRowIterator() as $row) {
        foreach ($row->getCellIterator() as $cell) {
            if (trim((string) $cell->getValue()) === 'Safety Stock Level') {
                $column = $cell->getColumn();
                $value = $sheet->getCell($column.($row->getRowIndex() + 1));

                // The cell is a formula now (Cons. Pattern x safety days), so read its result.
                expect(round((float) $value->getCalculatedValue(), 4))->toBe(2.6923)
                    ->and($value->getStyle()->getNumberFormat()->getFormatCode())->toBe('#,##0')
                    ->and($value->getFormattedValue())->toBe('3');
                $found = true;
                break 2;
            }
        }
    }
    expect($found)->toBeTrue();
});

it('shows 3 on a requisition and its PDF source, and leaves the stored snapshot alone', function () {
    $item = ssItem();
    $user = ssUser();
    $sheet = PurchaseRequisition::create([
        'requisition_no' => 'SS-1', 'requisition_date' => '2026-09-10', 'mode' => PurchaseRequisition::MODE_MULTI,
        'level' => PurchaseRequisition::LEVEL_FINAL, 'status' => PurchaseRequisition::STATUS_SUBMITTED,
        'submitted_at' => now(), 'created_by' => $user->id,
    ]);
    $line = PurchaseRequisitionItem::create([
        'purchase_requisition_id' => $sheet->id, 'stock_item_id' => $item->id, 'sort_order' => 1,
        'qty_requested' => 5, 'safety_stock_snapshot' => 2.6923, 'to_be_procured_qty' => 5,
    ]);

    $this->actingAs($user)->get(route('store.stock.requisitions.show', $sheet))
        ->assertOk()->assertDontSee('2.69');

    $rows = app(\App\Services\MonthlyRequisitionReportService::class)->requisitionRows($sheet);
    $pdfHtml = view('store.stock.requisitions._report-rows', [
        'rows' => $rows, 'forExcel' => false, 'typeLabels' => [],
        'qty' => fn ($v) => Quantity::whole($v, '-'), 'money' => fn ($v) => (string) $v, 'date' => fn ($v) => '',
    ])->render();
    expect($pdfHtml)->toContain('>3</td>')->not->toContain('2.69');

    expect((float) $line->fresh()->safety_stock_snapshot)->toBe(2.6923);
});
