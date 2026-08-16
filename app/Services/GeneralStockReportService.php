<?php

namespace App\Services;

use App\Models\StockIssue;
use App\Models\StockItem;
use App\Models\StockPurchase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * General Stock (module A) — builds the Consumable Stock Report, a
 * column-for-column port of the company's Excel "Stock <Month>" sheet.
 *
 * One month of the report is a pure function of the Purchase and Consumption
 * records plus the item master, so it is recomputed on demand rather than being
 * frozen into a snapshot table. That is what makes any past month viewable and
 * exportable, and it means a backdated challan corrects history instead of
 * leaving a stale archived copy behind.
 *
 * Excel formulas this reproduces (column letters from the reference file):
 *   I  Last Month Consumption pattern = SUMIFS(prev month issued qty) / 26
 *   J  Safety Stock Level             = I * 7
 *   K  Re-order Level                 = IF(I=0,"-", J + (I * (L + 3)))
 *   Q  Stock as on Date               = Opening + Addition - Consumption
 *   S  Closing Stock Value            = Q * Unit Price
 *   B  Whether to place order or not  = IF(Q < J, "Place Order", "Ok")
 *
 * The 26 / 7 / 3 constants live in config('stock.general_stock').
 */
class GeneralStockReportService
{
    /** Stock is zero or negative — nothing left to issue. */
    public const STATUS_OUT = 'out';

    /** Below Safety Stock Level — the Excel "Place Order" flag. */
    public const STATUS_PLACE_ORDER = 'place_order';

    /** Below Re-order Level but still above safety — the earlier warning. */
    public const STATUS_LOW = 'low';

    /** Healthy. */
    public const STATUS_OK = 'ok';

    /**
     * Statuses in severity order, worst first. Used for sorting and for the
     * "at least this bad" filter on the screen.
     *
     * @var list<string>
     */
    public const SEVERITY = [self::STATUS_OUT, self::STATUS_PLACE_ORDER, self::STATUS_LOW, self::STATUS_OK];

