<?php

namespace App\Services;

use App\Models\PurchaseRequisition;
use App\Support\CategoryGroups;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * At-a-glance, category-wise summary of a month's purchase requisitions, for
 * management.
 *
 * It is built from the counted requisition sheets AS RAISED — the same stored
 * lines the Final Requisition prints — so a Final Requisition's category
 * subtotals and this summary are the same figures. (The Monthly Report
 * recalculates a month by merging items across sheets against the latest
 * stock, so its totals can differ; that is its own, labelled, view.)
 *
 * Categories come in CategoryGroups order — largest spend first — the order
 * the Final Requisition and its Excel use, too.
 */
class CategorySummary
{
    public function __construct(private readonly MonthlyRequisitionReportService $report)
    {
    }

    /**
     * @return array{kinds: array, totals: array<string, mixed>, previous: array<string, mixed>, categories: Collection<int, array<string, mixed>>, top: ?array<string, mixed>}
     */
    public function build(Carbon $monthStart): array
    {
        $sheets = $this->sheets($monthStart);
        $current = $this->summarise($sheets);
        $previous = $this->summarise($this->sheets($monthStart->copy()->subMonthNoOverflow()));

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
            ->values();

        return [
            'kinds' => $this->kinds($sheets),
            'totals' => $current,
            'previous' => $previous,
            'categories' => $categories,
            'top' => $categories->first(),
        ];
    }

    /** The sheets that count as the month's demand, with what a row needs. */
    private function sheets(Carbon $monthStart): Collection
    {
        return PurchaseRequisition::query()
            ->countedInTotals()
            ->forMonth($monthStart->format('Y-m'))
            ->with(['items.stockItem', 'items.itemCategory', 'indentSection', 'indentPerson'])
            ->get();
    }

    /**
     * Monthly against Individual, as raised. "Monthly" is the department
     * monthly sheets and the Store's Final Requisitions; "Individual" the
     * one-off sheets that are never merged.
     *
     * @return array<string, array{label: string, requisitions: int, amount: float}>
     */
    private function kinds(Collection $sheets): array
    {
        $individual = $sheets->filter(fn (PurchaseRequisition $s) => $s->isDepartmentLevel() && $s->isIndividual());
        $monthly = $sheets->reject(fn (PurchaseRequisition $s) => $s->isDepartmentLevel() && $s->isIndividual());
        $of = fn (Collection $group) => [
            'requisitions' => $group->count(),
            'amount' => (float) $group->sum(fn (PurchaseRequisition $s) => $s->items->sum('amount')),
        ];

        return [
            PurchaseRequisition::TYPE_MONTHLY => ['label' => 'Monthly'] + $of($monthly),
            PurchaseRequisition::TYPE_INDIVIDUAL => ['label' => 'Individual'] + $of($individual),
        ];
    }

    /**
     * Headline figures and the same per category, in CategoryGroups order.
     *
     * @return array<string, mixed>
     */
    private function summarise(Collection $sheets): array
    {
        $rows = $sheets->flatMap(fn (PurchaseRequisition $s) => $this->report->requisitionRows($s))->values();

        $perCategory = CategoryGroups::ofRows($rows)->map(fn (Collection $group) => [
            'lines' => $group->count(),
            'items' => $group->pluck('stock_item_id')->unique()->count(),
            'qty_requested' => (float) $group->sum('qty_requested'),
            'to_be_procured' => (float) $group->sum('to_be_procured'),
            'amount' => (float) $group->sum('amount'),
        ]);

        return [
            'requisitions' => $sheets->count(),
            'lines' => $rows->count(),
            'items' => $rows->pluck('stock_item_id')->unique()->count(),
            'qty_requested' => (float) $rows->sum('qty_requested'),
            'to_be_procured' => (float) $rows->sum('to_be_procured'),
            'amount' => (float) $rows->sum('amount'),
            'per_category' => $perCategory,
        ];
    }
}
