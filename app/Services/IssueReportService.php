<?php

namespace App\Services;

use App\Models\StockIssue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * General Stock (module A) — the Issues (Consumption) report.
 *
 * Read-only, and entirely separate from the Record Issue screen: this compiles
 * what that screen recorded, it never writes.
 *
 * One row per issue LINE, which is how an issue is stored and how Issue History
 * already lists it. Deliberately not grouped by requisition the way the
 * Receiving report groups by delivery: a requisition is a request, while the
 * thing being reported here is what physically left the shelf, item by item.
 * The requisition is still counted in the summary, so a reader can see how many
 * documents the lines came from.
 */
class IssueReportService
{
    /**
     * Normalise a "YYYY-MM" input into the first day of that month. A blank
     * month means "every issue", not "this month" — the month filter on this
     * report is optional.
     */
    public function resolveMonth(?string $month): ?Carbon
    {
        if (! $month) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The filtered issue lines, newest first, with everything the report prints
     * already eager-loaded — a 500-line month would otherwise fire thousands of
     * lazy queries while rendering the PDF.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, StockIssue>
     */
    public function rows(array $filters = []): Collection
    {
        $month = $this->resolveMonth($filters['month'] ?? null);

        return StockIssue::query()
            ->with(['stockItem', 'indentSection', 'indentPerson', 'approver', 'itemCategory'])
            ->when($month, fn ($q, $m) => $q
                ->whereYear('issue_date', $m->year)
                ->whereMonth('issue_date', $m->month))
            // whereLike compiles to ILIKE on PostgreSQL, where a plain LIKE is
            // case-sensitive, and stays LIKE everywhere else.
            ->when($filters['requisition_no'] ?? null, fn ($q, $v) => $q->whereLike('requisition_no', '%'.$v.'%'))
            ->when($filters['item'] ?? null, fn ($q, $v) => $q->where('stock_item_id', $v))
            ->when($filters['section'] ?? null, fn ($q, $v) => $q->where('indent_section_id', $v))
            ->when($filters['person'] ?? null, fn ($q, $v) => $q->where('indent_person_id', $v))
            ->when($filters['category'] ?? null, fn ($q, $v) => $q->where('item_category_id', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('requisition_type', $v))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $like = '%'.$search.'%';
                $q->where(fn ($w) => $w->whereLike('requisition_no', $like)
                    ->orWhereHas('stockItem', fn ($i) => $i->whereLike('name', $like)));
            })
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('issue_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('issue_date', '<=', $v))
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Headline figures for the filtered set.
     *
     * Requisition No is optional on an issue, so it is counted only where one
     * was given — otherwise every blank would collapse into a single phantom
     * "requisition" and the count would read one too high.
     *
     * @param  Collection<int, StockIssue>  $rows
     * @return array<string, mixed>
     */
    public function summary(Collection $rows): array
    {
        return [
            'lines' => $rows->count(),
            'qty' => (float) $rows->sum('qty'),
            'items' => $rows->pluck('stock_item_id')->filter()->unique()->count(),
            'requisitions' => $rows->pluck('requisition_no')
                ->map(fn ($no) => trim((string) $no))
                ->filter()
                ->unique()
                ->count(),
            'sections' => $rows->pluck('indent_section_id')->filter()->unique()->count(),
        ];
    }

    /**
     * The same lines grouped by item category, for the per-category subtotals
     * the report prints under the main table.
     *
     * @param  Collection<int, StockIssue>  $rows
     * @return Collection<string, array<string, mixed>>
     */
    public function byCategory(Collection $rows): Collection
    {
        return $rows
            ->groupBy(fn (StockIssue $row) => optional($row->itemCategory)->name ?: 'Uncategorised')
            ->map(fn (Collection $group) => [
                'lines' => $group->count(),
                'qty' => (float) $group->sum('qty'),
                'items' => $group->pluck('stock_item_id')->filter()->unique()->count(),
            ])
            ->sortKeys();
    }
}
