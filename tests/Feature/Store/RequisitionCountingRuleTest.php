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
    // Cancelled (Step 3c), even after the head approved it: counted nowhere —
    // so the 130 below also proves the Stock Summary and the Monthly report
    // leave it out.
    countingSheet($by, $item, 1000, $department + ['status' => PurchaseRequisition::STATUS_CANCELLED, 'department_review_status' => PurchaseRequisition::DEPT_REVIEW_APPROVED]);
    // A different item: a final sheet repeating a department item would be
    // counted once instead, which is its own rule, tested further down.
    $other = StockItem::firstOrCreate(['name' => 'Final Sheet Item'], ['uom' => 'Pcs', 'is_active' => true]);
    countingSheet($by, $other, 100, ['level' => PurchaseRequisition::LEVEL_FINAL, 'status' => PurchaseRequisition::STATUS_APPROVED]);
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
        ['rows'];

    expect($row->sum('requested'))->toBe(130.0);
});

it('applies the same rule to the Monthly Requisition report', function () {
    seedEveryCountingState($this->store, $this->item);

    $rows = app(MonthlyRequisitionReportService::class)->rows(Carbon::create(2026, 8, 1));

    // One row per item (the monthly rule): the thread's two counted lines
    // (10 + 20) are one row, the final sheet's item the other.
    expect($rows)->toHaveCount(2)
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

// --- Interim: a department line a final sheet repeats counts once -----------

/** A department sheet (head-approved) and a final sheet, one line each. */
function repeatSheet(User $by, string $level, array $line, string $date = '2026-08-10'): PurchaseRequisition
{
    $sheet = PurchaseRequisition::create([
        'requisition_no' => 'REP-'.uniqid(),
        'requisition_date' => $date,
        'mode' => PurchaseRequisition::MODE_MULTI,
        'level' => $level,
        'department_role' => $level === PurchaseRequisition::LEVEL_DEPARTMENT ? 'production' : null,
        'status' => PurchaseRequisition::STATUS_SUBMITTED,
        'department_review_status' => $level === PurchaseRequisition::LEVEL_DEPARTMENT ? PurchaseRequisition::DEPT_REVIEW_APPROVED : null,
        'submitted_at' => now(),
        'created_by' => $by->id,
    ]);

    PurchaseRequisitionItem::create($line + ['purchase_requisition_id' => $sheet->id, 'sort_order' => 1]);

    return $sheet;
}

function monthlyQty(): float
{
    return (float) app(MonthlyRequisitionReportService::class)->rows(Carbon::create(2026, 8, 1))->sum('qty_requested');
}

it('counts an item on both a department sheet and that month\'s final sheet once', function () {
    repeatSheet($this->store, PurchaseRequisition::LEVEL_DEPARTMENT, ['stock_item_id' => $this->item->id, 'qty_requested' => 40]);
    repeatSheet($this->store, PurchaseRequisition::LEVEL_FINAL, ['stock_item_id' => $this->item->id, 'qty_requested' => 45]);
    // A typed item, matched on its name whatever the case and spacing.
    repeatSheet($this->store, PurchaseRequisition::LEVEL_DEPARTMENT, ['custom_item_name' => 'Hand  Sanitiser 5L ', 'qty_requested' => 3]);
    repeatSheet($this->store, PurchaseRequisition::LEVEL_FINAL, ['custom_item_name' => 'hand sanitiser 5l', 'qty_requested' => 4]);

    $row = app(RequisitionFulfilmentService::class)->summary($this->store, ['month' => '2026-08'])
        ['rows']->firstWhere('item_id', $this->item->id);

    // The final sheet's figures: 45 and 4, not 85 and 7.
    expect($row['requested'])->toBe(45.0)
        ->and(monthlyQty())->toBe(49.0);
});

it('still counts a department item the final sheet does not carry', function () {
    $other = StockItem::create(['name' => 'Machine Oil', 'uom' => 'Ltr', 'is_active' => true]);
    repeatSheet($this->store, PurchaseRequisition::LEVEL_DEPARTMENT, ['stock_item_id' => $this->item->id, 'qty_requested' => 40]);
    repeatSheet($this->store, PurchaseRequisition::LEVEL_FINAL, ['stock_item_id' => $other->id, 'qty_requested' => 5]);

    expect(monthlyQty())->toBe(45.0);
});

it('counts a department sheet in a month with no final sheet', function () {
    repeatSheet($this->store, PurchaseRequisition::LEVEL_DEPARTMENT, ['stock_item_id' => $this->item->id, 'qty_requested' => 40]);
    // Same item on a final sheet, but in September.
    repeatSheet($this->store, PurchaseRequisition::LEVEL_FINAL, ['stock_item_id' => $this->item->id, 'qty_requested' => 45], '2026-09-02');

    expect(monthlyQty())->toBe(40.0);
});

it('keeps a department user\'s own line in their summary, which never shows the final sheet', function () {
    Role::findOrCreate('production', 'web');
    $raiser = User::factory()->create()->assignRole('production');
    repeatSheet($raiser, PurchaseRequisition::LEVEL_DEPARTMENT, ['stock_item_id' => $this->item->id, 'qty_requested' => 40]);
    repeatSheet($this->store, PurchaseRequisition::LEVEL_FINAL, ['stock_item_id' => $this->item->id, 'qty_requested' => 45]);

    $row = app(RequisitionFulfilmentService::class)->summary($raiser, ['month' => '2026-08'])
        ['rows']->firstWhere('item_id', $this->item->id);

    expect($row['requested'])->toBe(40.0);
});
