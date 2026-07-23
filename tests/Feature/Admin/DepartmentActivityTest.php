<?php

use App\Models\ActivityLog;
use App\Models\ExcelCell;
use App\Models\ExcelFile;
use App\Models\ExcelHeader;
use App\Models\ExcelRow;
use App\Models\User;
use App\Services\DepartmentActivityService;
use Spatie\Permission\Models\Role;

/**
 * Department progress on the required columns each one owns.
 *
 * Read-only reporting over existing data, so the guarantees worth locking are
 * arithmetic: only REQUIRED and ACTIVE columns count, blanks are not progress,
 * and scoping to one workspace does not leak another workspace's cells.
 */
function deptFile(string $name = 'bom.xlsx'): ExcelFile
{
    return ExcelFile::create([
        'file_name' => $name,
        'original_file_name' => $name,
        'file_path' => $name,
        'status' => 'completed',
        'uploaded_by' => User::factory()->create()->id,
    ]);
}

function deptHeader(string $key, string $roleName, bool $required = true, bool $active = true): ExcelHeader
{
    return ExcelHeader::create([
        'header_key' => $key,
        'header_name' => ucfirst($key),
        'position' => 1,
        'owner_role_id' => Role::findOrCreate($roleName, 'web')->id,
        'is_required' => $required,
        'is_active' => $active,
    ]);
}

function deptRow(ExcelFile $file, int $number): ExcelRow
{
    return ExcelRow::create(['excel_file_id' => $file->id, 'row_number' => $number]);
}

it('reports a department with nothing entered as Not Started', function () {
    $file = deptFile();
    deptHeader('store_a', 'store');
    deptRow($file, 1);

    $summary = collect(app(DepartmentActivityService::class)->summary($file));
    $store = $summary->firstWhere('role', 'store');

    expect($store['status'])->toBe(DepartmentActivityService::NOT_STARTED)
        ->and($store['percent'])->toBe(0.0)
        ->and($store['columns_started'])->toBe(0)
        ->and($store['required_columns'])->toBe(1)
        ->and($store['last_activity'])->toBeNull();
});

it('reports a fully filled department as Completed', function () {
    $file = deptFile();
    $header = deptHeader('store_a', 'store');
    $rowA = deptRow($file, 1);
    $rowB = deptRow($file, 2);

    ExcelCell::create(['row_id' => $rowA->id, 'header_id' => $header->id, 'value' => 'x']);
    ExcelCell::create(['row_id' => $rowB->id, 'header_id' => $header->id, 'value' => 'y']);

    $store = collect(app(DepartmentActivityService::class)->summary($file))->firstWhere('role', 'store');

    expect($store['status'])->toBe(DepartmentActivityService::COMPLETED)
        ->and($store['percent'])->toBe(100.0)
        ->and($store['cells_filled'])->toBe(2)
        ->and($store['cells_expected'])->toBe(2);
});

it('reports a partly filled department as In Progress and counts columns started', function () {
    $file = deptFile();
    $filled = deptHeader('store_a', 'store');
    deptHeader('store_b', 'store');   // never touched
    $row = deptRow($file, 1);

    ExcelCell::create(['row_id' => $row->id, 'header_id' => $filled->id, 'value' => 'x']);

    $store = collect(app(DepartmentActivityService::class)->summary($file))->firstWhere('role', 'store');

    expect($store['status'])->toBe(DepartmentActivityService::IN_PROGRESS)
        ->and($store['columns_started'])->toBe(1)
        ->and($store['required_columns'])->toBe(2)
        ->and($store['percent'])->toBe(50.0);
});

it('does not treat a blank cell as progress', function () {
    $file = deptFile();
    $header = deptHeader('store_a', 'store');
    $row = deptRow($file, 1);

    ExcelCell::create(['row_id' => $row->id, 'header_id' => $header->id, 'value' => '']);

    $store = collect(app(DepartmentActivityService::class)->summary($file))->firstWhere('role', 'store');

    expect($store['status'])->toBe(DepartmentActivityService::NOT_STARTED)
        ->and($store['cells_filled'])->toBe(0);
});

it('ignores columns that are optional or inactive', function () {
    $file = deptFile();
    deptHeader('store_required', 'store');
    deptHeader('store_optional', 'store', required: false);
    deptHeader('store_retired', 'store', required: true, active: false);
    deptRow($file, 1);

    $store = collect(app(DepartmentActivityService::class)->summary($file))->firstWhere('role', 'store');

    expect($store['required_columns'])->toBe(1);
});

