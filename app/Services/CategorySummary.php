<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * At-a-glance, category-wise summary of a month's purchase requisitions, for
 * management. It reads the same monthly rows the Monthly Report does
 * (MonthlyRequisitionReportService), so its totals always agree with that
 * report; it only adds each category's share of the month and its change
 * against the month before.
 */
class CategorySummary
{
    public function __construct(private readonly MonthlyRequisitionReportService $report)
    {
    }

    /**
     * @return array{totals: array<string, mixed>, previous: array<string, mixed>, categories: Collection<int, array<string, mixed>>, top: ?array<string, mixed>}
     */
    public function build(Carbon $monthStart): array
    {
        $current = $this->report->summary($this->report->rows($monthStart));
        $previous = $this->report->summary($this->report->rows($monthStart->copy()->subMonthNoOverflow()));

        $total = (float) $current['amount'];

        $categories = collect($current['per_category'])
            ->map(function (array $row, string $name) use ($previous, $total) {
                $before = $previous['per_category'][$name]['amount'] ?? null;

                return $row + [
                    'name' => $name,
                    'share' => $total > 0 ? round($row['amount'] / $total * 100, 1) : 0.0,
                    'previous_amount' => $before,
                    // null when the category had no spend last month: a % of zero means nothing.
                    'change' => $before !== null && $before > 0 ? round(($row['amount'] - $before) / $before * 100, 1) : null,
                ];
            })
            ->sortByDesc('amount')
            ->values();

        return [
            'totals' => $current,
            'previous' => $previous,
            'categories' => $categories,
            'top' => $categories->first(),
        ];
    }
}
