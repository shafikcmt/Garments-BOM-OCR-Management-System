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
 * Safety Stock is shown to at most 2 decimals, trailing zeros trimmed
 * (owner, 2026-09-29): 2.6923 → 2.69, 0.3 → 0.3, 3 → 3 — on the Stock
 * Report and the requisitions, screen, PDF and Excel. The figure itself —
 * last month's consumption / 26 × 7 — and every stored value are unchanged;
 * an Excel cell keeps the real number and shows it to 2 decimals.
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

it('writes Safety Stock to at most 2 decimals, trailing zeros trimmed', function () {
    expect(Quantity::safety(2.6923))->toBe('2.69')
        ->and(Quantity::safety(0.3))->toBe('0.3')
        ->and(Quantity::safety(3))->toBe('3')
        ->and(Quantity::safety('3.7692'))->toBe('3.77')
        ->and(Quantity::safety(2.999))->toBe('3')
        ->and(Quantity::safety(null))->toBe('—')
        // Other quantities keep up to 4.
        ->and(Quantity::format(2.6923))->toBe('2.6923')
        ->and(Quantity::excelFormat(2.6923, Quantity::SAFETY_DECIMALS))->toBe('#,##0.0#')
        ->and(Quantity::excelFormat(2.999, Quantity::SAFETY_DECIMALS))->toBe('#,##0');
});

it('shows 2.69 on the Stock Report screen and keeps the real figure in its Excel', function () {
    ssItem();
    $user = ssUser();

    $this->actingAs($user)->get(route('store.stock.ledger', ['month' => '2026-09']))
        ->assertOk()->assertSee('2.69')->assertDontSee('2.6923');

    $response = $this->actingAs($user)->get(route('store.stock.ledger.excel', ['month' => '2026-09']))->assertOk()->baseResponse;
    $sheet = IOFactory::load($response->getFile()->getPathname())->getSheet(0);

    $found = false;
    foreach ($sheet->getRowIterator() as $row) {
        foreach ($row->getCellIterator() as $cell) {
            if (trim((string) $cell->getValue()) === 'Safety Stock Level') {
                $column = $cell->getColumn();
                $value = $sheet->getCell($column.($row->getRowIndex() + 1));

                expect(round((float) $value->getValue(), 4))->toBe(2.6923)
                    ->and($value->getStyle()->getNumberFormat()->getFormatCode())->toBe('#,##0.0#')
                    ->and($value->getFormattedValue())->toBe('2.69');
                $found = true;
                break 2;
            }
        }
    }
    expect($found)->toBeTrue();
});

it('shows 2.69 on a requisition and its PDF source, and leaves the stored snapshot alone', function () {
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
        ->assertOk()->assertSee('2.69')->assertDontSee('2.6923');

    $rows = app(\App\Services\MonthlyRequisitionReportService::class)->requisitionRows($sheet);
    $pdfHtml = view('store.stock.requisitions._report-rows', [
        'rows' => $rows, 'forExcel' => false, 'typeLabels' => [],
        'qty' => fn ($v) => Quantity::format($v, '-'), 'money' => fn ($v) => (string) $v, 'date' => fn ($v) => '',
    ])->render();
    expect($pdfHtml)->toContain('>2.69</td>')->not->toContain('2.6923');

    expect((float) $line->fresh()->safety_stock_snapshot)->toBe(2.6923);
});
