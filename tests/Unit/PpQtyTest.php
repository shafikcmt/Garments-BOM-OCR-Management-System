<?php

use App\Support\PpQty;

it('reads the leading number of a PP Qty cell', function () {
    expect(PpQty::number('25 yds'))->toBe(25.0)
        ->and(PpQty::number('1,250.5'))->toBe(1250.5)
        ->and(PpQty::number(''))->toBe(0.0)
        ->and(PpQty::number(null))->toBe(0.0)
        ->and(PpQty::number('n/a'))->toBe(0.0);
});

it('does not bill PP Qty unless the column says it is billed', function () {
    expect(PpQty::chargeable('25 yds', null))->toBe(0.0)
        ->and(PpQty::chargeable('25 yds', ''))->toBe(0.0)
        ->and(PpQty::chargeable('25 yds', 'Yes'))->toBe(0.0)
        ->and(PpQty::chargeable('25', ' FOC '))->toBe(0.0)
        ->and(PpQty::chargeable('25 yds', 'No'))->toBe(25.0)
        ->and(PpQty::chargeable('25', ' Billed '))->toBe(25.0);
});

it('re-prices a PRA line for the PP Qty choice', function () {
    $service = (new ReflectionClass(\App\Services\BookingPoSourceService::class))->newInstanceWithoutConstructor();
    $line = ['qty' => 4061.0, 'pp_qty_number' => 25.0, 'pi_rate' => 6.18, 'pi_amount' => 25096.98, 'pp_free' => false];

    expect(round($service->applyPpChoice($line, false)['pi_amount'], 2))->toBe(25251.48)
        ->and(round($service->applyPpChoice($line, true)['pi_amount'], 2))->toBe(25096.98)
        ->and($service->applyPpChoice($line, true)['pp_free'])->toBeTrue();

    $noPp = ['qty' => 100.0, 'pp_qty_number' => 0.0, 'pi_rate' => 2.0, 'pi_amount' => 200.0];
    expect($service->applyPpChoice($noPp, false))->toBe($noPp);
});
