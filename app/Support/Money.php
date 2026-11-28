<?php

namespace App\Support;

/**
 * Money rules shared by the Workspace and the PRA.
 */
final class Money
{
    /**
     * Cut an amount to whole cents WITHOUT rounding up.
     *
     * Supplier proforma invoices print each line cut to two decimals, not rounded
     * (65.487 prints as 65.48, 76.0596 as 76.05), so PI Amount follows the same
     * rule and the Workspace total matches the invoice to the cent. The amount is
     * first rounded to four decimals of a cent so a float such as 4.35 * 100 =
     * 434.99999999999994 is not cut a cent short.
     */
    public static function floorCents(float $amount): float
    {
        return self::floorTo($amount, 2);
    }

    /** Cut to a number of decimals (never rounded up), with the same float guard as floorCents(). */
    public static function floorTo(float $amount, int $decimals): float
    {
        $decimals = max(0, min(8, $decimals));
        $scale = 10 ** $decimals;
        $sign = $amount < 0 ? -1 : 1;

        return $sign * floor(round(abs($amount) * $scale, 4)) / $scale;
    }

    /** PI Amount: cut to the decimal places Admin set (2 unless changed). */
    public static function floorPi(float $amount): float
    {
        return self::floorTo($amount, \App\Support\PaymentRequestSettings::piDecimals());
    }
}
