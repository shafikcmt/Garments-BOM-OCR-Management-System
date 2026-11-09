<?php

namespace App\Services;

use App\Models\Costing;
use App\Models\OrderSizeQuantity;
use App\Models\StyleMaterialSize;
use Illuminate\Support\Collection;

/**
 * Cost of a garment by size.
 *
 * A pre-costing has one consumption per material line. When the style's
 * Size-wise BOM gives a material a different consumption for some sizes, the
 * cost of those sizes moves: for each size, the costing line that matches the
 * material is recalculated with the size's consumption (same wastage and rate),
 * every other line stays as costed.
 *
 *   cost of size = sheet total - matched lines as costed + matched lines at the size's consumption
 *
 * The average is weighted by the pieces of each size in the Size Breakdown, so it
 * shows what the order really costs against the single figure on the sheet.
 * Nothing is saved; the costing itself is not changed.
 */
class SizeWiseCosting
{
    /**
     * @return array{sizes: list<array<string, mixed>>, lines: list<array<string, string>>, unmatched: list<string>, average: ?float, average_margin: ?float, sheet_total: float, price: float, pieces: float}|null  null when the style has no size-wise consumption
     */
    public function forCosting(Costing $costing): ?array
    {
        $costing->loadMissing(['lines', 'motherStyle.sizeSet']);
        $mother = $costing->motherStyle;

        $groups = StyleMaterialSize::where('mother_style_id', $mother->id)->get()->groupBy('material_key');
        if ($groups->isEmpty()) {
            return null;
        }
        $materials = $groups->map(fn (Collection $rows, string $key) => [
            'key' => $key,
            'name' => (string) ($rows->first()->material_name ?: $key),
            'per_size' => $rows->mapWithKeys(fn ($r) => [(string) $r->size => (float) $r->consumption])->all(),
        ])->values();

        $perUnit = $costing->basis === 'dz' ? 12 : 1;
        $materialHeads = collect(Costings::HEADS)->filter(fn ($h) => $h[1] === 'material')->keys()->all();

        // Match each material costing line to one size-wise material.
        $used = [];
        $matched = [];
        foreach ($costing->lines->whereIn('head', $materialHeads) as $line) {
            $want = $this->norm($line->description);
            if ($want === '') {
                continue;
            }
            $free = $materials->reject(fn ($m) => in_array($m['key'], $used, true));
            $hit = $free->first(fn ($m) => $this->norm($m['name']) === $want || $this->norm($m['key']) === $want)
                ?? $free->first(fn ($m) => ($n = $this->norm($m['name'])) !== '' && (str_contains($n, $want) || str_contains($want, $n)));
            if ($hit) {
                $used[] = $hit['key'];
                $matched[] = ['line' => $line, 'material' => $hit];
            }
        }

        $pieces = OrderSizeQuantity::where('mother_style_id', $mother->id)->get()->groupBy('size')->map(fn ($g) => (float) $g->sum('qty'));
        $order = collect($mother->sizeSet?->sizes ?? []);
        $sizes = $order->merge($pieces->filter(fn ($q) => $q > 0)->keys())->merge($materials->flatMap(fn ($m) => array_keys($m['per_size'])))
            ->map(fn ($s) => (string) $s)->unique()->values();

        $total = (float) $costing->total_cost;
        $price = (float) $costing->quoted_price;

        $rows = $sizes->map(function (string $size) use ($matched, $perUnit, $total, $price, $pieces) {
            $cost = $total;
            $changed = 0;
            foreach ($matched as $m) {
                $cons = $m['material']['per_size'][$size] ?? null;
                if ($cons === null) {
                    continue;
                }
                $cost += Costings::amount($cons * $perUnit, (float) $m['line']->wastage_pct, (float) $m['line']->rate) - (float) $m['line']->amount;
                $changed++;
            }

            return [
                'size' => $size,
                'pieces' => (float) ($pieces[$size] ?? 0),
                'cost' => round($cost, 4),
                'difference' => round($cost - $total, 4),
                'margin' => $price > 0 ? round(($price - $cost) / $price * 100, 1) : null,
                'changed' => $changed,
            ];
        })->values();

        $weighted = $rows->sum('pieces');
        $average = $weighted > 0 ? round($rows->sum(fn ($r) => $r['cost'] * $r['pieces']) / $weighted, 4) : null;

        return [
            'sizes' => $rows->all(),
            'lines' => collect($matched)->map(fn ($m) => ['costing' => (string) $m['line']->description, 'material' => $m['material']['name']])->all(),
            'unmatched' => $materials->reject(fn ($m) => in_array($m['key'], $used, true))->pluck('name')->all(),
            'average' => $average,
            'average_margin' => $average !== null && $price > 0 ? round(($price - $average) / $price * 100, 1) : null,
            'sheet_total' => $total,
            'price' => $price,
            'pieces' => $weighted,
        ];
    }

    private function norm(?string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower((string) $text))));
    }
}
