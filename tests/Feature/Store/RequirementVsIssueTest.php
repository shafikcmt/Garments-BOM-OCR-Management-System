<?php

use App\Exports\RequirementVsIssueExport;
use App\Models\Department;
use App\Models\IndentSection;
use App\Models\ItemCategory;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Models\StockItem;
use App\Models\User;
use App\Services\RequirementVsIssueService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Requirement vs Issue — the Store Head's requested-against-issued view.
 *
 *   - Access: the current Store head holding store.requirement_issue.view,
 *     and Admin. Everyone else: no tab, 403 on the page and both exports.
 *   - Requested: department sheets by section, Part A counting rule; a final
 *     sheet adds only what its month's department lines did not already
 *     count, to "Not assigned". Nothing is counted twice.
 *   - Issued: by section + item + period.
 *   - Issued % is value-weighted; Not Requested stays out of it.
 *
 * In-memory sqlite. No real record touched.
 */
function rviUser(string $role, array $permissions = []): User
{
    $user = User::factory()->create()->assignRole(Role::findOrCreate($role, 'web'));

    foreach (array_merge(['store.requisition.view'], $permissions) as $p) {
        $user->givePermissionTo(Permission::findOrCreate($p, 'web'));
    }

    return $user;
}

function rviStoreHead(): User
{
    $head = rviUser('store', [RequirementVsIssueService::PERMISSION]);
    Department::updateOrCreate(['key' => 'store'], ['label' => 'Store', 'head_user_id' => $head->id, 'is_active' => true]);

    return $head;
}

function rviSection(string $name, ?string $department = 'production'): IndentSection
{
    if ($department) {
        Department::firstOrCreate(['key' => $department], ['label' => ucfirst($department), 'is_active' => true]);
    }

    return IndentSection::create(['name' => $name, 'department_key' => $department, 'is_active' => true]);
}

function rviItem(string $name, ?ItemCategory $category = null): StockItem
{
    return StockItem::create(['name' => $name, 'uom' => 'Pcs', 'is_active' => true, 'item_category_id' => $category?->id]);
}

/** A counted department sheet (head-approved) for one section. */
function rviRequest(?IndentSection $section, array $lines, array $attributes = []): PurchaseRequisition
{
    $sheet = PurchaseRequisition::create($attributes + [
        'requisition_no' => 'RVI-'.uniqid(),
        'requisition_date' => '2026-08-10',
        'mode' => PurchaseRequisition::MODE_MULTI,
        'level' => PurchaseRequisition::LEVEL_DEPARTMENT,
        'department_role' => 'production',
        'indent_section_id' => $section?->id,
        'status' => PurchaseRequisition::STATUS_SUBMITTED,
        'department_review_status' => PurchaseRequisition::DEPT_REVIEW_APPROVED,
        'submitted_at' => now(),
        'created_by' => User::factory()->create()->id,
    ]);

    foreach ($lines as $i => [$item, $qty, $rate]) {
        PurchaseRequisitionItem::create([
            'purchase_requisition_id' => $sheet->id,
            'stock_item_id' => $item->id,
            'sort_order' => $i + 1,
            'qty_requested' => $qty,
            'rate_appx' => $rate,
        ]);
    }

    return $sheet;
}

function rviFinal(array $lines, string $date = '2026-08-12'): PurchaseRequisition
{
    return rviRequest(null, $lines, [
        'level' => PurchaseRequisition::LEVEL_FINAL,
        'department_role' => null,
        'department_review_status' => null,
        'requisition_date' => $date,
    ]);
}

