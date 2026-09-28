<?php

use App\Models\Department;
use App\Models\IndentSection;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Models\StockItem;
use App\Models\User;
use App\Services\RequirementVsIssueService;
use App\Services\StockSummaryService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The Stock Summary card: Purchase View and Issue View of one period.
 *
 *   - Rendered only for holders of store.requirement_issue.view (Admin
 *     included) — on the requisition screen and on the dashboards. For
 *     anyone else the HTML is absent and its exports return 403.
 *   - Issue View totals are the Requirement vs Issue totals.
 *   - All items, 25 a page; the totals cover every filtered row.
 *
 * In-memory sqlite. No real record touched.
 */
function sumUser(string $role, bool $withPermission = false): User
{
    $user = User::factory()->create()->assignRole(Role::findOrCreate($role, 'web'));
    $user->givePermissionTo(Permission::findOrCreate('store.requisition.view', 'web'));

    if ($withPermission) {
        $user->givePermissionTo(Permission::findOrCreate(RequirementVsIssueService::PERMISSION, 'web'));
    }

    return $user;
}

/** A counted department sheet for a section, one line per [item, qty, rate]. */
function sumSheet(IndentSection $section, array $lines, string $date = '2026-08-10'): void
{
    $sheet = PurchaseRequisition::create([
        'requisition_no' => 'SUM-'.uniqid(),
        'requisition_date' => $date,
        'mode' => PurchaseRequisition::MODE_MULTI,
        'level' => PurchaseRequisition::LEVEL_DEPARTMENT,
        'department_role' => 'production',
        'indent_section_id' => $section->id,
        'status' => PurchaseRequisition::STATUS_SUBMITTED,
        'department_review_status' => PurchaseRequisition::DEPT_REVIEW_APPROVED,
        'submitted_at' => now(),
        'created_by' => User::factory()->create()->id,
    ]);

    foreach ($lines as $i => [$item, $qty, $rate]) {
        PurchaseRequisitionItem::create([
            'purchase_requisition_id' => $sheet->id, 'stock_item_id' => $item->id,
            'sort_order' => $i + 1, 'qty_requested' => $qty, 'rate_appx' => $rate,
        ]);
    }
}

