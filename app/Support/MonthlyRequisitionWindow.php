<?php

namespace App\Support;

use App\Models\AppSetting;
use Carbon\CarbonInterface;

/**
 * When a MONTHLY purchase requisition may be submitted.
 *
 * A department raises one monthly sheet for the whole month's need, which the
 * Store merges with the others. To keep every department's sheet in the same
 * period, Monthly is accepted only inside a window of each month — the 20th to
 * the end of the month by default. An Individual requisition has no window.
 *
 * Admin and the Store Head change the window on a screen (Monthly Requisition
 * Window); the values live in app_settings, so no deploy is needed. The check
 * is made on the day the sheet is SUBMITTED (Dhaka date), never on the date
 * typed on the sheet, and a draft can be saved on any day.
 */
class MonthlyRequisitionWindow
{
    public const KEY_ENABLED = 'requisition_monthly_enabled';
    public const KEY_START_DAY = 'requisition_monthly_start_day';
    public const KEY_END_DAY = 'requisition_monthly_end_day';
    public const KEY_MESSAGE = 'requisition_monthly_message';

    public const DEFAULT_START_DAY = 20;

    /** Monthly requisitions can be switched off altogether. */
    public static function enabled(): bool
    {
        return (string) AppSetting::get(self::KEY_ENABLED, '1') !== '0';
    }

    /** First day of the month the window is open (1–31). */
    public static function startDay(): int
    {
        return min(31, max(1, (int) AppSetting::get(self::KEY_START_DAY, self::DEFAULT_START_DAY)));
    }

    /** Last day the window is open; 0 means "the last day of the month". */
    public static function endDay(): int
    {
        return min(31, max(0, (int) AppSetting::get(self::KEY_END_DAY, 0)));
    }

    /** The message Admin typed to replace the default, if any. */
    public static function customMessage(): ?string
    {
        $text = trim((string) AppSetting::get(self::KEY_MESSAGE, ''));

        return $text === '' ? null : $text;
    }

    /** Is a Monthly requisition accepted on this day? */
    public static function isOpen(?CarbonInterface $on = null): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $on ??= LocalTime::today();

        $last = self::endDay() === 0 ? $on->daysInMonth : min(self::endDay(), $on->daysInMonth);
        $first = min(self::startDay(), $last);

        return $on->day >= $first && $on->day <= $last;
    }

    /** "20th to the end of the month" / "20th to 28th" — for messages. */
    public static function rangeText(): string
    {
        $end = self::endDay() === 0 ? 'the end of the month' : self::ordinal(self::endDay());

        return self::ordinal(self::startDay()).' to '.$end;
    }

    /** What the user is told when a Monthly submit is refused. */
    public static function message(): string
    {
        if (! self::enabled()) {
            return 'Monthly requisition is currently closed. If you need something now, please raise an Individual requisition.';
        }

        return self::customMessage()
            ?? 'Monthly requisition is accepted from the '.self::rangeText().'. If you need it earlier, please raise an Individual requisition.';
    }

    public static function ordinal(int $n): string
    {
        $suffix = ($n % 100 >= 11 && $n % 100 <= 13) ? 'th' : ([1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th');

        return $n.$suffix;
    }
}
