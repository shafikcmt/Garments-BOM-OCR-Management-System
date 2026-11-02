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

    /**
     * Other spellings of the standard units. Every spelling on the left is the
     * same unit as the name on the right, so "Pcs", "pcs." and "Pieces" are all
     * "Piece" — one unit, one name, wherever it is saved or counted.
     */
    private const SYNONYMS = [
        'Piece' => ['piece', 'pieces', 'pcs', 'pc', 'pce', 'pces', 'nos', 'ea', 'each'],
        'Pair' => ['pair', 'pairs', 'prs', 'pr'],
        'Set' => ['set', 'sets'],
        'Dozen' => ['dozen', 'dozens', 'doz', 'dzn'],
        'Box' => ['box', 'boxes', 'bx'],
        'Pack' => ['pack', 'packs', 'packet', 'packets', 'pkt', 'pkts', 'pak'],
        'Carton' => ['carton', 'cartons', 'ctn', 'ctns'],
        'Roll' => ['roll', 'rolls', 'rl'],
        'Cone' => ['cone', 'cones'],
        'Book' => ['book', 'books'],
        'Bottle' => ['bottle', 'bottles', 'btl', 'btls'],
        'Can' => ['can', 'cans'],
        'Bag' => ['bag', 'bags'],
        'Kg' => ['kg', 'kgs', 'kilogram', 'kilograms', 'kilo', 'kilos'],
        'Gram' => ['gram', 'grams', 'gm', 'gms', 'g'],
        'Litre' => ['litre', 'litres', 'liter', 'liters', 'ltr', 'ltrs', 'l'],
        'Meter' => ['meter', 'meters', 'metre', 'metres', 'mtr', 'mtrs', 'm'],
        'Yard' => ['yard', 'yards', 'yd', 'yds'],
        'Feet' => ['feet', 'foot', 'ft', 'fts'],
        'Inch' => ['inch', 'inches'],
    ];

    private static ?array $cached = null;

    /** @var array<string, string>|null spelling => unit group */
    private static ?array $map = null;

    /**
     * The unit group a spelling belongs to ("pcs." → "Piece"), or null for a
     * unit that is not in the list.
     */
    public static function group(?string $uom): ?string
    {
        $uom = trim((string) $uom);

        if ($uom === '') {
            return null;
        }

        self::$map ??= collect(self::SYNONYMS)
            ->flatMap(fn (array $spellings, string $unit) => collect($spellings)->mapWithKeys(fn ($w) => [$w => $unit]))
            ->all();

        return self::$map[trim(preg_replace('/\s+/', ' ', str_replace('.', '', mb_strtolower($uom))))] ?? null;
    }

    /**
     * A function that turns any spelling into THE one name its unit goes by.
     *
     * The name is the spelling the Item Master already uses most for that unit,
     * so a store that writes "Pkt" keeps "Pkt" and "Pack" or "Packet" become
     * "Pkt"; "Pcs", "Pieces" and "Piece" all become whichever of them most items
     * carry. A unit nobody has used yet keeps the spelling typed, and a unit not
     * in the list keeps its own spelling (trimmed). Reads the counts once, so the
     * caller can run it over a whole report.
     *
     * @return \Closure(?string): ?string
     */
    public static function resolver(): \Closure
    {
        $used = [];

        foreach (StockItem::query()->whereNotNull('uom')->where('uom', '<>', '')
            ->selectRaw('uom, COUNT(*) as items')->groupBy('uom')->pluck('items', 'uom') as $spelling => $count) {
            if ($group = self::group($spelling)) {
                $used[$group][trim((string) $spelling)] = ($used[$group][trim((string) $spelling)] ?? 0) + (int) $count;
            }
        }

        // Most items first; a tie goes to the standard name, then A to Z.
        $preferred = collect($used)->map(function (array $spellings, string $group) {
            uksort($spellings, fn ($a, $b) => [$spellings[$b], $b === $group ? 0 : 1, $a] <=> [$spellings[$a], $a === $group ? 0 : 1, $b]);

            return array_key_first($spellings);
        })->all();

        return function (?string $uom) use ($preferred): ?string {
            $typed = trim((string) $uom);

            if ($typed === '') {
                return null;
            }

            $group = self::group($typed);

            return $group !== null && isset($preferred[$group]) ? $preferred[$group] : $typed;
        };
    }

    /** One name for this unit (see resolver()). */
    public static function canonical(?string $uom): ?string
    {
        return self::resolver()($uom);
    }

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

        // One name per unit (Pcs / Pieces → Piece), case-insensitive, then A to Z.
        $one = self::resolver();

        return $all->map(fn ($u) => $one($u))->filter()->unique(fn ($u) => mb_strtolower($u))->sortBy(fn ($u) => mb_strtolower($u))->values()->all();
    }
}
