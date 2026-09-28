<?php

use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Models\StockItem;
use App\Models\User;
use App\Services\MonthlyRequisitionReportService;
use App\Services\RequisitionFulfilmentService;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/**
 * Step 1, Part A — the interim counting rule.
 *
 * A sheet counts as demand only once nobody is still deciding on it: past
 * Draft, and either a final sheet or a department sheet whose head approved
 * it (or had no head step at all). A sheet still with its head, or returned
 * by one, is not counted — in the Stock Summary and the Monthly Requisition
 * report alike, because both read PurchaseRequisition::countedInTotals().
 *
 * In-memory sqlite. No real record touched.
 */
function countingSheet(User $by, StockItem $item, float $qty, array $attributes): PurchaseRequisition
{
    $requisition = PurchaseRequisition::create($attributes + [
        'requisition_no' => 'CNT-'.uniqid(),
        'requisition_date' => '2026-08-10',
        'mode' => PurchaseRequisition::MODE_MULTI,
        'status' => PurchaseRequisition::STATUS_SUBMITTED,
        'submitted_at' => now(),
        'created_by' => $by->id,
    ]);

    PurchaseRequisitionItem::create([
        'purchase_requisition_id' => $requisition->id,
        'stock_item_id' => $item->id,
        'sort_order' => 1,
        'qty_requested' => $qty,
    ]);

    return $requisition;
}

/** One sheet in every state, with a different quantity each so the sum says which counted. */
function seedEveryCountingState(User $by, StockItem $item): void
{
    $department = ['level' => PurchaseRequisition::LEVEL_DEPARTMENT, 'department_role' => 'merchandising'];

    countingSheet($by, $item, 1, $department + ['department_review_status' => PurchaseRequisition::DEPT_REVIEW_PENDING]);
    countingSheet($by, $item, 2, $department + ['department_review_status' => PurchaseRequisition::DEPT_REVIEW_REJECTED]);
    countingSheet($by, $item, 4, $department + ['status' => PurchaseRequisition::STATUS_DRAFT]);
    countingSheet($by, $item, 10, $department + ['department_review_status' => PurchaseRequisition::DEPT_REVIEW_APPROVED]);
    countingSheet($by, $item, 20, $department + ['department_review_status' => null]);
    countingSheet($by, $item, 100, ['level' => PurchaseRequisition::LEVEL_FINAL, 'status' => PurchaseRequisition::STATUS_APPROVED]);
}

beforeEach(function () {
    Role::findOrCreate('store', 'web');
    $this->store = User::factory()->create()->assignRole('store');
    $this->item = StockItem::create(['name' => 'Sewing Thread 40/2', 'uom' => 'Cone', 'is_active' => true]);
});

it('counts head-approved, no-head and final sheets, and nothing still being decided', function () {
    seedEveryCountingState($this->store, $this->item);

    // 10 (head-approved) + 20 (no head step) + 100 (final).
    // Not 1 (with the head), 2 (returned) or 4 (draft).
    expect((float) PurchaseRequisitionItem::query()
        ->whereHas('requisition', fn ($q) => $q->countedInTotals())
        ->sum('qty_requested'))->toBe(130.0);
});

it('applies the same rule to the Stock Summary', function () {
    seedEveryCountingState($this->store, $this->item);

    $row = app(RequisitionFulfilmentService::class)
        ->summary($this->store, ['month' => '2026-08'])
        ['rows']->firstWhere('item_id', $this->item->id);

    expect($row['requested'])->toBe(130.0);
});

it('applies the same rule to the Monthly Requisition report', function () {
    seedEveryCountingState($this->store, $this->item);

    $rows = app(MonthlyRequisitionReportService::class)->rows(Carbon::create(2026, 8, 1));

    expect($rows)->toHaveCount(3)
        ->and($rows->sum('qty_requested'))->toBe(130.0);
});

it('starts counting a sheet the moment its head approves it', function () {
    $sheet = countingSheet($this->store, $this->item, 7, [
        'level' => PurchaseRequisition::LEVEL_DEPARTMENT,
        'department_review_status' => PurchaseRequisition::DEPT_REVIEW_PENDING,
    ]);

    expect(PurchaseRequisition::countedInTotals()->count())->toBe(0);

    $sheet->update(['department_review_status' => PurchaseRequisition::DEPT_REVIEW_APPROVED]);

    expect(PurchaseRequisition::countedInTotals()->pluck('id')->all())->toBe([$sheet->id]);
});
