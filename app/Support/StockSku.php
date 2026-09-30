<?php

namespace App\Support;

use App\Models\StockItem;

/**
 * The Item Master SKU: category code + item initials + a 4-digit serial,
 * written without hyphens.
 *
 *   Stationery & Printing / Cut Panel Inspection Report  →  STCPI0001
 *   Stationery & Printing / Ball Pen Black               →  STBPB0002
 *   Needle / B/S Needle DPX17                            →  NDBND0001
 *
 * The serial runs per category code, so the second Stationery item is 0002
 * whatever its initials. A SKU typed on the form is kept as typed; this only
 * fills one in when the field is left blank.
 */
class StockSku
{
    /** Used when an item has no category. */
    public const NO_CATEGORY_CODE = 'GN';

    /** Two letters for a category: its first letter and the next consonant ("Stationery" → ST, "Needle" → ND). */
    public static function categoryCode(?string $category): string
    {
        $word = self::words($category)[0] ?? '';

        if ($word === '') {
            return self::NO_CATEGORY_CODE;
        }

        $rest = substr($word, 1);
        $next = preg_replace('/[AEIOU]/', '', $rest)[0] ?? ($rest[0] ?? 'X');

        return $word[0].$next;
    }

    /** Three characters from the item name: the first letter of its first three words, topped up from the last word when the name is shorter ("Air Gun" → AGU). */
    public static function itemCode(string $name): string
    {
        $words = self::words($name);
        $code = implode('', array_map(fn ($w) => $w[0], array_slice($words, 0, 3)));

        if (strlen($code) < 3) {
            $code .= substr((string) end($words), 1);
        }

        return str_pad(substr($code, 0, 3), 3, 'X');
    }

    /** The next free SKU for this name and category. */
    public static function generate(string $name, ?string $category): string
    {
        $prefix = self::categoryCode($category);

        $last = StockItem::query()
            ->whereLike('sku', $prefix.'%')
            ->pluck('sku')
            ->map(fn ($sku) => preg_match('/^'.$prefix.'[A-Z0-9]{3}(\d{4})$/', strtoupper((string) $sku), $m) ? (int) $m[1] : 0)
            ->max() ?? 0;

        return $prefix.self::itemCode($name).str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Upper-case words, letters and digits only ("B/S Needle" → ["BS", "NEEDLE"]). */
    private static function words(?string $text): array
    {
        return array_values(array_filter(array_map(
            fn ($w) => preg_replace('/[^A-Z0-9]/', '', strtoupper($w)),
            preg_split('/\s+/', trim((string) $text)) ?: []
        )));
    }
}
