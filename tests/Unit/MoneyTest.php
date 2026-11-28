<?php

use App\Support\Money;

it('cuts to whole cents without rounding up, like the supplier invoice', function () {
    // The five lines of proforma PI-110647/26 and their printed amounts.
    $lines = [
        [479, 0.0789, 37.79],
        [830, 0.0789, 65.48],
        [964, 0.0789, 76.05],
        [538, 0.0819, 44.06],
        [882, 0.0819, 72.23],
    ];

    foreach ($lines as [$qty, $rate, $printed]) {
        expect(Money::floorCents($qty * $rate))->toBe($printed);
    }

    expect(round(array_sum(array_map(fn ($l) => Money::floorCents($l[0] * $l[1]), $lines)), 2))->toBe(295.61);
});

it('does not lose a cent to float error', function () {
    expect(Money::floorCents(4.35))->toBe(4.35)
        ->and(Money::floorCents(0.07))->toBe(0.07)
        ->and(Money::floorCents(0.0))->toBe(0.0)
        ->and(Money::floorCents(-65.487))->toBe(-65.48);
});

it('cuts to the requested number of decimals', function () {
    expect(Money::floorTo(6.35999, 4))->toBe(6.3599);
    expect(Money::floorTo(65.487, 2))->toBe(65.48);
    expect(Money::floorTo(0.0127 * 500, 2))->toBe(6.35);
});
