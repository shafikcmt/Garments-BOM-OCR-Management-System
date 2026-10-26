<?php

namespace App\Services;

use App\Models\BomItem;
use App\Models\Costing;
use App\Models\ExcelCell;
use Illuminate\Support\Collection;

/**
 * Pre-costing against the style's actual BOM: for each material line of the
 * costing, the BOM material with the same name, its consumption per garment
 * (incl. wastage) and the PI rate Supply Chain recorded, so the estimated and
 * the actual material cost sit side by side. BOM materials nobody costed are
 * listed too. Names are matched loosely (same words, any case); the PI rate is
 * found by its column name, never by buyer.
 */
class CostingVsBom
{
    public function __construct(private HeaderAliasResolver $headers)
    {
    }

    /**
     * @return array{rows: Collection, uncosted: Collection, costing_total: float, bom_total: float, priced: int, has_bom: bool}
     */
    public function compare(Costing $costing): array
    {
        $costing->loadMissing('lines');
        $perUnit = $costing->basis === 'dz' ? 12 : 1;
        $bom = $this->bomMaterials($costing->mother_style_id, $perUnit);

        $materialHeads = collect(Costings::HEADS)->filter(fn ($h) => $h[1] === 'material')->keys()->all();
        $lines = $costing->lines->whereIn('head', $materialHeads)->filter(fn ($l) => trim((string) $l->description) !== '');

        $used = [];
        $rows = $lines->map(function ($line) use ($bom, &$used) {
            $match = $this->match($line->description, $bom, $used);
            if ($match) {
                $used[] = $match['key'];
            }
            $costQty = (float) $line->qty * (1 + (float) $line->wastage_pct / 100);
            $bomAmount = $match && $match['rate'] !== null ? round($match['cons'] * $match['rate'], 4) : null;

            return [
                'description' => $line->description,
                'head' => Costings::HEADS[$line->head][0] ?? $line->head,
                'cost_qty' => $costQty,
                'cost_rate' => (float) $line->rate,
                'cost_amount' => (float) $line->amount,
                'bom' => $match,
                'bom_amount' => $bomAmount,
                'difference' => $bomAmount !== null ? $bomAmount - (float) $line->amount : null,
            ];
        })->values();

        $uncosted = $bom->reject(fn ($m) => in_array($m['key'], $used, true))->values();

        return [
            'rows' => $rows,
            'uncosted' => $uncosted,
            'costing_total' => (float) $rows->sum('cost_amount'),
            'bom_total' => (float) $rows->sum(fn ($r) => $r['bom_amount'] ?? 0) + (float) $uncosted->sum(fn ($m) => $m['rate'] !== null ? $m['cons'] * $m['rate'] : 0),
            'priced' => $rows->whereNotNull('bom_amount')->count(),
            'has_bom' => $bom->isNotEmpty(),
        ];
    }

    /** BOM materials of the style, one per name, with average consumption and PI rate. */
    private function bomMaterials(int $motherId, int $perUnit): Collection
    {
        $items = BomItem::whereHas('orderLine', fn ($q) => $q->where('mother_style_id', $motherId))->get();
        if ($items->isEmpty()) {
            return collect();
        }

        $rateHeaders = $this->headers->headerIdsForCanonical('pi_rate');
        $rates = [];
        if ($rateHeaders !== []) {
            foreach (array_chunk($items->pluck('excel_row_id')->filter()->unique()->values()->all(), 900) as $chunk) {
                ExcelCell::whereIn('row_id', $chunk)->whereIn('header_id', $rateHeaders)->get(['row_id', 'value'])
                    ->each(function ($c) use (&$rates) {
                        $v = str_replace([',', ' '], '', (string) $c->value);
                        if (is_numeric($v) && (float) $v > 0) {
                            $rates[$c->row_id] = (float) $v;
                        }
                    });
            }
        }

        return $items->groupBy(fn ($i) => $this->norm($i->material_description))
            ->reject(fn ($g, $key) => $key === '')
            ->map(function (Collection $group, string $key) use ($rates, $perUnit) {
                $cons = $group->map(function ($i) {
                    if ($i->consumption_incl_yy !== null) {
                        return (float) $i->consumption_incl_yy;
                    }

                    return $i->initial_consumption !== null ? (float) $i->initial_consumption * (1 + (float) $i->wastage_percent / 100) : null;
                })->filter(fn ($v) => $v !== null);
                $rowRates = $group->map(fn ($i) => $rates[$i->excel_row_id] ?? null)->filter();

                return [
                    'key' => $key,
                    'description' => $group->first()->material_description,
                    'uom' => $group->pluck('uom')->filter()->first(),
                    'cons' => $cons->isNotEmpty() ? round($cons->avg() * $perUnit, 6) : 0.0,
                    'rate' => $rowRates->isNotEmpty() ? round($rowRates->avg(), 4) : null,
                ];
            })->values();
    }

    private function match(string $description, Collection $bom, array $used): ?array
    {
        $want = $this->norm($description);
        $free = $bom->reject(fn ($m) => in_array($m['key'], $used, true));

        return $free->firstWhere('key', $want)
            ?? $free->first(fn ($m) => $want !== '' && (str_contains($m['key'], $want) || str_contains($want, $m['key'])));
    }

    private function norm(?string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower((string) $text))));
    }
}
