<?php

use App\Http\Controllers\SupplyChain\BookingController;
use App\Models\BookingPo;
use Illuminate\Support\Carbon;

/**
 * The default Required Ex-Mill date is the booking's generated date plus one
 * calendar month, clamped to the end of the target month.
 *
 * A plain addMonth() rolls 31 January into 3 March, while the form's own
 * autofill (_ex-mill-autofill) clamps to 28 February — so the saved default
 * and the date on screen disagreed at month end. This holds them together.
 */
function exMillDefaultFor(string $generatedAt): string
{
    $bookingPo = new BookingPo(['po_no' => 'HB26FA0001']);
    $bookingPo->generated_at = Carbon::parse($generatedAt);

    $method = new ReflectionMethod(BookingController::class, 'defaultBookingData');
    $method->setAccessible(true);

    return $method->invoke(app(BookingController::class), $bookingPo)['required_ex_mill'];
}

it('clamps the ex-mill default to the end of a shorter month', function (string $generated, string $expected) {
    expect(exMillDefaultFor($generated))->toBe($expected);
})->with([
    '31 Jan -> 28 Feb' => ['2026-01-31', '28/02/2026'],
    '31 Jan leap year -> 29 Feb' => ['2028-01-31', '29/02/2028'],
    '31 Oct -> 30 Nov' => ['2026-10-31', '30/11/2026'],
]);

it('keeps the same day when the next month has it', function () {
    expect(exMillDefaultFor('2026-03-15'))->toBe('15/04/2026');
});
