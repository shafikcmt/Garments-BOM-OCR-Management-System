<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * The factory's wall clock.
 *
 * Storage stays UTC: config('app.timezone') is UTC, so every timestamp column
 * holds a UTC literal and is never rewritten. What people SEE, and what counts
 * as "today" or "this month" for a business date, is Asia/Dhaka
 * (config('app.display_timezone')).
 *
 *   $row->created_at->local()->format('d M Y, h:i A')   a stored timestamp, shown in Dhaka
 *   LocalTime::show($row->created_at)                    the same, '—' when empty
 *   LocalTime::today()->toDateString()                   today's DATE for a form default
 *   LocalTime::now()                                      the Dhaka "now" for month defaults
 *
 * DATE columns (requisition_date, issue_date, ...) are calendar dates typed by
 * a person, with no time of day: they are never converted.
 */
class LocalTime
{
    public static function timezone(): string
    {
        return (string) config('app.display_timezone', 'Asia/Dhaka');
    }

    /** The current moment, expressed in Dhaka. */
    public static function now(): Carbon
    {
        return Date::now(self::timezone());
    }

    /** Today in Dhaka, at 00:00 Dhaka. */
    public static function today(): Carbon
    {
        return self::now()->startOfDay();
    }

    /** A stored (UTC) timestamp as the same instant on the Dhaka clock; null stays null. */
    public static function of(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Date::parse($value)->setTimezone(self::timezone());
    }

    public static function show(mixed $value, string $format = 'd M Y, h:i A', string $empty = '—'): string
    {
        return self::of($value)?->format($format) ?? $empty;
    }
}
