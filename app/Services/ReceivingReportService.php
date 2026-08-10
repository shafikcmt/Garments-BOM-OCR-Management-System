<?php

namespace App\Services;

use App\Models\StockPurchase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * General Stock (module A) — the Receiving report.
 *
 * Read-only, and entirely separate from the Record Receiving screen: this
 * compiles what that screen recorded, it never writes.
 *
 * One row per DELIVERY, not per line, using the same grouping Purchase History
 * uses — StockPurchase::groupKeyExpr(). That is deliberate and it is the whole
 * point: a report that counted lines instead of deliveries would disagree with
 * the screen it is printed from.
 *
 * A delivery is included when ANY of its lines matches the filters, and it is
 * then rebuilt from ALL of its lines. Filtering inside the grouped query would
 * make the line count and totals describe only the matching lines, which for a
 * six-item challan filtered by one item would print a total nobody can
 * reconcile against the paper document.
 */
class ReceivingReportService
{
    /**
     * Normalise a "YYYY-MM" input into the first day of that month. Unlike the
     * monthly requisition report there is NO fallback to the current month —
     * this report's month filter is optional, so a blank month means "every
     * delivery", not "this month".
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
     * One row per delivery, newest first.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, object>
     */
    public function rows(array $filters = []): Collection
    {
        $key = StockPurchase::groupKeyExpr();

        $matched = $this->applyFilters(StockPurchase::query(), $filters)
            ->distinct()
            ->pluck(DB::raw($key.' as group_key'));

        if ($matched->isEmpty()) {
            return collect();
        }

        return StockPurchase::query()
            ->selectRaw($key.' as group_key')
            ->selectRaw('rv_no, challan_no, purchase_date')
            ->selectRaw('MAX(rcv_date) as rcv_date, MAX(supplier_name) as supplier_name')
            ->selectRaw('COUNT(*) as line_count, SUM(qty) as group_total_qty')
            // Deliberately NOT aliased "total_value": StockPurchase has a
            // getTotalValueAttribute() accessor, and an accessor wins over a
            // selected column — it would recompute qty x unit_price from
            // attributes this grouped row does not carry and return 0.
            ->selectRaw('SUM(qty * COALESCE(unit_price, 0)) as group_total_value, MAX(id) as latest_id')
            ->whereIn(DB::raw($key), $matched->all())
            ->groupBy('rv_no', 'challan_no', 'purchase_date')
            ->orderByDesc('purchase_date')
            ->orderByDesc(DB::raw('MAX(id)'))
            ->get();
    }

    /**
     * Every line of the given deliveries, keyed by group, for the detail rows
     * under each delivery on the PDF.
     *
     * @param  Collection<int, object>  $rows
     * @return Collection<string, Collection<int, StockPurchase>>
     */
    public function lines(Collection $rows): Collection
    {
        $keys = $rows->pluck('group_key')->all();

        if (empty($keys)) {
            return collect();
        }

        $key = StockPurchase::groupKeyExpr();

        return StockPurchase::with(['stockItem'])
            ->selectRaw('*, '.$key.' as group_key')
            ->whereIn(DB::raw($key), $keys)
            ->orderBy('id')
            ->get()
            ->groupBy('group_key');
    }

    /**
     * Headline figures for the filtered set.
     *
     * @param  Collection<int, object>  $rows
     * @return array<string, mixed>
     */
    public function summary(Collection $rows): array
    {
        return [
            'deliveries' => $rows->count(),
            'lines' => (int) $rows->sum('line_count'),
            'qty' => (float) $rows->sum('group_total_qty'),
            'value' => (float) $rows->sum('group_total_value'),
            'suppliers' => $rows->pluck('supplier_name')->filter()->unique()->count(),
        ];
    }

    /** Supplier names present in the data, for the filter dropdown. */
    public function suppliers(): Collection
    {
        return StockPurchase::query()
            ->whereNotNull('supplier_name')
            ->where('supplier_name', '<>', '')
            ->distinct()
            ->orderBy('supplier_name')
            ->pluck('supplier_name');
    }

    /**
     * Every filter the screen offers, applied to a line-level query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters($query, array $filters)
    {
        $month = $this->resolveMonth($filters['month'] ?? null);

        return $query
            ->when($month, fn ($q, $m) => $q
                ->whereYear('purchase_date', $m->year)
                ->whereMonth('purchase_date', $m->month))
            // whereLike compiles to ILIKE on PostgreSQL, where a plain LIKE is
            // case-sensitive, and stays LIKE everywhere else.
            ->when($filters['challan_no'] ?? null, fn ($q, $v) => $q->whereLike('challan_no', '%'.$v.'%'))
            ->when($filters['rv_no'] ?? null, fn ($q, $v) => $q->whereLike('rv_no', '%'.$v.'%'))
            ->when($filters['supplier'] ?? null, fn ($q, $v) => $q->whereLike('supplier_name', '%'.$v.'%'))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $like = '%'.$search.'%';
                $q->where(fn ($w) => $w->whereLike('challan_no', $like)
                    ->orWhereLike('rv_no', $like)
                    ->orWhereLike('supplier_name', $like)
                    ->orWhereHas('stockItem', fn ($i) => $i->whereLike('name', $like)));
            })
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('purchase_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('purchase_date', '<=', $v))
            ->when($filters['rcv_from'] ?? null, fn ($q, $v) => $q->whereDate('rcv_date', '>=', $v))
            ->when($filters['rcv_to'] ?? null, fn ($q, $v) => $q->whereDate('rcv_date', '<=', $v));
    }
}
