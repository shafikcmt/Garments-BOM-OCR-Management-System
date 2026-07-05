<?php

namespace App\Services;

use App\Models\PaymentRequest;
use App\Models\PaymentRequestItem;
use App\Models\StyleBudget;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Style-wise budget checking for PRA creation.
 *
 * A "style" is the free-text `style_name`. A style may carry a budget (optionally
 * scoped by buyer/season). When a PRA is created, the cumulative amount already
 * committed to that style by every non-rejected PRA — plus the amount the new PRA
 * adds — is compared to the budget. Exceeding it hard-blocks creation unless an
 * authorised user overrides.
 */
class StyleBudgetService
{
    /**
     * Resolve the effective budget for a style, preferring the most specific
     * scope: style+buyer+season, then style+season, then style+buyer, then the
     * style-only (global) budget. Returns null when the style has no budget.
     */
    public function budgetFor(string $style, ?string $buyer = null, ?string $season = null): ?StyleBudget
    {
        $style = trim($style);
        if ($style === '') {
            return null;
        }

        $candidates = StyleBudget::query()
            ->whereRaw('LOWER(TRIM(style_name)) = ?', [Str::lower($style)])
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $buyer = $buyer !== null ? Str::lower(trim($buyer)) : null;
        $season = $season !== null ? Str::lower(trim($season)) : null;

        $score = function (StyleBudget $b) use ($buyer, $season) {
            $bBuyer = $b->buyer_name !== null ? Str::lower(trim($b->buyer_name)) : null;
            $bSeason = $b->season_name !== null ? Str::lower(trim($b->season_name)) : null;

            // Reject rows whose set scope conflicts with the PRA's buyer/season.
            if ($bBuyer !== null && $bBuyer !== $buyer) {
                return -1;
            }
            if ($bSeason !== null && $bSeason !== $season) {
                return -1;
            }

            return ($bBuyer !== null ? 2 : 0) + ($bSeason !== null ? 1 : 0);
        };

        return $candidates
            ->map(fn (StyleBudget $b) => ['b' => $b, 'score' => $score($b)])
            ->filter(fn ($row) => $row['score'] >= 0)
            ->sortByDesc('score')
            ->first()['b'] ?? null;
    }

    /**
     * Cumulative amount already committed to a style by live (non-rejected) PRAs.
     * Lifetime across all such PRAs, matching the "active PRA" rule used when
     * hiding already-raised POs from the pending list.
     */
    public function consumedForStyle(string $style): float
    {
        $style = trim($style);
        if ($style === '') {
            return 0.0;
        }

        return (float) PaymentRequestItem::query()
            ->join('payment_requests', 'payment_requests.id', '=', 'payment_request_items.payment_request_id')
            ->where('payment_requests.status', '!=', PaymentRequest::STATUS_REJECTED)
            ->whereRaw('LOWER(TRIM(payment_request_items.style_name)) = ?', [Str::lower($style)])
            ->sum('payment_request_items.pi_amount');
    }

    /**
     * Evaluate a set of PRA snapshot rows against style budgets.
     *
     * @param  Collection<int, array<string, mixed>>  $snapshots
     * @return array{exceeded: bool, lines: array<int, array<string, mixed>>}
     *         `lines` holds one entry per distinct style that has a budget, with
     *         the projected total and whether it is over budget.
     */
    public function evaluate(Collection $snapshots): array
    {
        $byStyle = $snapshots
            ->map(fn (array $row) => [
                'style' => trim((string) ($row['style_name'] ?? '')),
                'buyer' => (string) ($row['buyer_name'] ?? ''),
                'season' => (string) ($row['season_name'] ?? ''),
                'amount' => (float) ($row['pi_amount'] ?? 0),
            ])
            ->filter(fn (array $r) => $r['style'] !== '')
            ->groupBy(fn (array $r) => Str::lower($r['style']));

        $lines = [];
        $exceeded = false;

        foreach ($byStyle as $group) {
            $first = $group->first();
            $style = $first['style'];
            $newAmount = $group->sum('amount');

            $budget = $this->budgetFor($style, $first['buyer'] ?: null, $first['season'] ?: null);
            if (! $budget) {
                continue; // No budget set for this style -> no restriction.
            }

            $consumed = $this->consumedForStyle($style);
            $projected = $consumed + $newAmount;
            $budgetAmount = (float) $budget->budget_amount;
            $over = $projected > $budgetAmount;

            if ($over) {
                $exceeded = true;
            }

            $lines[] = [
                'style' => $style,
                'budget' => $budgetAmount,
                'consumed' => $consumed,
                'new' => (float) $newAmount,
                'projected' => $projected,
                'over_by' => $over ? ($projected - $budgetAmount) : 0.0,
                'over' => $over,
            ];
        }

        return ['exceeded' => $exceeded, 'lines' => $lines];
    }
}
