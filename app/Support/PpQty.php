<?php

namespace App\Support;

/**
 * PP (pre-production / sample) quantity rules for PI amount.
 *
 * BY DEFAULT PP QTY IS NOT BILLED: PI Amount = PI Rate x Materials Ordered, the
 * same as the PP Qty Free column set to "Yes". A line is billed only when its
 * PP Qty Free column says No (or Billed), and then PI Amount =
 * PI Rate x (Materials Ordered + PP Qty). The PP Qty cell is free text on the
 * booking format ("25 yds"), so only its leading number is used.
 *
 * The Workspace screen, the saved Workspace cell and the PRA all use this one
 * rule (the screen's copy is in the Workspace view's script - keep them in step).
 */
final class PpQty
{
    /** Values of the "PP Qty Free" column that BILL the PP quantity. Anything else, blank included, is free. */
    private const BILLED_VALUES = ['no', 'n', 'billed', 'bill', 'charged', 'paid', 'false', '0'];

    public static function number(mixed $value): float
    {
        $value = trim((string) $value);

        if ($value === '' || ! preg_match('/-?\d[\d,]*(?:\.\d+)?|-?\.\d+/', $value, $match)) {
            return 0.0;
        }

        return max(0.0, (float) str_replace(',', '', $match[0]));
    }

    public static function isFree(mixed $flag): bool
    {
        return ! in_array(strtolower(trim((string) $flag)), self::BILLED_VALUES, true);
    }

    /** The PP quantity that is billed: zero when it is free. */
    public static function chargeable(mixed $ppQty, mixed $freeFlag): float
    {
        return self::isFree($freeFlag) ? 0.0 : self::number($ppQty);
    }
}
