<?php

use App\Models\PurchaseRequisitionAction;
use App\Support\LocalTime;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Storage is UTC; people see Asia/Dhaka. Nothing is stored in Dhaka time.
 */
uses(TestCase::class);

afterEach(fn () => Carbon::setTestNow());

it('keeps the application timezone on UTC and displays in Dhaka', function () {
    expect(config('app.timezone'))->toBe('UTC')
        ->and(LocalTime::timezone())->toBe('Asia/Dhaka');
});

it('shows a stored UTC timestamp as the Dhaka wall clock, across midnight', function () {
    // 30 Sep 18:30 UTC is 1 Oct 00:30 in Dhaka.
    $stored = Carbon::parse('2026-09-30 18:30:00', 'UTC');

    expect($stored->local()->format('d M Y, h:i A'))->toBe('01 Oct 2026, 12:30 AM')
        ->and(LocalTime::show($stored))->toBe('01 Oct 2026, 12:30 AM')
        ->and(LocalTime::show($stored, 'd-M-Y'))->toBe('01-Oct-2026')
        ->and($stored->format('d M Y, h:i A'))->toBe('30 Sep 2026, 06:30 PM');
});

it('reads sheet 17 the way it happened: 03:54 UTC is 09:54 in Dhaka', function () {
    expect(LocalTime::show(Carbon::parse('2026-09-30 03:54:43', 'UTC')))->toBe('30 Sep 2026, 09:54 AM');
});

it('does not touch the stored instant, only how it is shown', function () {
    $stored = Carbon::parse('2026-09-30 18:30:00', 'UTC');
    $local = $stored->local();

    expect($local->getTimestamp())->toBe($stored->getTimestamp())
        ->and($stored->timezoneName)->toBe('UTC');
});

it('says what to show when there is no time', function () {
    expect(LocalTime::show(null))->toBe('—')
        ->and(LocalTime::show('', 'd M Y', 'n/a'))->toBe('n/a')
        ->and(LocalTime::of(null))->toBeNull();
});

it('takes today and now from the Dhaka clock, so 00:30 Dhaka is already tomorrow', function () {
    // 18:30 UTC on 30 Sep is 00:30 on 1 Oct in Dhaka.
    Carbon::setTestNow(Carbon::parse('2026-09-30 18:30:00', 'UTC'));

    expect(now()->toDateString())->toBe('2026-09-30')
        ->and(LocalTime::today()->toDateString())->toBe('2026-10-01')
        ->and(LocalTime::now()->format('Y-m'))->toBe('2026-10')
        ->and(LocalTime::now()->startOfMonth()->toDateString())->toBe('2026-10-01');
});

it('works on a model timestamp through the macro', function () {
    $action = new PurchaseRequisitionAction(['created_at' => '2026-09-30 18:30:00']);

    expect($action->created_at->local()->format('d M Y'))->toBe('01 Oct 2026');
});
