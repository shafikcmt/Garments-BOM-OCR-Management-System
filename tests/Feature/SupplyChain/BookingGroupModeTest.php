<?php

use App\Http\Controllers\SupplyChain\BookingController;
use Illuminate\Http\Request;

function groupKey(array $row, ?string $mode): string
{
    app()->instance('request', Request::create('/x', 'POST', $mode ? ['group_mode' => $mode] : []));
    $controller = app(BookingController::class);
    $method = new ReflectionMethod($controller, 'bookingGroupKey');

    return $method->invoke($controller, $row);
}

function baseRow(): array
{
    return ['buyer_name' => 'Ann Taylor', 'season_name' => 'SU27', 'ihod' => '2027-01-10', 'vendor_name' => 'ACME', 'sap_code' => '1001'];
}

it('item-wise (default) joins the same vendor and SAP code across buyer, season and IHOD', function () {
    $other = ['buyer_name' => 'Hugo Boss', 'season_name' => 'AW27', 'ihod' => '2027-03-01'] + baseRow();

    expect(groupKey(baseRow(), null))->toBe(groupKey($other, null))
        ->and(groupKey(baseRow(), 'item'))->toBe(groupKey($other, 'item'));
});

it('item-wise still separates different vendors or SAP codes', function () {
    expect(groupKey(baseRow(), 'item'))->not->toBe(groupKey(['vendor_name' => 'OTHER'] + baseRow(), 'item'))
        ->and(groupKey(baseRow(), 'item'))->not->toBe(groupKey(['sap_code' => '2002'] + baseRow(), 'item'));
});

it('strict match needs buyer, season, IHOD, vendor and SAP code all equal', function () {
    expect(groupKey(baseRow(), 'strict'))->toBe(groupKey(baseRow(), 'strict'));

    foreach ([['buyer_name' => 'Hugo Boss'], ['season_name' => 'AW27'], ['ihod' => '2027-03-01'], ['vendor_name' => 'OTHER'], ['sap_code' => '2002']] as $change) {
        expect(groupKey($change + baseRow(), 'strict'))->not->toBe(groupKey(baseRow(), 'strict'));
    }
});

it('falls back to item-wise for an unknown mode', function () {
    expect(groupKey(baseRow(), 'nonsense'))->toBe(groupKey(['buyer_name' => 'Hugo Boss'] + baseRow(), 'nonsense'));
});

it('item-wise groups the same item together when the vendor is not known', function () {
    $a = ['vendor_name' => '', 'sap_code' => '', 'item_name' => 'POCKETING', 'description' => 'TC POCKETING 110X76'] + baseRow();
    $b = ['buyer_name' => 'Hugo Boss', 'season_name' => 'AW27', 'ihod' => '2027-03-01'] + $a;
    $c = ['description' => 'TC POCKETING 96X72'] + $a;

    expect(groupKey($a, 'item'))->toBe(groupKey($b, 'item'))
        ->and(groupKey($a, 'item'))->not->toBe(groupKey($c, 'item'))
        ->and(groupKey($a, 'strict'))->not->toBe(groupKey($b, 'strict'));
});

it('item-wise with a vendor but no SAP code keeps the old buyer, season, IHOD and vendor key', function () {
    $noSap = ['sap_code' => ''] + baseRow();

    expect(groupKey($noSap, 'item'))->not->toBe(groupKey(['buyer_name' => 'Hugo Boss'] + $noSap, 'item'));
});
