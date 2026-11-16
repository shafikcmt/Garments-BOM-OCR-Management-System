<?php

namespace App\Services;

use App\Models\GsComparativeStatement;
use App\Models\GsCsItem;
use App\Models\GsCsQuote;
use Illuminate\Support\Collection;

/**
 * Price comparison of a Comparative Statement: what each supplier's column adds
 * up to, what the chosen suppliers add up to, what the cheapest quote on every
 * line would add up to, and the lines where the chosen supplier is not the
 * cheapest. It only reads; nothing is saved or changed, and it is shown on the
 * screen, not on the printed form.
 *
 * "Cheapest" is the lowest unit price quoted for the line. A supplier whose price
 * equals the lowest counts as the lowest. Needs items.quotes and suppliers loaded.
 */
class CsComparison
{
    /**
     * @return array{
     *   suppliers: list<array<string, mixed>>, lowest_total: float, chosen_total: float, extra: float, extra_pct: ?float,
     *   not_lowest: list<array<string, mixed>>, unchosen: int, best_single: ?array<string, mixed>, has_quotes: bool
     * }
     */
    public function for(GsComparativeStatement $cs): array
    {
        $items = $cs->items;
        $quoted = fn (GsCsItem $i): Collection => $i->quotes->filter(fn (GsCsQuote $q) => $q->unit_price !== null);

        $suppliers = $cs->suppliers->map(function ($s) use ($items, $quoted) {
            $total = 0.0;
            $count = 0;
            $lowestOn = 0;
            $chosenOn = 0;
            $chosenAmount = 0.0;

            foreach ($items as $item) {
                $q = $item->quoteFor($s->id);
                if ($q && $q->unit_price !== null) {
                    $count++;
                    $total += (float) $item->qty * (float) $q->unit_price;
                    $min = $quoted($item)->min(fn ($x) => (float) $x->unit_price);
                    if ((float) $q->unit_price <= (float) $min) {
                        $lowestOn++;
                    }
                }
                if ((int) $item->selected_supplier_id === (int) $s->id && $q && $q->unit_price !== null) {
                    $chosenOn++;
                    $chosenAmount += (float) $item->qty * (float) $q->unit_price;
                }
            }

            return [
                'supplier' => $s, 'quoted' => $count, 'total' => round($total, 2), 'complete' => $items->isNotEmpty() && $count === $items->count(),
                'lowest_on' => $lowestOn, 'chosen_on' => $chosenOn, 'chosen_amount' => round($chosenAmount, 2),
            ];
        })->values();

        $lowestTotal = 0.0;
        $chosenTotal = 0.0;
        $notLowest = [];
        $unchosen = 0;

        foreach ($items as $item) {
            $q = $quoted($item);
            if ($q->isEmpty()) {
                continue;
            }
            $selected = $item->selectedQuote();
            if (! $selected || $selected->unit_price === null) {
                $unchosen++;

                continue;
            }
            $lowest = $q->sortBy(fn ($x) => (float) $x->unit_price)->first();
            $qty = (float) $item->qty;
            $lowestTotal += $qty * (float) $lowest->unit_price;
            $chosenTotal += $qty * (float) $selected->unit_price;

            if ((float) $selected->unit_price > (float) $lowest->unit_price) {
                $notLowest[] = [
                    'item' => $item, 'qty' => $qty,
                    'chosen_name' => $cs->suppliers->firstWhere('id', (int) $selected->gs_cs_supplier_id)?->name,
                    'chosen_price' => (float) $selected->unit_price,
                    'lowest_name' => $cs->suppliers->firstWhere('id', (int) $lowest->gs_cs_supplier_id)?->name,
                    'lowest_price' => (float) $lowest->unit_price,
                    'extra' => round($qty * ((float) $selected->unit_price - (float) $lowest->unit_price), 2),
                ];
            }
        }

        $bestSingle = $suppliers->where('complete', true)->sortBy('total')->first();

        return [
            'suppliers' => $suppliers->all(),
            'lowest_total' => round($lowestTotal, 2),
            'chosen_total' => round($chosenTotal, 2),
            'extra' => round(max(0, $chosenTotal - $lowestTotal), 2),
            'extra_pct' => $lowestTotal > 0 ? round(max(0, $chosenTotal - $lowestTotal) / $lowestTotal * 100, 1) : null,
            'not_lowest' => $notLowest,
            'unchosen' => $unchosen,
            'best_single' => $bestSingle,
            'has_quotes' => $items->contains(fn ($i) => $quoted($i)->isNotEmpty()),
        ];
    }
}
