<?php

namespace App\Support;

/**
 * "11710" -> "Eleven thousand seven hundred ten Taka Only." as printed on the
 * Internal PO. Whole Taka are spelt out; paisa, when there are any, follow as
 * "and Fifty Paisa". English scale words (thousand, million), as on the
 * factory's existing PO.
 */
final class AmountInWords
{
    private const ONES = [
        'zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
        'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen',
    ];

    private const TENS = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

    private const SCALES = [1_000_000_000 => 'billion', 1_000_000 => 'million', 1_000 => 'thousand'];

    public static function taka(float|int|string|null $amount): string
    {
        $amount = round((float) $amount, 2);
        $whole = (int) floor($amount);
        $paisa = (int) round(($amount - $whole) * 100);

        $words = ucfirst(self::words($whole)).' Taka';

        if ($paisa > 0) {
            $words .= ' and '.ucfirst(self::words($paisa)).' Paisa';
        }

        return $words.' Only.';
    }

    public static function words(int $n): string
    {
        if ($n < 20) {
            return self::ONES[$n];
        }

        if ($n < 100) {
            return self::TENS[intdiv($n, 10)].($n % 10 ? ' '.self::ONES[$n % 10] : '');
        }

        if ($n < 1000) {
            return self::ONES[intdiv($n, 100)].' hundred'.($n % 100 ? ' '.self::words($n % 100) : '');
        }

        foreach (self::SCALES as $size => $label) {
            if ($n >= $size) {
                return self::words(intdiv($n, $size)).' '.$label.($n % $size ? ' '.self::words($n % $size) : '');
            }
        }

        return (string) $n;
    }
}