it('keeps one workspace out of another workspace figures', function () {
    $fileA = deptFile('a.xlsx');
    $fileB = deptFile('b.xlsx');
    $header = deptHeader('store_a', 'store');

    $rowA = deptRow($fileA, 1);
    deptRow($fileB, 1);

    // Only workspace A has the value.
    ExcelCell::create(['row_id' => $rowA->id, 'header_id' => $header->id, 'value' => 'x']);

    $service = app(DepartmentActivityService::class);

    expect(collect($service->summary($fileA))->firstWhere('role', 'store')['status'])
        ->toBe(DepartmentActivityService::COMPLETED);

    expect(collect($service->summary($fileB))->firstWhere('role', 'store')['status'])
        ->toBe(DepartmentActivityService::NOT_STARTED);

    // Unscoped covers both rows, so it sits between the two.
    expect(collect($service->summary())->firstWhere('role', 'store')['percent'])->toBe(50.0);
});

it('separates departments from one another', function () {
    $file = deptFile();
    $storeHeader = deptHeader('store_a', 'store');
    deptHeader('commercial_a', 'commercial');
    $row = deptRow($file, 1);

    ExcelCell::create(['row_id' => $row->id, 'header_id' => $storeHeader->id, 'value' => 'x']);

    $summary = collect(app(DepartmentActivityService::class)->summary($file));

    expect($summary->firstWhere('role', 'store')['status'])->toBe(DepartmentActivityService::COMPLETED)
        ->and($summary->firstWhere('role', 'commercial')['status'])->toBe(DepartmentActivityService::NOT_STARTED);
});

it('reports when a department last worked on its own columns', function () {
    $file = deptFile();
    $header = deptHeader('store_a', 'store');
    $row = deptRow($file, 1);
    $user = User::factory()->create();

    ActivityLog::create([
        'excel_file_id' => $file->id,
        'row_id' => $row->id,
        'header_id' => $header->id,
        'action' => 'updated',
        'user_id' => $user->id,
    ]);

    $store = collect(app(DepartmentActivityService::class)->summary($file))->firstWhere('role', 'store');

    expect($store['last_activity'])->not->toBeNull();
});

// --- Single-department scoping ---------------------------------------------
it('scopes forRole to one department and never returns another', function () {
    $file = deptFile();
    $storeHeader = deptHeader('store_a', 'store');
    deptHeader('commercial_a', 'commercial');
    $row = deptRow($file, 1);

    ExcelCell::create(['row_id' => $row->id, 'header_id' => $storeHeader->id, 'value' => 'x']);

    $service = app(DepartmentActivityService::class);

    $store = $service->forRole('store', $file);
    expect($store['role'])->toBe('store')
        ->and($store['status'])->toBe(DepartmentActivityService::COMPLETED)
        ->and($store['required_columns'])->toBe(1);

    $commercial = $service->forRole('commercial', $file);
    expect($commercial['role'])->toBe('commercial')
        ->and($commercial['status'])->toBe(DepartmentActivityService::NOT_STARTED);
});

it('gives forRole and the admin summary the same numbers for a department', function () {
    $file = deptFile();
    $a = deptHeader('store_a', 'store');
    deptHeader('store_b', 'store');
    $row = deptRow($file, 1);
    ExcelCell::create(['row_id' => $row->id, 'header_id' => $a->id, 'value' => 'x']);

    $service = app(DepartmentActivityService::class);

    $fromAdmin = collect($service->summary($file))->firstWhere('role', 'store');
    $fromUser = $service->forRole('store', $file);

    expect($fromUser['percent'])->toBe($fromAdmin['percent'])
        ->and($fromUser['status'])->toBe($fromAdmin['status'])
        ->and($fromUser['columns_started'])->toBe($fromAdmin['columns_started'])
        ->and($fromUser['cells_filled'])->toBe($fromAdmin['cells_filled']);
});

it('returns null for a role that owns no required columns', function () {
    deptFile();
    deptHeader('store_a', 'store');

    expect(app(DepartmentActivityService::class)->forRole('commercial'))->toBeNull();
});

it('exposes the aliases the workspace-progress card renders from', function () {
    $file = deptFile();
    deptHeader('store_a', 'store');
    deptRow($file, 1);

    expect(app(DepartmentActivityService::class)->forRole('store', $file))
        ->toHaveKeys(['fields', 'rows', 'expected', 'filled', 'pending', 'percent', 'status']);
});

it('resolves a user department, and none for admin or management', function () {
    deptHeader('store_a', 'store');

    Role::findOrCreate('admin', 'web');
    Role::findOrCreate('management', 'web');

    $service = app(DepartmentActivityService::class);

    $storeUser = User::factory()->create()->assignRole('store');
    expect($service->departmentRoleFor($storeUser))->toBe('store');

    // Admin and management own no required columns, so they get the
    // all-department view rather than a personal one.
    expect($service->departmentRoleFor(User::factory()->create()->assignRole('admin')))->toBeNull();
    expect($service->departmentRoleFor(User::factory()->create()->assignRole('management')))->toBeNull();
    expect($service->departmentRoleFor(null))->toBeNull();
});

it('shows the department table on the admin dashboard', function () {
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create()->assignRole('admin');

    deptHeader('store_a', 'store');
    deptRow(deptFile(), 1);

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Department progress on required columns')
        // The existing ownership chart must survive alongside it.
        ->assertSee('Workspace columns by owner role');
});
