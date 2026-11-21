<?php

namespace App\Support;

/**
 * PP (pre-production / sample) quantity rules for PI amount.
 *
 * PP Qty is normally charged by the supplier, so PI Amount is
 * PI Rate x (Booking Qty + PP Qty). Some suppliers or buyers provide the PP
 * quantity free of charge; the PP Qty Free column (Yes) switches the charge off
 * for that line. The PP Qty cell is free text on the booking format ("25 yds"),
 * so only its leading number is used.
 */
final class PpQty
{
    /** Values of the "PP Qty Free" column that mean the PP quantity is not charged. */
    private const FREE_VALUES = ['yes', 'y', 'free', 'foc', 'true', '1', 'free of cost', 'free of charge'];

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
        return in_array(strtolower(trim((string) $flag)), self::FREE_VALUES, true);
    }

    /** The PP quantity that is billed: zero when it is free. */
    public static function chargeable(mixed $ppQty, mixed $freeFlag): float
    {
        return self::isFree($freeFlag) ? 0.0 : self::number($ppQty);
    }
}