function rviIssue(?IndentSection $section, StockItem $item, float $qty, string $date = '2026-08-15'): void
{
    DB::table('stock_issues')->insert([
        'stock_item_id' => $item->id,
        'is_stock_item' => true,
        'issue_date' => $date,
        'qty' => $qty,
        'indent_section_id' => $section?->id,
        'department' => $section?->name,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** The service run as the controller runs it. */
function rviBuild(array $query = []): array
{
    $service = app(RequirementVsIssueService::class);
    $options = $service->options();
    $filters = $service->filters($query + ['from' => '2026-08', 'to' => '2026-08'], $options);

    return $service->build($filters, $options) + ['filters' => $filters, 'options' => $options];
}

function rviRow(array $result, StockItem $item, ?IndentSection $section): ?array
{
    return $result['rows']->first(fn ($r) => $r['item_id'] === $item->id && $r['section_id'] === $section?->id);
}

// --- Access ------------------------------------------------------------------

it('lets the Store head open the page and both exports, and shows the tab', function () {
    $head = rviStoreHead();

    $this->actingAs($head)->get(route('store.stock.requisitions.index'))->assertOk()->assertSee('Requirement vs Issue');
    $this->actingAs($head)->get(route('store.stock.requisitions.requirement-vs-issue'))->assertOk()->assertSee('No matching items');
    $this->actingAs($head)->get(route('store.stock.requisitions.requirement-vs-issue.excel'))->assertOk();
    $this->actingAs($head)->get(route('store.stock.requisitions.requirement-vs-issue.pdf'))->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('lets Admin in through the super-admin rule', function () {
    $admin = rviUser('admin');

    $this->actingAs($admin)->get(route('store.stock.requisitions.requirement-vs-issue'))->assertOk();
});

it('refuses everyone else, including Store staff holding the permission', function (string $role, bool $withPermission) {
    rviStoreHead();
    $user = rviUser($role, $withPermission ? [RequirementVsIssueService::PERMISSION] : []);

    $this->actingAs($user)->get(route('store.stock.requisitions.index'))->assertOk()->assertDontSee('Requirement vs Issue');

    foreach (['', '.excel', '.pdf'] as $suffix) {
        $this->actingAs($user)->get(route('store.stock.requisitions.requirement-vs-issue'.$suffix))->assertForbidden();
    }
})->with([
    'store staff' => ['store', false],
    'store staff with a stale grant' => ['store', true],
    'management' => ['management', false],
    'merchandising' => ['merchant', false],
    'commercial' => ['commercial', false],
    'accounts' => ['account', false],
]);

it('gives the permission to the current Store head only when migrating', function () {
    $head = User::factory()->create();
    $staff = User::factory()->create()->assignRole(Role::findOrCreate('store', 'web'));
    Department::updateOrCreate(['key' => 'store'], ['label' => 'Store', 'head_user_id' => $head->id, 'is_active' => true]);
    Permission::where('name', RequirementVsIssueService::PERMISSION)->delete();
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    (require database_path('migrations/2026_09_29_000001_add_requirement_issue_permission.php'))->up();

    expect($head->fresh()->hasDirectPermission(RequirementVsIssueService::PERMISSION))->toBeTrue()
        ->and($staff->fresh()->hasPermissionTo(RequirementVsIssueService::PERMISSION))->toBeFalse()
        ->and(Role::findByName('store', 'web')->hasPermissionTo(RequirementVsIssueService::PERMISSION))->toBeFalse();
});

// --- Numbers -----------------------------------------------------------------

it('computes rows, statuses and a value-weighted Issued %', function () {
    $cad = rviSection('CAD & Sample');
    [$pencil, $paper, $tape, $glue] = [rviItem('Pencil'), rviItem('Paper'), rviItem('Tape'), rviItem('Glue')];

    rviRequest($cad, [[$pencil, 10, 5], [$paper, 100, 20], [$tape, 4, 50], [$glue, 2, 10]]);
    rviIssue($cad, $pencil, 10);   // fully
    rviIssue($cad, $paper, 25);    // partial
    rviIssue($cad, $tape, 6);      // over — balance −2
    // glue: not issued

    $result = rviBuild();

    expect(rviRow($result, $pencil, $cad)['status'])->toBe('fully_issued')
        ->and(rviRow($result, $paper, $cad)['status'])->toBe('partial')
        ->and(rviRow($result, $glue, $cad)['status'])->toBe('not_issued');

    $tapeRow = rviRow($result, $tape, $cad);
    expect($tapeRow['status'])->toBe('over_issued')
        ->and($tapeRow['balance_qty'])->toEqual(-2.0)
        ->and($tapeRow['balance_value'])->toEqual(-100.0)
        ->and($tapeRow['pct'])->toEqual(100.0);

    // Requested value 50 + 2000 + 200 + 20 = 2270; issued 50 + 500 + 300 = 850.
    // Value-weighted 37.4%, not the average of the row percentages (100, 25, 100, 0 → 56.25%).
    expect($result['summary']['requested_value'])->toEqual(2270.0)
        ->and($result['summary']['issued_value'])->toEqual(850.0)
        ->and(round($result['summary']['pct'], 1))->toEqual(37.4);
});

it('never counts one quantity twice between department and final sheets', function () {
    $cad = rviSection('CAD & Sample');
    $cutting = rviSection('Cutting');
    [$pencil, $paper, $ink] = [rviItem('Pencil'), rviItem('Paper'), rviItem('Ink')];

    rviRequest($cad, [[$pencil, 30, 5]]);
    rviRequest($cutting, [[$pencil, 20, 5]]);
    // Store's final sheet: pencils 60 (50 already on department sheets →
    // 10 not assigned), paper 40 (on no department sheet → all 40), ink 5
    // (department sheet in September only → all 5).
    rviFinal([[$pencil, 60, 5], [$paper, 40, 20], [$ink, 5, 100]]);
    rviRequest($cad, [[$ink, 5, 100]], ['requisition_date' => '2026-09-03']);

    $result = rviBuild();

    expect(rviRow($result, $pencil, $cad)['requested_qty'])->toEqual(30.0)
        ->and(rviRow($result, $pencil, $cutting)['requested_qty'])->toEqual(20.0)
        ->and(rviRow($result, $pencil, null)['requested_qty'])->toEqual(10.0)
        ->and(rviRow($result, $paper, null)['requested_qty'])->toEqual(40.0)
        ->and(rviRow($result, $ink, null)['requested_qty'])->toEqual(5.0)
        ->and(rviRow($result, $pencil, null)['section_name'])->toBe('Not assigned to a section');

    // Grand total = 60 pencils + 40 paper + 5 ink — the final sheet's figures,
    // never department + final (110 pencils).
    expect($result['summary']['requested_qty'])->toEqual(105.0);

    // A second final sheet the same month: 70 on final sheets against 50 on
    // department sheets → 20 not assigned, and the total follows (70 + 40 + 5).
    rviFinal([[$pencil, 10, 5]], '2026-08-20');
    $again = rviBuild();
    expect(rviRow($again, $pencil, null)['requested_qty'])->toEqual(20.0)
        ->and($again['summary']['requested_qty'])->toEqual(115.0);
});

it('leaves nothing unassigned when the final sheet asks for less than the departments', function () {
    $cad = rviSection('CAD & Sample');
    $pencil = rviItem('Pencil');
    rviRequest($cad, [[$pencil, 30, 5]]);
    rviFinal([[$pencil, 25, 5]]);

    $result = rviBuild();

    expect(rviRow($result, $pencil, null))->toBeNull()
        ->and($result['summary']['requested_qty'])->toEqual(30.0);
});

it('leaves out drafts, returned and still-with-head sheets', function () {
    $cad = rviSection('CAD & Sample');
    $pencil = rviItem('Pencil');

    rviRequest($cad, [[$pencil, 1, 5]], ['status' => PurchaseRequisition::STATUS_DRAFT, 'department_review_status' => null]);
    rviRequest($cad, [[$pencil, 2, 5]], ['department_review_status' => PurchaseRequisition::DEPT_REVIEW_REJECTED]);
    rviRequest($cad, [[$pencil, 4, 5]], ['department_review_status' => PurchaseRequisition::DEPT_REVIEW_PENDING]);
    rviRequest($cad, [[$pencil, 8, 5]]);

    expect(rviRow(rviBuild(), $pencil, $cad)['requested_qty'])->toEqual(8.0);
});

it('shows an item issued without a requisition as Not Requested, outside Issued %', function () {
    $cad = rviSection('CAD & Sample');
    [$pencil, $oil] = [rviItem('Pencil'), rviItem('Machine Oil')];
    DB::table('stock_purchases')->insert(['stock_item_id' => $oil->id, 'purchase_date' => '2026-07-01', 'qty' => 10, 'unit_price' => 300, 'created_at' => now(), 'updated_at' => now()]);

    rviRequest($cad, [[$pencil, 10, 5]]);
    rviIssue($cad, $pencil, 5);
    rviIssue($cad, $oil, 2);

    $result = rviBuild();
    $oilRow = rviRow($result, $oil, $cad);

    expect($oilRow['status'])->toBe('not_requested')
        ->and($oilRow['requested_qty'])->toEqual(0.0)
        ->and($oilRow['rate'])->toEqual(300.0)
        ->and($oilRow['issued_value'])->toEqual(600.0)
        ->and($oilRow['pct'])->toBeNull()
        ->and($result['summary']['pct'])->toEqual(50.0)
        ->and($result['summary']['issued_value'])->toEqual(25.0)
        ->and($result['summary']['not_requested_value'])->toEqual(600.0)
        ->and($result['summary']['counts']['not_requested'])->toBe(1);
});

it('shows a dash for an item with no rate and keeps it out of the values', function () {
    $cad = rviSection('CAD & Sample');
    [$pencil, $mystery] = [rviItem('Pencil'), rviItem('Mystery Part')];

    rviRequest($cad, [[$pencil, 10, 5], [$mystery, 3, null]]);
    rviIssue($cad, $mystery, 1);

    $result = rviBuild();
    $row = rviRow($result, $mystery, $cad);

    expect($row['rate'])->toBeNull()
        ->and($row['requested_value'])->toBeNull()
        ->and($row['status'])->toBe('partial')
        ->and($result['summary']['requested_value'])->toEqual(50.0)
        ->and($result['summary']['unrated'])->toBe(1);

    $this->actingAs(rviStoreHead())->get(route('store.stock.requisitions.requirement-vs-issue', ['from' => '2026-08', 'to' => '2026-08']))
        ->assertOk()->assertSee('Mystery Part')->assertSee('1 item(s) have no rate');
});

// --- Filters -----------------------------------------------------------------

it('filters by several departments, and lists only their sections', function () {
    $cad = rviSection('CAD & Sample', 'production');
    $mnt = rviSection('Mechanical', 'maintenance');
    $store = rviSection('Store Floor', null);
    $pencil = rviItem('Pencil');
    foreach ([$cad, $mnt, $store] as $s) { rviRequest($s, [[$pencil, 5, 5]]); }

    $service = app(RequirementVsIssueService::class);
    $options = $service->options();

    expect(rviBuild(['departments' => ['production', 'maintenance']])['rows']->pluck('section_id')->sort()->values()->all())
        ->toBe([$cad->id, $mnt->id])
        ->and(rviBuild(['departments' => ['none']])['rows']->pluck('section_id')->all())->toBe([$store->id])
        ->and($service->sectionsFor($options, ['maintenance'])->pluck('id')->all())->toBe([$mnt->id])
        ->and($service->sectionsFor($options, [])->count())->toBe($options['sections']->count());
});

it('filters by status, category, section, search and month range', function () {
    $cat = ItemCategory::create(['name' => 'Stationary', 'is_active' => true]);
    $cad = rviSection('CAD & Sample');
    $cutting = rviSection('Cutting');
    [$pencil, $paper] = [rviItem('Pencil HB', $cat), rviItem('Paper A4')];

    rviRequest($cad, [[$pencil, 10, 5], [$paper, 10, 5]]);
    rviRequest($cutting, [[$paper, 3, 5]], ['requisition_date' => '2026-09-05']);
    rviIssue($cad, $paper, 4);

    expect(rviBuild(['statuses' => ['not_issued']])['rows']->pluck('item_name')->all())->toBe(['Pencil HB'])
        ->and(rviBuild(['categories' => [(string) $cat->id]])['rows']->pluck('item_name')->all())->toBe(['Pencil HB'])
        ->and(rviBuild(['categories' => ['none']])['rows']->pluck('item_name')->all())->toBe(['Paper A4'])
        ->and(rviBuild(['sections' => [(string) $cad->id]])['rows'])->toHaveCount(2)
        ->and(rviBuild(['search' => 'a4'])['rows']->pluck('item_name')->all())->toBe(['Paper A4'])
        ->and(rviBuild(['from' => '2026-08', 'to' => '2026-09'])['summary']['requested_qty'])->toEqual(23.0)
        ->and(rviBuild(['from' => '2026-09', 'to' => '2026-09'])['summary']['requested_qty'])->toEqual(3.0);
});

it('ignores unknown ids and falls back to the current month for a bad month', function () {
    Carbon::setTestNow('2026-08-20');
    $service = app(RequirementVsIssueService::class);
    $options = $service->options();

    $filters = $service->filters(['from' => 'not-a-month', 'sections' => ['999999'], 'statuses' => ['bogus'], 'departments' => ['nope']], $options);

    expect($filters['from']->format('Y-m'))->toBe('2026-08')
        ->and($filters['sections'])->toBe([])
        ->and($filters['statuses'])->toBe([])
        ->and($filters['departments'])->toBe([]);
    Carbon::setTestNow();
});

it('opens February on the 31st, not March', function () {
    Carbon::setTestNow('2026-03-31 10:00:00');
    $cad = rviSection('CAD & Sample');
    $pencil = rviItem('Pencil');
    rviRequest($cad, [[$pencil, 7, 5]], ['requisition_date' => '2026-02-10']);

    $result = rviBuild(['from' => '2026-02', 'to' => '2026-02']);

    expect($result['filters']['from']->format('Y-m'))->toBe('2026-02')
        ->and($result['summary']['requested_qty'])->toEqual(7.0);
    Carbon::setTestNow();
});

it('gives the same result for the same filtered URL, and Reset clears every filter', function () {
    $head = rviStoreHead();
    $cad = rviSection('CAD & Sample');
    rviRequest($cad, [[rviItem('Pencil'), 10, 5]]);
    $query = ['from' => '2026-08', 'to' => '2026-08', 'sections' => [(string) $cad->id], 'statuses' => ['not_issued']];

    $first = $this->actingAs($head)->get(route('store.stock.requisitions.requirement-vs-issue', $query))->assertOk()->getContent();
    $second = $this->actingAs($head)->get(route('store.stock.requisitions.requirement-vs-issue', $query))->assertOk()->getContent();

    expect(preg_replace('/name="_token" value="[^"]*"/', '', $first))
        ->toBe(preg_replace('/name="_token" value="[^"]*"/', '', $second))
        ->and($first)->toContain('Section: CAD &amp; Sample')->toContain('Status: Not Issued');

    // Reset is the bare URL: no filter chips at all.
    $this->actingAs($head)->get(route('store.stock.requisitions.requirement-vs-issue'))
        ->assertOk()->assertDontSee('Remove filter');
});

// --- Totals and exports match the table ---------------------------------------

it('makes the cards, totals and Excel agree with the filtered table', function () {
    $cad = rviSection('CAD & Sample');
    $cutting = rviSection('Cutting');
    [$pencil, $paper] = [rviItem('Pencil'), rviItem('Paper')];
    rviRequest($cad, [[$pencil, 10, 5], [$paper, 20, 2]]);
    rviRequest($cutting, [[$paper, 50, 2]]);
    rviIssue($cad, $pencil, 4);
    rviIssue($cutting, $paper, 60);

    $service = app(RequirementVsIssueService::class);
    $options = $service->options();
    $filters = $service->filters(['from' => '2026-08', 'to' => '2026-08', 'sections' => [(string) $cad->id]], $options);
    $data = $service->build($filters, $options) + [
        'filters' => $filters, 'options' => $options,
        'title' => $service->title($filters, $options), 'period' => $service->periodLabel($filters),
    ];

    expect($data['summary']['requested_qty'])->toEqual((float) $data['rows']->sum('requested_qty'))
        ->and($data['summary']['requested_value'])->toEqual((float) $data['rows']->sum('requested_value'))
        ->and($data['title'])->toBe('Section: CAD & Sample · Month of August 2026');

    $path = tempnam(sys_get_temp_dir(), 'rvi').'.xlsx';
    file_put_contents($path, Excel::raw(new RequirementVsIssueExport($data), ExcelFormat::XLSX));
    $book = IOFactory::load($path);
    @unlink($path);

    expect($book->getSheetNames())->toBe(['All', 'CAD & Sample']);
    $all = $book->getSheetByName('All');

    expect($all->getCell('A3')->getValue())->toBe('Dept. of All')
        ->and($all->getCell('A2')->getValue())->toBe('Purchase Requisition vs Issue (Month of August 2026)')
        ->and($all->getCell('B5')->getValue())->toBe('Total')
        ->and((float) $all->getCell('G5')->getValue())->toEqual(30.0)
        ->and((float) $all->getCell('H5')->getValue())->toEqual(90.0)
        ->and((float) $all->getCell('J5')->getValue())->toEqual(20.0)
        ->and($all->getCell('A6')->getValue())->toBe('SL')
        ->and($all->getCell('N6')->getValue())->toBe('Status')
        ->and($book->getSheetByName('CAD & Sample')->getCell('A3')->getValue())->toBe('Dept. of CAD & Sample');
});

// --- The shared chip select --------------------------------------------------

it('keeps checkbox mode opt-in: no other screen uses data-checkboxes', function () {
    $users = collect(\Illuminate\Support\Facades\File::allFiles(resource_path('views')))
        ->filter(fn ($f) => str_contains($f->getContents(), 'data-checkboxes'))
        ->map(fn ($f) => str_replace('\\', '/', $f->getRelativePathname()))
        ->values()->all();

    expect($users)->toEqualCanonicalizing([
        'store/stock/_searchable.blade.php',
        'store/stock/requisitions/requirement-vs-issue.blade.php',
    ]);

    // The plugin is only switched on under the attribute.
    expect(file_get_contents(resource_path('views/store/stock/_searchable.blade.php')))
        ->toContain("if (el.hasAttribute('data-checkboxes'))");
});

// --- Store head change -------------------------------------------------------

it('gives the permission to each new Store head set on Admin > Departments, and moves access with it', function () {
    $admin = rviUser('admin');
    $a = rviUser('store');
    $b = rviUser('store');
    $store = Department::updateOrCreate(['key' => 'store'], ['label' => 'Store', 'head_user_id' => null, 'is_active' => true]);
    $put = fn (User $head) => $this->actingAs($admin)->put(route('admin.departments.update', $store), [
        'label' => 'Store', 'head_user_id' => $head->id, 'is_active' => 1, 'roles' => $store->roleNames(),
    ])->assertRedirect();

    $put($a);
    expect($a->fresh()->hasDirectPermission(RequirementVsIssueService::PERMISSION))->toBeTrue();
    $this->actingAs($a->fresh())->get(route('store.stock.requisitions.index'))->assertSee('Requirement vs Issue');
    $this->actingAs($a->fresh())->get(route('store.stock.requisitions.requirement-vs-issue'))->assertOk();

    $put($b);
    $this->actingAs($b->fresh())->get(route('store.stock.requisitions.index'))->assertSee('Requirement vs Issue');
    $this->actingAs($b->fresh())->get(route('store.stock.requisitions.requirement-vs-issue'))->assertOk();

    // A keeps the grant (nothing is revoked) but is no longer the head.
    expect($a->fresh()->hasDirectPermission(RequirementVsIssueService::PERMISSION))->toBeTrue();
    $this->actingAs($a->fresh())->get(route('store.stock.requisitions.requirement-vs-issue'))->assertForbidden();
    $this->actingAs($a->fresh())->get(route('store.stock.requisitions.requirement-vs-issue.excel'))->assertForbidden();
});

// --- The reference workbook, CAD & Sample, one month -------------------------

it('reproduces the reference workbook totals, value-weighted', function () {
    $rows = [
        // Item, UOM, Rate, Requested, Issued
        ['Virgin Paper-48"', 'Kg', 100, 1500, 726],
        ['Marker Paper (Intake)-64"', 'Kg', 68, 2000, 503],
        ['Ball Pen-Black', 'Pcs', 5, 25, 24],
        ['Ball Pen-Red', 'Pcs', 5, 10, 10],
        ['Gel Pen-Blue', 'Pcs', 16, 5, 3],
        ['Offset Paper-A4 Size', 'Ream', 420, 10, 7],
        ['Printer Cartridge-HP 45', 'Pcs', 2940, 24, 8],
        ['Pattern Cutting Knife', 'Pcs', 250, 2, 0],
        ['Stick Up Liquid Glue-50ml', 'Pcs', 60, 5, 5],
        ['Gum Tape 01" Transparent', 'Pcs', 12, 6, 12],
        ['Lead Pencil-0.5mm', 'Pcs', 130, 2, 1],
        ['Mouse Pad', 'Pcs', 80, 5, 0],
        ['Printer Cartridge-LBP 6030W', 'Pcs', 810, 2, 2],
        ['CD-DVD Marker Pen', 'Pcs', 17, 6, 2],
        ['Correction Pen', 'Pcs', 35, 2, 2],
        ['Measurement Tape-Lace', 'Pcs', 50, 3, 4],
        ['Thread Cutter', 'Pcs', 14, 12, 0],
        ['Poly-18" x 26"', 'Kg', 397, 10, 0],
        ['Poly-28" x 22"', 'Kg', 397, 10, 0],
        ['Steel Scale-12"', 'Pcs', 30, 3, 2],
        ['Savlon Liquid-5Ltr Jar', 'Liter', 1200, 1, 1],
        ['Tibbat Ball Soap', 'Pcs', 26, 10, 10],
        ['EPSON-003 Refil Ink', 'Pcs', 2600, 1, 1],
    ];

    $cad = rviSection('CAD & Sample', 'production');
    $lines = [];
    foreach ($rows as [$name, $uom, $rate, $requested, $issued]) {
        $item = StockItem::create(['name' => $name, 'uom' => $uom, 'is_active' => true]);
        $lines[] = [$item, $requested, $rate];
        if ($issued > 0) {
            rviIssue($cad, $item, $issued);
        }
    }
    rviRequest($cad, $lines);

    $result = rviBuild(['departments' => ['production'], 'sections' => [(string) $cad->id]]);
    $summary = $result['summary'];

    expect($result['rows'])->toHaveCount(23)
        ->and($summary['requested_qty'])->toEqual(3654.0)
        ->and($summary['issued_qty'])->toEqual(1323.0)
        // Net: the two over-issued rows' negative balances are included,
        // exactly as the workbook's 2,331 includes them.
        ->and($summary['balance_qty'])->toEqual(2331.0)
        ->and($summary['requested_value'])->toEqual(376747.0)
        ->and($summary['issued_value'])->toEqual(140100.0)
        ->and($summary['balance_value'])->toEqual(236647.0)
        ->and(round($summary['pct'], 1))->toEqual(37.2)
        ->and($summary['counts']['over_issued'])->toBe(2)
        ->and($summary['counts']['not_issued'])->toBe(5)
        ->and($result['rows']->where('status', 'over_issued')->pluck('item_name')->sort()->values()->all())
            ->toBe(['Gum Tape 01" Transparent', 'Measurement Tape-Lace']);

    // The workbook's 60.1% was the plain average of the row percentages.
    expect(round($result['rows']->avg('pct'), 1))->toEqual(60.1)
        ->and(round($summary['pct'], 1))->not->toEqual(60.1);

    // Screen and Excel print the same totals.
    $head = rviStoreHead();
    $this->actingAs($head)->get(route('store.stock.requisitions.requirement-vs-issue', [
        'from' => '2026-08', 'to' => '2026-08', 'sections' => [(string) $cad->id],
    ]))->assertOk()->assertSee('376,747.00')->assertSee('140,100.00')->assertSee('236,647.00')->assertSee('37.2%');

    $service = app(RequirementVsIssueService::class);
    $options = $service->options();
    $filters = $service->filters(['from' => '2026-08', 'to' => '2026-08', 'sections' => [(string) $cad->id]], $options);
    $data = $service->build($filters, $options) + ['filters' => $filters, 'options' => $options,
        'title' => $service->title($filters, $options), 'period' => $service->periodLabel($filters)];
    $path = tempnam(sys_get_temp_dir(), 'rvi').'.xlsx';
    file_put_contents($path, Excel::raw(new RequirementVsIssueExport($data), ExcelFormat::XLSX));
    $sheet = IOFactory::load($path)->getSheetByName('CAD & Sample');
    @unlink($path);

    // Totals above the header (row 5) and below the last row (row 30).
    expect((float) $sheet->getCell('G5')->getValue())->toEqual(3654.0)
        ->and((float) $sheet->getCell('I5')->getValue())->toEqual(1323.0)
        ->and((float) $sheet->getCell('K5')->getValue())->toEqual(2331.0)
        ->and((float) $sheet->getCell('L5')->getValue())->toEqual(236647.0)
        ->and(round((float) $sheet->getCell('M5')->getValue(), 3))->toEqual(0.372)
        ->and((float) $sheet->getCell('K30')->getValue())->toEqual(2331.0);
});

// --- Rate source ---------------------------------------------------------------

it('rates an item from the latest Receiving line, else the Item Master, else a dash', function () {
    $cad = rviSection('CAD & Sample');
    $received = rviItem('Received Item');
    $mastered = StockItem::create(['name' => 'Master Rate Item', 'uom' => 'Pcs', 'is_active' => true, 'unit_price' => 42]);
    $bare = rviItem('No Rate Item');
    foreach ([['2026-06-01', 10], ['2026-08-05', 12], ['2026-09-10', 99]] as [$date, $price]) {
        DB::table('stock_purchases')->insert(['stock_item_id' => $received->id, 'purchase_date' => $date, 'qty' => 1, 'unit_price' => $price, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach ([$received, $mastered, $bare] as $item) {
        rviIssue($cad, $item, 1);
    }

    $result = rviBuild();

    // Latest receipt up to the end of August — not September's 99.
    expect(rviRow($result, $received, $cad)['rate'])->toEqual(12.0)
        ->and(rviRow($result, $mastered, $cad)['rate'])->toEqual(42.0)
        ->and(rviRow($result, $bare, $cad)['rate'])->toBeNull();
});
