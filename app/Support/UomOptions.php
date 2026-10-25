<?php

namespace App\Support;

use App\Models\StockItem;

/**
 * The unit-of-measure choices for the Item Master dropdown.
 *
 * A standard list, plus every UOM already stored on an item so nothing in use
 * disappears from the list, plus the item's own current value when editing.
 */
class UomOptions
{
    public const STANDARD = [
        'Piece', 'Pair', 'Set', 'Dozen', 'Box', 'Pack', 'Carton', 'Roll', 'Cone', 'Book',
        'Bottle', 'Can', 'Bag', 'Kg', 'Gram', 'Litre', 'Meter', 'Yard', 'Feet', 'Inch',
    ];

    private static ?array $cached = null;

    /** @return list<string> */
    public static function list(?string $current = null): array
    {
        self::$cached ??= StockItem::query()
            ->whereNotNull('uom')->where('uom', '<>', '')
            ->distinct()->pluck('uom')->map(fn ($u) => trim((string) $u))->filter()->all();

        $all = collect(self::STANDARD)->merge(self::$cached);

        if (filled($current)) {
            $all->push(trim($current));
        }

        // Case-insensitive de-duplication, keeping the first spelling seen.
        return $all->unique(fn ($u) => mb_strtolower($u))->sortBy(fn ($u) => mb_strtolower($u))->values()->all();
    }
}