function sumIssue(IndentSection $section, StockItem $item, float $qty): void
{
    DB::table('stock_issues')->insert([
        'stock_item_id' => $item->id, 'is_stock_item' => true, 'issue_date' => '2026-08-15', 'qty' => $qty,
        'indent_section_id' => $section->id, 'department' => $section->name, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function sumSection(string $name = 'CAD & Sample'): IndentSection
{
    Department::firstOrCreate(['key' => 'production'], ['label' => 'Production', 'is_active' => true]);

    return IndentSection::create(['name' => $name, 'department_key' => 'production', 'is_active' => true]);
}

const SUM_AUGUST = ['sum' => ['from' => '2026-08', 'to' => '2026-08']];

// --- Visibility ----------------------------------------------------------------

it('shows the card, with both views, to a holder of the permission and to Admin', function (string $role, bool $grant) {
    $section = sumSection();
    $pencil = StockItem::create(['name' => 'Pencil HB', 'uom' => 'Pcs', 'is_active' => true]);
    sumSheet($section, [[$pencil, 10, 5]]);
    sumIssue($section, $pencil, 4);
    $user = sumUser($role, $grant);

    $this->actingAs($user)->get(route('store.stock.requisitions.index', SUM_AUGUST))
        ->assertOk()
        ->assertSee('id="stock-summary"', false)
        ->assertSee('Purchase View')->assertSee('Issue View')
        ->assertSee('Pencil HB')->assertSee('Current Stock')->assertSee('To Purchase');

    $this->actingAs($user)->get(route('store.stock.requisitions.index', ['sum' => SUM_AUGUST['sum'] + ['view' => 'issue']]))
        ->assertOk()
        ->assertSee('Approx Rate')->assertSee('Issue Bal. Value')->assertSee('CAD &amp; Sample', false)->assertSee('40.0%');

    foreach (['purchase', 'issue'] as $view) {
        $query = ['sum' => SUM_AUGUST['sum'] + ['view' => $view]];
        $this->actingAs($user)->get(route('store.stock.requisitions.stock-summary.excel', $query))->assertOk();
        $this->actingAs($user)->get(route('store.stock.requisitions.stock-summary.pdf', $query))->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
})->with([
    'store staff with the permission' => ['store', true],
    'a department user with the permission' => ['production', true],
    'admin' => ['admin', false],
]);

it('renders no card, and refuses its exports, for everyone else', function (string $role, bool $sectionHead) {
    $section = sumSection();
    sumSheet($section, [[StockItem::create(['name' => 'Pencil HB', 'uom' => 'Pcs', 'is_active' => true]), 10, 5]]);
    $user = sumUser($role);
    if ($sectionHead) {
        $section->update(['head_user_id' => $user->id]);
    }

    $this->actingAs($user)->get(route('store.stock.requisitions.index', SUM_AUGUST))
        ->assertOk()
        ->assertDontSee('id="stock-summary"', false)
        ->assertDontSee('Purchase View');

    $this->actingAs($user)->get(route('store.stock.requisitions.stock-summary.excel', SUM_AUGUST))->assertForbidden();
    $this->actingAs($user)->get(route('store.stock.requisitions.stock-summary.pdf', SUM_AUGUST))->assertForbidden();
})->with([
    'a department user' => ['production', false],
    'a section head' => ['production', true],
    'store staff' => ['store', false],
    'management' => ['management', false],
]);

it('shows the dashboard widget only with the permission', function () {
    $without = sumUser('store');
    $with = sumUser('store', true);

    $this->actingAs($without)->get(route('store.dashboard'))->assertOk()->assertDontSee('Open Stock Summary');
    $this->actingAs($with)->get(route('store.dashboard'))->assertOk()->assertSee('Open Stock Summary');
});

// --- Figures -------------------------------------------------------------------

it('gives the Issue View the same totals as Requirement vs Issue for the same filters', function () {
    $cad = sumSection('CAD & Sample');
    $cut = sumSection('Cutting');
    [$pencil, $paper] = [StockItem::create(['name' => 'Pencil', 'uom' => 'Pcs', 'is_active' => true]), StockItem::create(['name' => 'Paper', 'uom' => 'Ream', 'is_active' => true])];
    sumSheet($cad, [[$pencil, 10, 5], [$paper, 20, 400]]);
    sumSheet($cut, [[$paper, 5, 400]]);
    sumIssue($cad, $pencil, 10);
    sumIssue($cut, $paper, 6);

    $input = ['from' => '2026-08', 'to' => '2026-08', 'sections' => [(string) $cad->id, (string) $cut->id]];
    $summary = app(StockSummaryService::class)->build(sumUser('store', true), $input);

    $service = app(RequirementVsIssueService::class);
    $options = $service->options();
    $rvi = $service->build($service->filters($input, $options), $options);

    expect($summary['issue']['summary'])->toBe($rvi['summary'])
        ->and(collect($summary['cards']['issue'])->pluck('value', 'label')['Requested Value'])->toEqual($rvi['summary']['requested_value'])
        ->and(collect($summary['cards']['issue'])->pluck('value', 'label')['Issued %'])->toEqual($rvi['summary']['pct']);
});

it('lists every item 25 a page, with totals over all filtered rows', function () {
    $section = sumSection();
    $lines = [];
    foreach (range(1, 30) as $n) {
        $lines[] = [StockItem::create(['name' => sprintf('Item %02d', $n), 'uom' => 'Pcs', 'is_active' => true]), $n, 1];
    }
    sumSheet($section, $lines);
    $user = sumUser('store', true);

    $summary = app(StockSummaryService::class)->build($user, SUM_AUGUST['sum']);
    expect($summary['purchase']['rows'])->toHaveCount(30)
        ->and($summary['purchase']['totals']['requested'])->toEqual(465.0);   // 1 + 2 + … + 30

    $page1 = $this->actingAs($user)->get(route('store.stock.requisitions.index', SUM_AUGUST))->assertOk()->getContent();
    $page2 = $this->actingAs($user)->get(route('store.stock.requisitions.index', SUM_AUGUST + ['sum_page' => 2]))->assertOk()->getContent();

    // Highest To Purchase first: Item 30 opens page 1, Item 01 closes page 2.
    // Read from the table only — the chart above it covers every row.
    $table = fn (string $html) => substr($html, strpos($html, 'gx-sum-table'));
    [$page1, $page2] = [$table($page1), $table($page2)];
    expect($page1)->toContain('Item 30')->not->toContain('Item 05')->toContain('465')
        ->and($page2)->toContain('Item 01')->not->toContain('Item 30')->toContain('465');
});

it('keeps the list filters and the summary filters apart', function () {
    $user = sumUser('store', true);

    $html = $this->actingAs($user)->get(route('store.stock.requisitions.index', [
        'month' => '2026-08', 'search' => 'listsearch', 'sum' => ['from' => '2026-08', 'to' => '2026-08', 'search' => 'pencil'],
    ]))->assertOk()->getContent();

    // The view switch keeps the summary filters and the list's own.
    expect($html)->toContain('sum%5Bsearch%5D=pencil')->toContain('search=listsearch')
        ->toContain('name="sum[search]"')->toContain('value="pencil"');
});

// --- Cards follow the active view ------------------------------------------------

/** Two sections, two items, some issued, for the card tests. */
function sumCardData(): array
{
    $cad = sumSection('CAD & Sample');
    $cut = sumSection('Cutting');
    [$pencil, $paper] = [StockItem::create(['name' => 'Pencil', 'uom' => 'Pcs', 'is_active' => true]), StockItem::create(['name' => 'Paper', 'uom' => 'Ream', 'is_active' => true])];
    sumSheet($cad, [[$pencil, 10, 5], [$paper, 20, 400]]);
    sumSheet($cut, [[$paper, 5, 400]]);
    sumIssue($cad, $pencil, 10);
    sumIssue($cut, $paper, 6);

    return [$cad, $cut, $pencil, $paper];
}

it('makes the Purchase View cards equal its totals row', function () {
    sumCardData();
    $user = sumUser('store', true);
    $s = app(StockSummaryService::class)->build($user, SUM_AUGUST['sum']);

    $cards = collect($s['cards']['purchase'])->pluck('value', 'label');
    expect($cards->keys()->all())->toBe(['Requested', 'Current Stock', 'Purchased', 'To Purchase'])
        ->and($cards['Requested'])->toEqual($s['purchase']['totals']['requested'])
        ->and($cards['Current Stock'])->toEqual($s['purchase']['totals']['stock_in_hand'])
        ->and($cards['Purchased'])->toEqual($s['purchase']['totals']['purchased'])
        ->and($cards['To Purchase'])->toEqual($s['purchase']['totals']['pending'])
        ->and($cards['Requested'])->toEqual(35.0);

    $this->actingAs($user)->get(route('store.stock.requisitions.index', SUM_AUGUST))->assertOk()
        ->assertSee('data-card="Requested">35<', false)
        ->assertSee('data-card="To Purchase">35<', false)
        ->assertDontSee('data-card="Issued %"', false);
});

it('makes the Issue View cards equal its totals row', function () {
    sumCardData();
    $user = sumUser('store', true);
    $s = app(StockSummaryService::class)->build($user, SUM_AUGUST['sum'] + ['view' => 'issue']);
    $sum = $s['issue']['summary'];

    $cards = collect($s['cards']['issue'])->pluck('value', 'label');
    expect($cards->keys()->all())->toBe(['Requested Qty', 'Requested Value', 'Issued Qty', 'Issued Value', 'Issued %', 'Balance Value'])
        ->and($cards['Requested Qty'])->toEqual($sum['requested_qty'])
        ->and($cards['Requested Value'])->toEqual($sum['requested_value'])
        ->and($cards['Issued Qty'])->toEqual($sum['issued_qty'])
        ->and($cards['Issued Value'])->toEqual($sum['issued_value'])
        ->and($cards['Issued %'])->toEqual($sum['pct'])
        ->and($cards['Balance Value'])->toEqual($sum['balance_value']);

    // 10×5 + 20×400 + 5×400 = 10,050 requested; 10×5 + 6×400 = 2,450 issued.
    $this->actingAs($user)->get(route('store.stock.requisitions.index', ['sum' => SUM_AUGUST['sum'] + ['view' => 'issue']]))->assertOk()
        ->assertSee('data-card="Requested Value">10,050.00<', false)
        ->assertSee('data-card="Issued Value">2,450.00<', false)
        ->assertSee('data-card="Balance Value">7,600.00<', false)
        ->assertDontSee('data-card="Current Stock"', false);
});

it('ignores Department and Section in the Purchase View, and keeps them for the Issue View', function () {
    [$cad, $cut, $pencil, $paper] = sumCardData();
    $user = sumUser('store', true);
    $input = SUM_AUGUST['sum'] + ['sections' => [(string) $cad->id]];

    $purchase = app(StockSummaryService::class)->build($user, $input);
    $issue = app(StockSummaryService::class)->build($user, $input + ['view' => 'issue']);

    // Company-wide: Cutting's 5 paper still counts in the Purchase View.
    expect($purchase['purchase']['totals']['requested'])->toEqual(35.0)
        ->and($purchase['title'])->toBe('Month of August 2026')
        // …while the Issue View is CAD & Sample only.
        ->and($issue['issue']['rows']->pluck('section_id')->unique()->all())->toBe([$cad->id])
        ->and($issue['title'])->toContain('Section: CAD & Sample');

    $html = $this->actingAs($user)->get(route('store.stock.requisitions.index', ['sum' => $input]))->assertOk()->getContent();

    expect($html)->toContain('Purchase and stock are company-wide.')
        // The pickers are disabled, their values ride along hidden…
        ->toContain('<input type="hidden" name="sum[sections][]" value="'.$cad->id.'">')
        ->toMatch('/id="sumSections"[^>]*disabled/')
        // …and the Issue View link still carries the section.
        ->toContain('sum%5Bsections%5D%5B0%5D='.$cad->id);
});

// --- Charts on the card --------------------------------------------------------------

it('draws the Issue View charts from its own table, and one chart on the Purchase View', function () {
    sumCardData();
    $user = sumUser('store', true);
    $s = app(StockSummaryService::class)->build($user, SUM_AUGUST['sum'] + ['view' => 'issue']);

    expect($s['charts']['issue']['pct'])->toBe($s['issue']['summary']['pct'])
        ->and(array_sum(array_column($s['charts']['issue']['statuses'], 'count')))->toBe($s['issue']['rows']->count())
        ->and(round(array_sum(array_column($s['charts']['issue']['sections'], 'requested')), 2))->toEqual(round($s['issue']['summary']['requested_value'], 2))
        // Purchase chart: top items by To Purchase, figures from the table rows.
        ->and(collect($s['charts']['purchase']['items'])->sum('pending'))->toEqual($s['purchase']['totals']['pending']);

    $this->actingAs($user)->get(route('store.stock.requisitions.index', ['sum' => SUM_AUGUST['sum'] + ['view' => 'issue']]))
        ->assertOk()->assertSee('Issued % (by value)')->assertDontSee('Top 10 items to purchase');
    $this->actingAs($user)->get(route('store.stock.requisitions.index', SUM_AUGUST))
        ->assertOk()->assertSee('Top 10 items to purchase')->assertDontSee('Issued % (by value)');
});

it('keeps the dashboard card free of charts', function () {
    sumCardData();
    $this->actingAs(sumUser('store', true))->get(route('store.dashboard'))->assertOk()
        ->assertSee('Open Stock Summary')
        ->assertDontSee('Top 10 items to purchase')->assertDontSee('Issued % (by value)');
});
