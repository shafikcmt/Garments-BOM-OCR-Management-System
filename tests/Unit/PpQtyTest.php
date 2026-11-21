<?php

use App\Support\PpQty;

it('reads the leading number of a PP Qty cell', function () {
    expect(PpQty::number('25 yds'))->toBe(25.0)
        ->and(PpQty::number('1,250.5'))->toBe(1250.5)
        ->and(PpQty::number(''))->toBe(0.0)
        ->and(PpQty::number(null))->toBe(0.0)
        ->and(PpQty::number('n/a'))->toBe(0.0);
});

it('bills PP Qty unless the free flag is set', function () {
    expect(PpQty::chargeable('25 yds', null))->toBe(25.0)
        ->and(PpQty::chargeable('25 yds', 'No'))->toBe(25.0)
        ->and(PpQty::chargeable('25 yds', 'Yes'))->toBe(0.0)
        ->and(PpQty::chargeable('25', ' FOC '))->toBe(0.0);
});
