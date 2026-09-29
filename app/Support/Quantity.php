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

    /**
     * Money — rate, amount, value, price — in every export: always two
     * decimals (owner, 2026-09-29). Kept here beside the quantity formats so
     * no export picks its own.
     */
    public const EXCEL_MONEY = '#,##0.00';

    /** For reading: "1,200", "12.5", or $empty when there is no figure. */
    public static function format(mixed $value, string $empty = '—'): string
    {
        return self::write($value, $empty, ',');
    }

    /**
     * For a form input or an Excel cell: no thousands separator, so the
     * browser and the spreadsheet both read it as a number. Blank when there
     * is no figure.
     */
    public static function plain(mixed $value): string
    {
        return self::write($value, '', '');
    }

    /** The Excel number format for one cell holding this value. */
    public static function excelFormat(float|int|string $value): string
    {
        return self::isWhole((float) $value) ? self::EXCEL_WHOLE : self::EXCEL_FRACTION;
    }

    /**
     * Give every number in a range of quantity cells its own format
     * (excelFormat()). Text cells ("—") are left alone.
     */
    public static function applyExcelFormats(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $range): void
    {
        foreach ($sheet->rangeToArray($range, null, false, false, true) as $row => $cells) {
            foreach ($cells as $column => $value) {
                if (is_int($value) || is_float($value)) {
                    $sheet->getStyle($column.$row)->getNumberFormat()->setFormatCode(self::excelFormat($value));
                }
            }
        }
    }

    private static function write(mixed $value, string $empty, string $thousands): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return $empty;
        }

        $number = round((float) $value, 4);

        // round() can leave -0.0, which number_format prints as "-0".
        if ($number == 0.0) {
            return '0';
        }

        if (self::isWhole($number)) {
            return number_format($number, 0, '.', $thousands);
        }

        return rtrim(rtrim(number_format($number, 4, '.', $thousands), '0'), '.');
    }

    private static function isWhole(float $number): bool
    {
        return abs($number - round($number)) < 0.00005;
    }
}
