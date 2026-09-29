<?php

namespace App\Support;

/**
 * THE quantity format, for every screen, form, PDF and Excel file that shows
 * a quantity (requisitions, Stock Summary, Requirement vs Issue, the monthly
 * report).
 *
 *   10       → "10"        whole numbers carry no decimals
 *   12.5     → "12.5"      otherwise trimmed, at most 4 decimals
 *   0.25     → "0.25"
 *   1200     → "1,200"     thousands separator on screen and paper
 *
 * Safety Stock is shown to at most 2 decimals (owner, 2026-09-29): 2.6923 →
 * "2.69", 0.3 → "0.3", 3 → "3" — safety() / SAFETY_DECIMALS — on the Stock
 * Report and the requisitions alike. The stored figure keeps its decimals.
 *
 * The database keeps decimal(…, 4) and validation is unchanged; this is only
 * how a stored figure is written out. Money is not a quantity — it keeps its
 * two decimals wherever it is printed.
 *
 * Excel gets real numbers (plain()) and a per-cell format (excelFormat()), so
 * a whole number never shows a trailing dot ("200.") the way "#,##0.####"
 * does.
 */
final class Quantity
{
    public const EXCEL_WHOLE = '#,##0';

    public const EXCEL_FRACTION = '#,##0.00##';

    /** A 2-decimal figure with its trailing zero trimmed: 2.69, 0.3. */
    public const EXCEL_FRACTION_2 = '#,##0.0#';

    /** Decimals Safety Stock is shown with. */
    public const SAFETY_DECIMALS = 2;

    /**
     * Money — rate, amount, value, price — in every export: always two
     * decimals (owner, 2026-09-29). Kept here beside the quantity formats so
     * no export picks its own.
     */
    public const EXCEL_MONEY = '#,##0.00';

    /** For reading: "1,200", "12.5", or $empty when there is no figure. */
    public static function format(mixed $value, string $empty = '—', int $decimals = 4): string
    {
        return self::write($value, $empty, ',', $decimals);
    }

    /** Safety Stock for reading: at most 2 decimals, trailing zeros trimmed. */
    public static function safety(mixed $value, string $empty = '—'): string
    {
        return self::format($value, $empty, self::SAFETY_DECIMALS);
    }

    /**
     * For a form input or an Excel cell: no thousands separator, so the
     * browser and the spreadsheet both read it as a number. Blank when there
     * is no figure.
     */
    public static function plain(mixed $value): string
    {
        return self::write($value, '', '', 4);
    }

    /** The Excel number format for one cell holding this value, shown to $decimals. */
    public static function excelFormat(float|int|string $value, int $decimals = 4): string
    {
        if (self::isWhole(round((float) $value, $decimals))) {
            return self::EXCEL_WHOLE;
        }

        return $decimals === self::SAFETY_DECIMALS ? self::EXCEL_FRACTION_2 : self::EXCEL_FRACTION;
    }

    /**
     * Give every number in a range of quantity cells its own format
     * (excelFormat()). Text cells ("—") are left alone.
     */
    public static function applyExcelFormats(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $range, int $decimals = 4): void
    {
        foreach ($sheet->rangeToArray($range, null, false, false, true) as $row => $cells) {
            foreach ($cells as $column => $value) {
                if (is_int($value) || is_float($value)) {
                    $sheet->getStyle($column.$row)->getNumberFormat()->setFormatCode(self::excelFormat($value, $decimals));
                }
            }
        }
    }

    private static function write(mixed $value, string $empty, string $thousands, int $decimals): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return $empty;
        }

        $number = round((float) $value, $decimals);

        // round() can leave -0.0, which number_format prints as "-0".
        if ($number == 0.0) {
            return '0';
        }

        if (self::isWhole($number)) {
            return number_format($number, 0, '.', $thousands);
        }

        return rtrim(rtrim(number_format($number, $decimals, '.', $thousands), '0'), '.');
    }

    private static function isWhole(float $number): bool
    {
        return abs($number - round($number)) < 0.00005;
    }
}
