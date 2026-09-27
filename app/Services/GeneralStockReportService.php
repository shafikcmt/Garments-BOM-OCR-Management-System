<?php

namespace App\Services;

use App\Models\GeneralStockSupplier;
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
     * The period being viewed ENDS BEFORE this item was counted, so there is no
     * known position for it — which is not the same as a position of zero.
     *
     * Without this the report answered a pre-count month by treating the missing
     * seed as 0 and then calling the item Out of Stock: a July 2026 report
     * flagged 1,138 items for purchase and put the store's value at 0.00. That
     * is a false buying signal off a figure nobody ever counted.
     *
     * It is NOT a severity. It is the absence of an answer, so it is excluded
     * from the totals, from the Place Order list and from "Needs Attention".
     */
    public const STATUS_NOT_COUNTED = 'not_counted';

    /**
     * Statuses in severity order, worst first. Used for sorting and for the
     * "at least this bad" filter on the screen.
     *
     * NOT_COUNTED sorts last, below Ok: a row with no figures cannot be more
     * urgent than one that has them.
     *
     * @var list<string>
     */
    public const SEVERITY = [
        self::STATUS_OUT,
        self::STATUS_PLACE_ORDER,
        self::STATUS_LOW,
        self::STATUS_OK,
        self::STATUS_NOT_COUNTED,
    ];

    /** @return array<string, string> status => label */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_OUT => 'Out of Stock',
            self::STATUS_PLACE_ORDER => 'Place Order',
            self::STATUS_LOW => 'Low Stock',
            self::STATUS_OK => 'Ok',
            self::STATUS_NOT_COUNTED => 'Not Counted Yet',
        ];
    }

    /**
     * Normalise a "YYYY-MM" input into the first day of that month, falling
     * back to the current month for anything unparseable.
     */
    public function resolveMonth(?string $month): Carbon
    {
        try {
            return $month ? Carbon::createFromFormat('!Y-m', $month)->startOfMonth() : now()->startOfMonth();
        } catch (\Throwable $e) {
            return now()->startOfMonth();
        }
    }

    /**
     * The window the report's arithmetic covers.
     *
     * A month by default — every figure this report has ever produced comes off
     * that path, unchanged. `from` / `to` move the window to an arbitrary
     * range: Balance B/F becomes everything before `from`, Addition and
     * Consumption become what moved between the two dates, and Stock as on Date
     * becomes the balance at `to`.
     *
     * A HALF-FILLED RANGE COMPLETES ITSELF FROM THE MONTH OF THE DATE THAT WAS
     * TYPED, not from the Month picker. Anchoring the missing end to the picker
     * can produce a backwards range (From in September, Month set to August),
     * and a filter that can be driven into an impossible state by two controls
     * that look unrelated is a support call.
     *
     * @return array{start: Carbon, end: Carbon, is_range: bool}
     */
    public function resolvePeriod(Carbon $monthStart, ?string $from = null, ?string $to = null): array
    {
        $monthStart = $monthStart->copy()->startOfMonth();

        $fromDate = $this->toDate($from);
        $toDate = $this->toDate($to);

        if (! $fromDate && ! $toDate) {
            return [
                'start' => $monthStart,
                'end' => $monthStart->copy()->endOfMonth(),
                'is_range' => false,
            ];
        }

        $start = $fromDate ? $fromDate->copy()->startOfDay() : $toDate->copy()->startOfMonth();
        $end = $toDate ? $toDate->copy()->endOfDay() : $fromDate->copy()->endOfMonth();

        return ['start' => $start, 'end' => $end, 'is_range' => true];
    }

    /**
     * One report row per stock item for the given period.
     *
     * @param  array{search?: string|null, category?: string|null, status?: string|null, only_active?: bool, item_ids?: list<int>|int|null, supplier_id?: int|null, from?: string|null, to?: string|null}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(Carbon $monthStart, array $filters = []): Collection
    {
        $period = $this->resolvePeriod($monthStart, $filters['from'] ?? null, $filters['to'] ?? null);

        $periodStart = $period['start'];
        $periodEnd = $period['end'];

        // THE RATE COLUMNS STAY ON CALENDAR MONTHS, whatever the period is.
        //
        // Column I is the Excel's "Last Month Consumption pattern" —
        // SUMIFS(previous month's issues) / 26 working days — and it feeds
        // Safety Stock (I x 7), Re-order Level and therefore Status. Those
        // thresholds are shared with the Item Master screen and the dashboard
        // through statusFor().
        //
        // Divide a five-day window's consumption by 26 and the rate collapses,
        // safety stock collapses with it, and this report starts calling an
        // item Ok while the Item Master calls it Place Order — the same item,
        // two answers. So the pattern is read from the calendar month BEFORE
        // the month the period ends in. With no range that is the month before
        // the picked one, which is exactly what it has always been.
        $rateMonth = $periodEnd->copy()->startOfMonth();
        $prevStart = $rateMonth->copy()->subMonthNoOverflow()->startOfMonth();
        $prevEnd = $prevStart->copy()->endOfMonth();

        $items = $this->items($filters, $periodEnd);

        if ($items->isEmpty()) {
            return collect();
        }

        $ids = $items->pluck('id')->all();
        $movements = $this->movements($ids, $periodStart, $periodEnd, $prevStart, $prevEnd);

        $rows = $items->map(fn (StockItem $item) => $this->row($item, $periodStart, $movements));

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
        // EVERY TOTAL IS TAKEN OVER THE COUNTED ROWS ONLY. A row whose count had
        // not happened by this period contributes no known quantity or value, so
        // adding its computed zero in would state a store-wide figure partly
        // built from items nobody had measured.
        $counted = $rows->where('not_counted', false);

        return [
            'items' => $rows->count(),
            // How many of those rows actually carry figures, so the screen and
            // the printed sheet can say what the totals are drawn from.
            'counted_items' => $counted->count(),
            'not_counted' => $rows->count() - $counted->count(),
            'out' => $counted->where('status', self::STATUS_OUT)->count(),
            'place_order' => $counted->where('status', self::STATUS_PLACE_ORDER)->count(),
            'low' => $counted->where('status', self::STATUS_LOW)->count(),
            'ok' => $counted->where('status', self::STATUS_OK)->count(),
            'closing_value' => (float) $counted->sum('closing_value'),
            'addition' => (float) $counted->sum('addition'),
            'consumption' => (float) $counted->sum('consumption'),

            // Closing stock quantity. This is the figure Total Value is priced
            // from — closing_value is this column times unit price — so the two
            // belong beside each other.
            'stock_as_on' => (float) $counted->sum('stock_as_on'),

            // How many different units of measure the filtered set spans. A
            // single quantity total only means anything when they all share
            // one: 5,000 PCS plus 200 KG is 5,200 of nothing. The report says
            // so on screen when this is above 1. Counted rows only, for the
            // same reason as the totals it qualifies.
            'uom_count' => $counted->map(fn ($r) => $r['item']->uom)->filter()->unique()->count(),
        ];
    }

    /**
     * Rows needing purchase action, worst first — the management "Place Order"
     * list. Excludes healthy items entirely, and items not yet counted: an
     * item with no known position is not evidence that anything needs buying,
     * and putting 1,138 of them on a purchase list buries the ones that do.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    public function actionList(Collection $rows): Collection
    {
        $order = array_flip(self::SEVERITY);

        return $rows->whereNotIn('status', [self::STATUS_OK, self::STATUS_NOT_COUNTED])
            ->sortBy(fn ($row) => [$order[$row['status']], $row['item']->name])
            ->values();
    }

    /**
     * Active General Stock suppliers, for the filter dropdown.
     *
     * GeneralStockSupplier, deliberately not the Buyer/Style `Supplier` model —
     * they are separate lists for separate relationships and share no table.
     * An inactive supplier is left out: the list is for narrowing a live report,
     * not for browsing history. A report already filtered to one that has since
     * been deactivated still resolves, because the filter is an id.
     *
     * @return Collection<int, GeneralStockSupplier>
     */
    public function suppliers(): Collection
    {
        return GeneralStockSupplier::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * The name behind a supplier filter, for the screen note and the export
     * headings. Not read out of suppliers() — that lists active suppliers only,
     * and a report filtered to one since deactivated is still filtered by it.
     */
    public function supplierName(int|string|null $supplierId): ?string
    {
        if (! $supplierId) {
            return null;
        }

        return GeneralStockSupplier::withTrashed()->find((int) $supplierId)?->name;
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
    private function items(array $filters, ?Carbon $periodEnd = null): Collection
    {
        return StockItem::query()
            // Narrow to specific items — lets a caller that needs one item's
            // position (the Issue form's stock warning) reuse this service
            // without computing the whole report.
            ->when($filters['item_ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', (array) $ids))
            ->when($filters['only_active'] ?? true, fn ($q) => $q->where('is_active', true))
            // SUPPLIER NARROWS WHICH ITEMS ARE LISTED. It does not narrow the
            // figures on them, and it must not: stock_issues carries no
            // supplier, so consumption cannot be attributed to a vendor. Filter
            // Addition to one supplier while Balance B/F and Consumption still
            // count everyone's and the row stops tying — Opening + Addition −
            // Consumption would no longer equal Stock as on Date, and Closing
            // Stock Value would be priced off a balance that never existed.
            //
            // So the question this answers is "what is my stock position on the
            // things I buy from this vendor", with every figure still the true
            // whole-store one. A supplier-wise breakdown of receipts is a
            // different report, and the Receiving Report already is it.
            //
            // Bounded at the period end for the same reason last_addition and
            // the unit price are: a receipt entered after the period being
            // viewed is not part of that period's history.
            ->when($filters['supplier_id'] ?? null, fn ($q, $supplierId) => $q->whereIn(
                'id',
                StockPurchase::query()
                    ->select('stock_item_id')
                    ->where('general_stock_supplier_id', $supplierId)
                    ->whereNotNull('purchase_date')
                    ->when($periodEnd, fn ($p, $end) => $p->whereDate('purchase_date', '<=', $end))
            ))
            ->when($filters['category'] ?? null, fn ($q, $category) => $q->where('category', $category))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $like = '%'.$search.'%';
                // whereLike, not where(..., 'like', ...): PostgreSQL's LIKE is
                // case-sensitive, so "num" would not find "Numbering Machine"
                // and the report answered "Nothing to show" on the live server
                // for a term that worked in development. The framework's
                // whereLike compiles to ILIKE on Postgres and stays LIKE on
                // MySQL/MariaDB and SQLite, where LIKE is already
                // case-insensitive. Same treatment as the receiving, issue and
                // requisition searches.
                $q->where(fn ($w) => $w->whereLike('name', $like)
                    ->orWhereLike('category', $like)
                    // The merged Brand/Specification field.
                    ->orWhereLike('brand', $like));
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
    private function movements(array $ids, Carbon $periodStart, Carbon $periodEnd, Carbon $prevStart, Carbon $prevEnd): array
    {
        // Local aliases so the query bodies below read unchanged. The bounds
        // are a month when no range is set, which is every call this had before.
        $monthStart = $periodStart;
        $monthEnd = $periodEnd;

        $purchase = fn () => StockPurchase::query()->whereIn('stock_item_id', $ids)->whereNotNull('purchase_date');
        $issue = fn () => StockIssue::query()->whereIn('stock_item_id', $ids)->whereNotNull('issue_date');

        $sum = fn ($query) => $query->selectRaw('stock_item_id, SUM(qty) as agg')
            ->groupBy('stock_item_id')->pluck('agg', 'stock_item_id');
        $max = fn ($query, string $column) => $query->selectRaw("stock_item_id, MAX({$column}) as agg")
            ->groupBy('stock_item_id')->pluck('agg', 'stock_item_id');

        /**
         * An INCLUSIVE window on both ends, expressed as two whereDate calls
         * rather than one whereBetween.
         *
         * The three engines do not agree here. MySQL and PostgreSQL store these
         * as real DATE columns, so `col BETWEEN '2026-08-01' AND '2026-08-31'`
         * includes the 31st and always has — no figure this report has produced
         * on either engine moves. SQLite, which only the test suite runs on,
         * keeps the value as the text "2026-08-31 00:00:00", and that string
         * compares GREATER than the bare bound "2026-08-31", so the last day of
         * every window silently dropped out.
         *
         * whereDate wraps the column in each dialect's own date cast, so all
         * three now answer the same question. This matters far more with a
         * custom range than it did with a month: "5 Aug to 9 Aug" that quietly
         * means "5 Aug to 8 Aug" is a report nobody can reconcile.
         */
        $between = fn ($query, string $column, Carbon $start, Carbon $end) => $query
            ->whereDate($column, '>=', $start)
            ->whereDate($column, '<=', $end);

        return [
            'purchase_before' => $sum($purchase()->whereDate('purchase_date', '<', $monthStart)),
            'purchase_in_month' => $sum($between($purchase(), 'purchase_date', $monthStart, $monthEnd)),
            'issue_before' => $sum($issue()->whereDate('issue_date', '<', $monthStart)),
            'issue_in_month' => $sum($between($issue(), 'issue_date', $monthStart, $monthEnd)),
            'issue_prev_month' => $sum($between($issue(), 'issue_date', $prevStart, $prevEnd)),
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

        // The period predates the count. Every figure below still computes —
        // consumers like the dashboard read stock_as_on unconditionally — but
        // the row is marked so the screen and the exports print "—" instead of
        // a zero nobody measured, and so summary() leaves it out of the totals.
        //
        // Keyed on the count DATE, not on a null seed: an item with no
        // opening_qty at all was never seeded, and its movements are real.
        $notCounted = $item->opening_as_on !== null
            && $monthStart->lt($item->opening_as_on->copy()->startOfMonth());

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

        // Excel column R: the price on the most recent challan. That stays the
        // answer whenever there is one, so no figure this report has ever
        // produced changes.
        //
        // The item master's standard price is the FALLBACK, for the item that
        // has never been received with a price on the challan. That is not a
        // rare case here: of 1,240 items, five have such a price, so the Value
        // column was empty for almost every row and the report's own total
        // described a fraction of the store. A standard price is a worse
        // number than a real one and a far better number than none.
        $challanPrice = isset($movements['unit_price'][$item->id])
            ? (float) $movements['unit_price'][$item->id]
            : null;

        $masterPrice = $item->unit_price !== null ? (float) $item->unit_price : null;

        $unitPrice = $challanPrice ?? $masterPrice;

        // Which of the two this is, so the screen can say so rather than
        // presenting a standard price as though something had been bought at
        // it. Only meaningful when there is a price at all.
        $priceIsStandard = $challanPrice === null && $masterPrice !== null;

        // The two dates are DERIVED — the most recent purchase and the most
        // recent issue — so an item carried over from the old Excel sheets,
        // with no transaction recorded here yet, shows nothing in either
        // column however much history the store actually has for it.
        //
        // The reference dates are the FALLBACK for exactly that item, and only
        // for it: a real movement always wins, so no imported figure can ever
        // hide or contradict one. This is the same shape as the price two lines
        // above, for the same reason — see the migration that adds the columns
        // for why the derived value cannot simply be written to.
        $derivedAddition = $this->toDate($movements['last_addition'][$item->id] ?? null);
        $derivedConsumption = $this->toDate($movements['last_consumption'][$item->id] ?? null);

        $lastAddition = $derivedAddition ?? $this->toDate($item->last_addition_reference);
        $lastConsumption = $derivedConsumption ?? $this->toDate($item->last_consumption_reference);

        // Which of the two this is, so the screen can mark a reference date
        // rather than presenting it as evidence that something moved.
        $lastAdditionIsReference = $derivedAddition === null && $lastAddition !== null;
        $lastConsumptionIsReference = $derivedConsumption === null && $lastConsumption !== null;

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
            'last_addition_date' => $lastAddition,
            'last_addition_is_reference' => $lastAdditionIsReference,
            'consumption' => $consumption,
            'last_consumption_date' => $lastConsumption,
            'last_consumption_is_reference' => $lastConsumptionIsReference,
            'stock_as_on' => $stockAsOn,
            'unit_price' => $unitPrice,
            'unit_price_is_standard' => $priceIsStandard,
            'closing_value' => $unitPrice !== null ? $stockAsOn * $unitPrice : null,
            // Not counted yet wins over any computed severity: calling an item
            // Out of Stock because its count had not happened is the defect.
            'status' => $notCounted
                ? self::STATUS_NOT_COUNTED
                : $this->status($stockAsOn, $safety, $reorder),
            'not_counted' => $notCounted,
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
        // "attention" is the management shorthand for anything not Ok — but not
        // for a row with no figures on it, which is nothing to attend to. Same
        // set actionList() and the meta's "attention" count use.
        if ($status === 'attention') {
            return $rows->whereNotIn('status', [self::STATUS_OK, self::STATUS_NOT_COUNTED]);
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