    /** @return array<string, string> status => label */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_OUT => 'Out of Stock',
            self::STATUS_PLACE_ORDER => 'Place Order',
            self::STATUS_LOW => 'Low Stock',
            self::STATUS_OK => 'Ok',
        ];
    }

    /**
     * Normalise a "YYYY-MM" input into the first day of that month, falling
     * back to the current month for anything unparseable.
     */
    public function resolveMonth(?string $month): Carbon
    {
        try {
            return $month ? Carbon::createFromFormat('Y-m', $month)->startOfMonth() : now()->startOfMonth();
        } catch (\Throwable $e) {
            return now()->startOfMonth();
        }
    }

    /**
     * One report row per stock item for the given month.
     *
     * @param  array{search?: string|null, category?: string|null, status?: string|null, only_active?: bool, item_ids?: list<int>|int|null}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(Carbon $monthStart, array $filters = []): Collection
    {
        $monthStart = $monthStart->copy()->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $prevStart = $monthStart->copy()->subMonthNoOverflow()->startOfMonth();
        $prevEnd = $prevStart->copy()->endOfMonth();

        $items = $this->items($filters);

        if ($items->isEmpty()) {
            return collect();
        }

        $ids = $items->pluck('id')->all();
        $movements = $this->movements($ids, $monthStart, $monthEnd, $prevStart, $prevEnd);

        $rows = $items->map(fn (StockItem $item) => $this->row($item, $monthStart, $movements));

        // Status filter is applied after the row is built, because status is
        // derived and cannot be expressed in the item query.
        if (! empty($filters['status'])) {
            $rows = $this->filterByStatus($rows, $filters['status']);
        }

        return $rows->values();
    }

    /**
     * Headline counts for the dashboard card and the report banner.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int|float>
     */
    public function summary(Collection $rows): array
    {
        return [
            'items' => $rows->count(),
            'out' => $rows->where('status', self::STATUS_OUT)->count(),
            'place_order' => $rows->where('status', self::STATUS_PLACE_ORDER)->count(),
            'low' => $rows->where('status', self::STATUS_LOW)->count(),
            'ok' => $rows->where('status', self::STATUS_OK)->count(),
            'closing_value' => (float) $rows->sum('closing_value'),
            'addition' => (float) $rows->sum('addition'),
            'consumption' => (float) $rows->sum('consumption'),

            // Closing stock quantity. This is the figure Total Value is priced
            // from — closing_value is this column times unit price — so the two
            // belong beside each other.
            'stock_as_on' => (float) $rows->sum('stock_as_on'),

            // How many different units of measure the filtered set spans. A
            // single quantity total only means anything when they all share
            // one: 5,000 PCS plus 200 KG is 5,200 of nothing. The report says
            // so on screen when this is above 1.
            'uom_count' => $rows->map(fn ($r) => $r['item']->uom)->filter()->unique()->count(),
        ];
    }

    /**
     * Rows needing purchase action, worst first — the management "Place Order"
     * list. Excludes healthy items entirely.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    public function actionList(Collection $rows): Collection
    {
        $order = array_flip(self::SEVERITY);

        return $rows->where('status', '!=', self::STATUS_OK)
            ->sortBy(fn ($row) => [$order[$row['status']], $row['item']->name])
            ->values();
    }

    /** Distinct categories, for the filter dropdown. */
    public function categories(): Collection
    {
        return StockItem::query()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, StockItem>
     */
    private function items(array $filters): Collection
    {
        return StockItem::query()
            // Narrow to specific items — lets a caller that needs one item's
            // position (the Issue form's stock warning) reuse this service
            // without computing the whole report.
            ->when($filters['item_ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', (array) $ids))
            ->when($filters['only_active'] ?? true, fn ($q) => $q->where('is_active', true))
            ->when($filters['category'] ?? null, fn ($q, $category) => $q->where('category', $category))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $like = '%'.$search.'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)
                    ->orWhere('category', 'like', $like)
                    // The merged Brand/Specification field.
                    ->orWhere('brand', 'like', $like));
            })
            ->orderBy('name')
            ->get();
    }

    /**
     * Every aggregate the report needs, in a fixed number of grouped queries
     * rather than one query per item.
     *
     * @param  list<int>  $ids
     * @return array<string, \Illuminate\Support\Collection>
     */
    private function movements(array $ids, Carbon $monthStart, Carbon $monthEnd, Carbon $prevStart, Carbon $prevEnd): array
    {
        $purchase = fn () => StockPurchase::query()->whereIn('stock_item_id', $ids)->whereNotNull('purchase_date');
        $issue = fn () => StockIssue::query()->whereIn('stock_item_id', $ids)->whereNotNull('issue_date');

        $sum = fn ($query) => $query->selectRaw('stock_item_id, SUM(qty) as agg')
            ->groupBy('stock_item_id')->pluck('agg', 'stock_item_id');
        $max = fn ($query, string $column) => $query->selectRaw("stock_item_id, MAX({$column}) as agg")
            ->groupBy('stock_item_id')->pluck('agg', 'stock_item_id');

        return [
            'purchase_before' => $sum($purchase()->whereDate('purchase_date', '<', $monthStart)),
            'purchase_in_month' => $sum($purchase()->whereBetween('purchase_date', [$monthStart->toDateString(), $monthEnd->toDateString()])),
            'issue_before' => $sum($issue()->whereDate('issue_date', '<', $monthStart)),
            'issue_in_month' => $sum($issue()->whereBetween('issue_date', [$monthStart->toDateString(), $monthEnd->toDateString()])),
            'issue_prev_month' => $sum($issue()->whereBetween('issue_date', [$prevStart->toDateString(), $prevEnd->toDateString()])),
            'last_addition' => $max($purchase()->whereDate('purchase_date', '<=', $monthEnd), 'purchase_date'),
            'last_consumption' => $max($issue()->whereDate('issue_date', '<=', $monthEnd), 'issue_date'),
            'unit_price' => $this->latestUnitPrices($ids, $monthEnd),
        ];
    }

    /**
     * Excel column R: the price on the most recent challan for the item, not an
     * average. Read as a lean ordered list and reduced in PHP — the general
     * store's purchase volume is a few thousand rows, so this is cheaper and
     * far more portable than a correlated per-item subquery.
     *
     * @param  list<int>  $ids
     * @return Collection<int, float>
     */
    private function latestUnitPrices(array $ids, Carbon $monthEnd): Collection
    {
        return StockPurchase::query()
            ->whereIn('stock_item_id', $ids)
            ->whereNotNull('purchase_date')
            ->whereNotNull('unit_price')
            ->where('unit_price', '>', 0)
            ->whereDate('purchase_date', '<=', $monthEnd)
            ->orderByDesc('purchase_date')
            ->orderByDesc('id')
            ->get(['stock_item_id', 'unit_price'])
            ->groupBy('stock_item_id')
            ->map(fn ($group) => (float) $group->first()->unit_price);
    }

    /**
     * @param  array<string, Collection>  $movements
     * @return array<string, mixed>
     */
    private function row(StockItem $item, Carbon $monthStart, array $movements): array
    {
        $config = config('stock.general_stock');
        $get = fn (string $key) => (float) ($movements[$key][$item->id] ?? 0);

        // Opening Stock is the counted figure from the Item Master and nothing
        // else. It is deliberately frozen: it says what was on the shelf when
        // the item was counted, so it reads the same in every month and no
        // purchase or issue can ever move it. Null before the month it was
        // counted in — the count had not happened yet, which is not the same
        // as counting zero, so the screen shows "—" rather than 0.
        $counted = null;
        if ($item->opening_qty !== null
            && ($item->opening_as_on === null || $monthStart->gte($item->opening_as_on->copy()->startOfMonth()))) {
            $counted = (float) $item->opening_qty;
        }

        // Balance Brought Forward is the running figure — the counted seed plus
        // everything received minus everything issued BEFORE this month, which
        // is what carries last month's closing into this month. It always
        // equals the previous month's Stock as on Date, and it is what the
        // month's arithmetic actually builds on.
        //
        // Records dated before the seed date are assumed not to exist: the seed
        // is set at go-live, when there is no earlier history.
        $balanceBf = ($counted ?? 0.0) + $get('purchase_before') - $get('issue_before');

        $addition = $get('purchase_in_month');
        $consumption = $get('issue_in_month');

        // Unchanged arithmetic — only the name of the first input changed, so
        // every Stock as on Date this report has ever produced still produces
        // the same number.
        $stockAsOn = $balanceBf + $addition - $consumption;

        // Consumption per day from LAST month, exactly as the Excel does it.
        $perDay = $get('issue_prev_month') / max(1, (int) $config['working_days_per_month']);

        // Auto values from the Excel formula; a value typed into the item
        // master wins, so a store manager can still pin an item by hand.
        $safetyAuto = $perDay * (int) $config['safety_stock_days'];
        $safety = $item->safety_stock_qty !== null ? (float) $item->safety_stock_qty : $safetyAuto;

        $leadTime = $item->lead_time_days ?? (int) $config['default_lead_time_days'];

        // Excel writes "-" when there is no consumption pattern: with nothing
        // moving there is no meaningful re-order point.
        $reorderAuto = $perDay > 0
            ? $safety + ($perDay * ($leadTime + (int) $config['order_placing_days']))
            : null;
        $reorder = $item->reorder_level !== null ? (float) $item->reorder_level : $reorderAuto;

        $unitPrice = isset($movements['unit_price'][$item->id]) ? (float) $movements['unit_price'][$item->id] : null;

        return [
            'item' => $item,
            // Counted figure from the Item Master; null before it was counted.
            'opening' => $counted,
            // Running balance carried in from last month.
            'balance_bf' => $balanceBf,
            'consumption_per_day' => $perDay,
            'safety' => $safety,
            'safety_is_manual' => $item->safety_stock_qty !== null,
            'lead_time_days' => $leadTime,
            'reorder' => $reorder,
            'reorder_is_manual' => $item->reorder_level !== null,
            'addition' => $addition,
            'last_addition_date' => $this->toDate($movements['last_addition'][$item->id] ?? null),
            'consumption' => $consumption,
            'last_consumption_date' => $this->toDate($movements['last_consumption'][$item->id] ?? null),
            'stock_as_on' => $stockAsOn,
            'unit_price' => $unitPrice,
            'closing_value' => $unitPrice !== null ? $stockAsOn * $unitPrice : null,
            'status' => $this->status($stockAsOn, $safety, $reorder),
            'remarks' => $item->remarks,
        ];
    }

    /**
     * Re-order fires before safety on purpose: crossing the re-order level is
     * the early warning (there is still lead-time cover left), while dropping
     * under safety stock is the Excel's hard "Place Order" call.
     *
     * Public and static because the Item Master screen labels its rows with the
     * same four statuses. Two screens deciding separately what "low" means is
     * how a report and a list start contradicting each other, so both call
     * this. The inputs differ by design — the report weighs a month's closing
     * balance, the Item Master the lifetime balance — but the thresholds are
     * the one rule defined here.
     */
    public static function statusFor(float $stock, ?float $safety, ?float $reorder): string
    {
        if ($stock <= 0) {
            return self::STATUS_OUT;
        }

        if ($safety !== null && $safety > 0 && $stock < $safety) {
            return self::STATUS_PLACE_ORDER;
        }

        if ($reorder !== null && $reorder > 0 && $stock < $reorder) {
            return self::STATUS_LOW;
        }

        return self::STATUS_OK;
    }

    private function status(float $stockAsOn, ?float $safety, ?float $reorder): string
    {
        return self::statusFor($stockAsOn, $safety, $reorder);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function filterByStatus(Collection $rows, string $status): Collection
    {
        // "attention" is the management shorthand for anything not Ok.
        if ($status === 'attention') {
            return $rows->where('status', '!=', self::STATUS_OK);
        }

        return $rows->where('status', $status);
    }

    private function toDate(mixed $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
