<?php

namespace App\Services;

use App\Models\Inquiry;
use App\Models\MotherStyle;
use App\Models\OrderLine;
use Illuminate\Support\Collection;

/**
 * Merchandising analytics: what the orders are worth and what is left of the
 * price, by buyer (or by season of one buyer), plus the inquiry win rate.
 *
 * Order quantity is the style's order lines (the same colour / PO in two files
 * counts once, as on the Size Breakdown). Price and cost come from the style's
 * latest FINAL buyer pre-costing, per piece (a dozen sheet is divided by 12), so
 * these are ESTIMATES until post-costing exists; a style with no final costing
 * is counted as "not costed" and left out of the money columns. Different
 * currencies are never added together: a group with two currencies has two rows.
 */
class MerchantAnalytics
{
    public function __construct(private Costings $costings)
    {
    }

    /**
     * @param  list<int>  $buyerIds
     * @return array{rows: list<array<string, mixed>>, styles: list<array<string, mixed>>, uncosted: int, uncosted_qty: float, group_by: string}
     */
    public function build(array $buyerIds, ?int $buyerId = null): array
    {
        $ids = $buyerId ? [$buyerId] : $buyerIds;
        $mothers = MotherStyle::whereIn('buyer_id', $ids)->with(['buyer:id,buyer_name', 'season:id,code'])->get();
        $finals = $this->costings->latestFinal($mothers);

        $lines = OrderLine::whereIn('mother_style_id', $mothers->pluck('id'))->get(['mother_style_id', 'gmts_color', 'customer_po_no', 'order_qty'])
            ->groupBy('mother_style_id');

        $groupBy = $buyerId ? 'season' : 'buyer';
        $styles = [];
        $uncosted = 0;
        $uncostedQty = 0.0;

        foreach ($mothers as $m) {
            $qty = $this->quantity($lines->get($m->id, collect()));
            if ($qty <= 0) {
                continue;
            }
            $costing = $finals->get($m->id);
            if (! $costing || $costing->quoted_price === null || (float) $costing->quoted_price <= 0) {
                $uncosted++;
                $uncostedQty += $qty;

                continue;
            }
            $perUnit = $costing->basis === 'dz' ? 12 : 1;
            $price = (float) $costing->quoted_price / $perUnit;
            $cost = (float) $costing->total_cost / $perUnit;

            $styles[] = [
                'style' => $m, 'group' => $groupBy === 'buyer' ? ($m->buyer?->buyer_name ?? '—') : ($m->season?->code ?? '—'),
                'currency' => $costing->currency, 'qty' => $qty, 'revenue' => $qty * $price, 'cost' => $qty * $cost,
                'margin' => round(($price - $cost) / $price * 100, 1), 'revision' => $costing->revision,
            ];
        }

        $rows = collect($styles)->groupBy(fn ($s) => $s['group'] . '|' . $s['currency'])->map(function (Collection $g) {
            $revenue = $g->sum('revenue');
            $cost = $g->sum('cost');

            return [
                'group' => $g->first()['group'], 'currency' => $g->first()['currency'], 'styles' => $g->count(), 'qty' => $g->sum('qty'),
                'revenue' => $revenue, 'cost' => $cost, 'profit' => $revenue - $cost,
                'margin' => $revenue > 0 ? round(($revenue - $cost) / $revenue * 100, 1) : null,
            ];
        })->sortBy(fn ($r) => [$r['group'], $r['currency']])->values()->all();

        return [
            'rows' => $rows,
            'styles' => collect($styles)->sortBy('margin')->values()->all(),
            'uncosted' => $uncosted,
            'uncosted_qty' => $uncostedQty,
            'group_by' => $groupBy,
        ];
    }

    /**
     * Inquiry results per buyer.
     *
     * @param  list<int>  $buyerIds
     * @return list<array{buyer: string, open: int, won: int, lost: int, win_rate: ?int}>
     */
    public function inquiryResults(array $buyerIds, ?int $buyerId = null): array
    {
        return Inquiry::whereIn('buyer_id', $buyerId ? [$buyerId] : $buyerIds)->with('buyer:id,buyer_name')->get()
            ->groupBy('buyer_id')->map(function (Collection $g) {
                $won = $g->where('status', 'won')->count();
                $lost = $g->where('status', 'lost')->count();

                return [
                    'buyer' => $g->first()->buyer?->buyer_name ?? '—',
                    'open' => $g->filter(fn (Inquiry $i) => $i->isOpen())->count(),
                    'won' => $won, 'lost' => $lost,
                    'win_rate' => $won + $lost > 0 ? (int) round($won * 100 / ($won + $lost)) : null,
                ];
            })->sortBy('buyer')->values()->all();
    }

    /** One quantity per colour / customer PO: the largest seen, so a repeat in another file is not added twice. */
    private function quantity(Collection $lines): float
    {
        return (float) $lines->groupBy(fn ($l) => mb_strtolower(trim((string) $l->gmts_color)) . '|' . mb_strtolower(trim((string) $l->customer_po_no)))
            ->sum(fn ($g) => (float) $g->max('order_qty'));
    }
}
