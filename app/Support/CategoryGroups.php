<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * THE category order of a requisition, used by the Category Summary, the Final
 * Requisition on screen, in PDF and in Excel, so they always read alike:
 * categories by spend, the largest first (ties A to Z), items A to Z inside
 * each category. A line with no category goes under "Uncategorised".
 */
final class CategoryGroups
{
    public const NONE = 'Uncategorised';

    /**
     * @template T
     *
     * @param  Collection<int, T>  $items
     * @param  callable(T): ?string  $category
     * @param  callable(T): float|int|string|null  $amount
     * @param  callable(T): string  $name
     * @return Collection<string, Collection<int, T>>
     */
    public static function of(Collection $items, callable $category, callable $amount, callable $name): Collection
    {
        return $items
            ->groupBy(fn ($item) => trim((string) $category($item)) ?: self::NONE)
            ->map(fn (Collection $group, string $label) => [
                'name' => $label,
                'sum' => round($group->sum(fn ($i) => (float) $amount($i)), 2),
                'items' => $group->sortBy(fn ($i) => mb_strtolower($name($i)), SORT_NATURAL)->values(),
            ])
            ->sort(fn (array $a, array $b) => [$b['sum'], mb_strtolower($a['name'])] <=> [$a['sum'], mb_strtolower($b['name'])])
            ->mapWithKeys(fn (array $g) => [$g['name'] => $g['items']]);
    }

    /** The same, for the report's row arrays. */
    public static function ofRows(Collection $rows): Collection
    {
        return self::of($rows, fn (array $r) => $r['category'] ?? null, fn (array $r) => $r['amount'] ?? 0, fn (array $r) => (string) ($r['item_name'] ?? ''));
    }
}
