<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

/**
 * The missing-PI alert runs at 09:00 Dhaka (03:00 UTC), not 09:00 UTC.
 */
afterEach(fn () => Carbon::setTestNow());

it('runs the missing-PI alert at 09:00 Asia/Dhaka', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command, 'po:check-missing-pi-alerts'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 9 * * *')
        ->and((string) $event->timezone)->toBe('Asia/Dhaka');

    Carbon::setTestNow(Carbon::parse('2026-10-11 03:00:00', 'UTC')); // 09:00 Dhaka
    expect($event->isDue(app()))->toBeTrue();

    Carbon::setTestNow(Carbon::parse('2026-10-11 09:00:00', 'UTC')); // 15:00 Dhaka
    expect($event->isDue(app()))->toBeFalse();
});
