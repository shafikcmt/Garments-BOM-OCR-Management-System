<?php

use App\Models\MaterialBulkIssue;
use App\Models\MaterialReceiving;
use App\Models\MaterialStockLedger;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Locks the store report access matrix and the separation between the
 * date-filtered Period Movement and the lifetime ledger balance.
 */
function reportUser(string $role): User
{
    Role::findOrCreate($role, 'web');

    return User::factory()->create()->assignRole($role);
}

beforeEach(function () {
    MaterialReceiving::create([
        'buyer_name' => 'Buyer A',
        'style_name' => 'Style A',
        'material_description' => 'Main Fabric',
        'receive_date' => '2026-03-10',
        'qty' => 100,
        'unit_price' => 2,
    ]);

    MaterialBulkIssue::create([
        'buyer_name' => 'Buyer A',
        'style_name' => 'Style A',
        'material_description' => 'Main Fabric',
        'issue_date' => '2026-03-12',
        'bulk_qty' => 40,
    ]);

    MaterialStockLedger::create([
        'buyer_name' => 'Buyer A',
        'style_name' => 'Style A',
        'material_description' => 'Main Fabric',
        'total_closing_qty' => 60,
    ]);
});

test('store, admin and management get preview plus both exports', function (string $role) {
    $user = reportUser($role);

    $this->actingAs($user)->get('/store/reports?type=style')->assertOk();
    $this->actingAs($user)->get('/store/reports/pdf?type=style')->assertOk();
    $this->actingAs($user)->get('/store/reports/excel?type=style')->assertOk();
})->with(['store', 'admin', 'management']);

test('merchant can preview but cannot download', function () {
    $user = reportUser('merchant');

    $this->actingAs($user)->get('/store/reports?type=buyer')->assertOk();
    $this->actingAs($user)->get('/store/reports/pdf?type=buyer')->assertForbidden();
    $this->actingAs($user)->get('/store/reports/excel?type=buyer')->assertForbidden();
});

test('other roles cannot reach the reports at all', function () {
    $user = reportUser('commercial');

    $this->actingAs($user)->get('/store/reports')->assertForbidden();
    $this->actingAs($user)->get('/store/reports/pdf')->assertForbidden();
    $this->actingAs($user)->get('/store/reports/excel')->assertForbidden();
});

test('guests are redirected to login', function () {
    $this->get('/store/reports')->assertRedirect('/login');
});

test('date filter narrows period movement but never the ledger balance', function () {
    $service = app(App\Services\StoreReportService::class);

    $all = $service->rows('style', [])->firstWhere('label', 'Style A');
    expect($all['receive_qty'])->toBe(100.0)
        ->and($all['total_issue'])->toBe(40.0)
        ->and($all['period_movement'])->toBe(60.0)
        ->and($all['ledger_balance'])->toBe(60.0)
        // Value comes from the receiving row's existing unit_price.
        ->and($all['receive_value'])->toBe(200.0);

    // A window that excludes the issue must drop it from Period Movement while
    // the ledger balance stays at its lifetime value.
    $windowed = $service->rows('style', ['date_from' => '2026-03-01', 'date_to' => '2026-03-11'])
        ->firstWhere('label', 'Style A');

    expect($windowed['total_issue'])->toBe(0.0)
        ->and($windowed['period_movement'])->toBe(100.0)
        ->and($windowed['ledger_balance'])->toBe(60.0);
});
