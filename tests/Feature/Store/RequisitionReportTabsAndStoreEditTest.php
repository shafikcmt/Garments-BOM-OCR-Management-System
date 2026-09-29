<?php

use App\Exports\MonthlyRequisitionReportExport;
use App\Models\IndentSection;
use App\Models\ItemCategory;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Models\StockItem;
use App\Models\User;
use App\Services\MonthlyRequisitionReportService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Two things, both about trusting what the workbook and the review queue say.
 *
 * PART 1 — every tab of the monthly workbook is the SAME calculation. The ALL
 * tab and each department tab are not two code paths that happen to agree:
 * rows() computes the figures once, byCategory() only groups that same
 * collection in memory, and every sheet renders one shared partial. These
 * tests pin that, so a later change that gives one tab its own sums fails here
 * rather than in a management meeting.
 *
 * PART 2 — the Store can correct a department's sheet on the way through,
 * without that correction changing whose requisition it is or where it sits in
 * the flow.
 *
 * In-memory sqlite. No real record touched.
 */

function reportTabsUser(): User
{
    $role = Role::findOrCreate('store', 'web');

    foreach (['store.requisition.review', 'store.edit'] as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return User::factory()->create()->assignRole('store');
}

function reportTabsDepartmentUser(): User
{
    Role::findOrCreate('merchant', 'web');

    $role = Role::findOrCreate('merchant-requisition', 'web');

    foreach (['store.requisition.view', 'store.requisition.create'] as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return User::factory()->create()->assignRole('merchant', 'merchant-requisition');
}

/**
 * Two categories with different quantities, so a department tab's total can be
 * told apart from the whole month's.
 */
function reportTabsFixture(User $by): void
{
    $stationery = ItemCategory::create(['name' => 'Stationery']);
    $production = ItemCategory::create(['name' => 'Production']);

    $pen = StockItem::create([
        'name' => 'Bal Pen', 'uom' => 'pcs', 'is_active' => true,
        'category' => 'Stationery', 'item_category_id' => $stationery->id,
    ]);
    $needle = StockItem::create([
        'name' => 'Needle DBx1 90/14', 'uom' => 'Pkt', 'is_active' => true,
        'category' => 'Production', 'item_category_id' => $production->id,
    ]);

    $requisition = PurchaseRequisition::create([
        'requisition_no' => 'REQ-TABS-1',
        'requisition_date' => '2026-08-10',
        'mode' => PurchaseRequisition::MODE_MULTI,
        'status' => PurchaseRequisition::STATUS_SUBMITTED,
        'submitted_at' => now(),
        'created_by' => $by->id,
    ]);

    foreach ([[$pen, $stationery, 50, 10], [$needle, $production, 200, 25]] as $i => [$item, $cat, $qty, $rate]) {
        PurchaseRequisitionItem::create([
            'purchase_requisition_id' => $requisition->id,
            'stock_item_id' => $item->id,
            'item_category_id' => $cat->id,
            'sort_order' => $i,
            'uom_snapshot' => $item->uom,
            'qty_requested' => $qty,
            'to_be_procured_qty' => $qty,
            'rate_appx' => $rate,
            'amount' => $qty * $rate,
        ]);
    }
}

/** The <thead> block of a rendered sheet — its column set and order. */
function reportHeaderBlock(string $html): string
{
    preg_match('/<thead>(.*?)<\/thead>/s', $html, $m);

    return preg_replace('/\s+/', ' ', $m[1] ?? '');
}

// --- PART 1: every tab, one calculation --------------------------------------

it('builds the ALL tab and every department tab from one row set', function () {
    reportTabsFixture(reportTabsUser());

    $service = app(MonthlyRequisitionReportService::class);
    $rows = $service->rows($service->resolveMonth('2026-08'));
    $byCategory = $service->byCategory($rows);

    // byCategory() groups the SAME array elements rows() produced — it does not
    // recompute. Identity, not equality, is the point.
    expect($byCategory->keys()->sort()->values()->all())->toBe(['Production', 'Stationery']);
    expect($byCategory->flatten(1)->count())->toBe($rows->count());

    foreach ($byCategory as $group) {
        foreach ($group as $row) {
            expect($rows->contains($row))->toBeTrue();
        }
    }
});

it('gives every tab the identical column set and order', function () {
    reportTabsFixture(reportTabsUser());

    $sheets = (new MonthlyRequisitionReportExport(reportTabsPayload()))->sheets();

    expect(array_map(fn ($s) => $s->title(), $sheets))->toBe(['ALL', 'Production', 'Stationery']);

    // Byte-for-byte the same header block on every tab — the one thing a
    // per-tab divergence would show up in first.
    $headers = array_map(fn ($s) => reportHeaderBlock($s->view()->render()), $sheets);

    expect(array_unique($headers))->toHaveCount(1);

    // And it is the agreed column set, not merely a consistent wrong one.
    foreach ([
        'SL', 'Name of Item', 'Uom', 'Specification / Brand &amp; Model',
        'Type (Replace/ New)', 'User Dept./ Section',
        'Stock Details', 'Qty Requested', 'Stock in Hand', 'Consumption (Last Month)',
        'Last Purchase Details', 'To Be Procured', 'Rate Appx.', 'Amount',
        'Store Acknowledgement on GRN', 'Accounts Acknowledgement on Pmt',
        'Pending Qty', 'Signature', 'Remarks',
    ] as $heading) {
        expect($headers[0])->toContain($heading);
    }
});

it('totals each tab on its own rows, with the same summation', function () {
    reportTabsFixture(reportTabsUser());

    $sheets = (new MonthlyRequisitionReportExport(reportTabsPayload()))->sheets();
    $rendered = [];

    foreach ($sheets as $sheet) {
        $rendered[$sheet->title()] = $sheet->view()->render();
    }

    // Stationery 50 x 10 = 500, Production 200 x 25 = 5000, ALL = 5500.
    //
    // Matched on the total CELL, not loose in the page: a sheet renders with
    // forExcel so the figures are bare (5000.00, no separator — separators are
    // FormatsStoreSheet's job), and "500.00" would otherwise match inside
    // "5500.00" and pass for the wrong reason.
    $total = fn (string $amount) => '<td class="num">'.$amount.'</td>';

    expect($rendered['Stationery'])->toContain($total('500.00'))
        ->and($rendered['Stationery'])->not->toContain($total('5500.00'));

    expect($rendered['Production'])->toContain($total('5000.00'))
        ->and($rendered['Production'])->not->toContain($total('5500.00'));

    expect($rendered['ALL'])->toContain($total('5500.00'));

    // The letterhead's Section is the section(s) or ALL — never the category
    // (owner, 2026-09-29) — and a tab carries no total of its own, so a
    // category tab can never show the month's amount over its own rows.
    expect($rendered['Production'])->toContain('>ALL</td>')
        ->not->toContain('>Production</td>')
        ->and($rendered['Production'])->not->toContain('>5500.00</td>');
    expect($rendered['Stationery'])->toContain('>REQ-TABS-1</td>');

    // A department tab prints only its own items.
    expect($rendered['Stationery'])->toContain('Bal Pen')->not->toContain('Needle DBx1 90/14');
    expect($rendered['Production'])->toContain('Needle DBx1 90/14')->not->toContain('Bal Pen');
    expect($rendered['ALL'])->toContain('Bal Pen')->toContain('Needle DBx1 90/14');
});

/** The controller's payload, built the way the download builds it. */
function reportTabsPayload(): array
{
    $service = app(MonthlyRequisitionReportService::class);
    $month = $service->resolveMonth('2026-08');
    $rows = $service->rows($month);

    return [
        'rows' => $rows,
        'byCategory' => $service->byCategory($rows),
        'summary' => $service->summary($rows),
        'filters' => [],
        'month' => $month->format('Y-m'),
        'monthLabel' => $month->format('F Y'),
        'categories' => $service->categoriesInMonth($month),
        'merge' => false,
        'title' => 'Purchase Requisition',
    ];
}

// --- PART 2: the Store corrects a department's sheet -------------------------

it('opens the ordinary edit form for Store on a submitted department sheet', function () {
    reportTabsFixture(reportTabsDepartmentUser());
    $requisition = PurchaseRequisition::first();

    // Submitted, so the requester's own draft window is shut...
    expect($requisition->isEditable())->toBeFalse()
        ->and($requisition->isOpenToStoreCorrection())->toBeTrue();

    // ...and the Store's correction window is open. Same route, same form.
    $this->actingAs(reportTabsUser())
        ->get(route('store.stock.requisitions.edit', $requisition))
        ->assertOk()
        ->assertSee('Bal Pen');
});

it('offers Edit on the Requested queue', function () {
    reportTabsFixture(reportTabsDepartmentUser());
    $requisition = PurchaseRequisition::first();

    $this->actingAs(reportTabsUser())
        ->get(route('store.stock.requisitions.requested'))
        ->assertOk()
        ->assertSee(route('store.stock.requisitions.edit', $requisition), false);
});

it('keeps the requester and the stage when Store corrects it', function () {
    $department = reportTabsDepartmentUser();
    reportTabsFixture($department);

    $requisition = PurchaseRequisition::first();
    $requisition->update(['status' => PurchaseRequisition::STATUS_STORE_REVIEWED]);

    $store = reportTabsUser();
    $item = StockItem::where('name', 'Bal Pen')->first();

    $this->actingAs($store)->put(route('store.stock.requisitions.update', $requisition), [
        'action' => 'submit',
        'mode' => PurchaseRequisition::MODE_MULTI,
        'requisition_date' => '2026-08-10',
        'items' => [['stock_item_id' => $item->id, 'qty_requested' => 75]],
    ])->assertRedirect();

    $requisition->refresh();

    // The correction landed...
    expect((float) $requisition->items->first()->qty_requested)->toBe(75.0);

    // ...the sheet is still the department's...
    expect($requisition->created_by)->toBe($department->id);

    // ...it did not fall back to Submitted or Draft just because it was saved...
    expect($requisition->status)->toBe(PurchaseRequisition::STATUS_STORE_REVIEWED);

    // ...and who corrected it is recorded, on the columns that already existed.
    expect($requisition->checked_by)->toBe($store->id)
        ->and($requisition->checked_at)->not->toBeNull();
});

it('shuts the correction window once the requisition is approved', function () {
    reportTabsFixture(reportTabsDepartmentUser());

    $requisition = PurchaseRequisition::first();
    $requisition->update(['status' => PurchaseRequisition::STATUS_APPROVED]);

    // A signed sheet must not be editable underneath the signature.
    expect($requisition->isOpenToStoreCorrection())->toBeFalse();

    $this->actingAs(reportTabsUser())
        ->get(route('store.stock.requisitions.edit', $requisition))
        ->assertForbidden();
});

it('does not let a department user edit its own submitted sheet', function () {
    $department = reportTabsDepartmentUser();
    reportTabsFixture($department);

    // The original rule is untouched: once submitted, the requester cannot
    // quietly rewrite figures the Store has already read.
    $this->actingAs($department)
        ->get(route('store.stock.requisitions.edit', PurchaseRequisition::first()))
        ->assertForbidden();
});
