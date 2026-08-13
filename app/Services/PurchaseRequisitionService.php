<?php

namespace App\Services;

use App\Models\StockItem;
use App\Models\StockPurchase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * General Stock (module A) — supplies the read-only figures the Purchase
 * Requisition form fills in beside each item, and freezes them onto the saved
 * line.
 *
 * Deliberately thin. Stock in Hand, Safety Stock and Consumption all come from
 * GeneralStockReportService, which is the one place that knows how a month's
 * stock position is built. Re-deriving any of it here would let the requisition
 * and the Stock Report disagree about the same item on the same day, which is
 * exactly the Excel problem this module exists to end.
 *
 * The one thing computed here is Last Purchase, and only because the report
 * does not expose it as a single coherent record — see lastPurchases().
 */
class PurchaseRequisitionService
{
    public function __construct(private readonly GeneralStockReportService $report)
    {
    }

    /**
     * The auto-filled figures for one item, as at $on (defaults to today).
     *
     * @return array<string, mixed>
     */
    public function snapshot(int $stockItemId, ?Carbon $on = null): array
    {
        return $this->snapshots([$stockItemId], $on)[$stockItemId] ?? $this->emptySnapshot();
    }

    /**
     * The auto-filled figures for several items at once — one pass of the
     * report per month rather than one per item, so a 30-line requisition costs
     * the same few queries as a 1-line one.
     *
     * @param  list<int>  $stockItemIds
     * @return array<int, array<string, mixed>> keyed by stock item id
     */
    public function snapshots(array $stockItemIds, ?Carbon $on = null): array
    {
        $ids = array_values(array_unique(array_filter($stockItemIds)));

        if ($ids === []) {
            return [];
        }

        $on = ($on ?? Carbon::now())->copy();
        $thisMonth = $on->copy()->startOfMonth();
        $lastMonth = $thisMonth->copy()->subMonthNoOverflow();

        // only_active is off on purpose: an item can be deactivated after a
        // requisition names it, and reopening that requisition must still show
        // its figures rather than a blank row.
        $filters = ['item_ids' => $ids, 'only_active' => false];

        // Stock in Hand and Safety Stock as the Stock Report sees them this
        // month. "Stock as on Date" IS Stock in Hand — Balance B/F plus this
        // month's receipts minus its issues.
        $current = $this->report->rows($thisMonth, $filters)->keyBy(fn ($row) => $row['item']->id);

        // Consumption (Last Month) is last month's issued quantity. Asking the
        // report for the previous month and reading its Consumption is the
        // same number the Stock Report would print for that month, with no
        // second definition of "consumption" anywhere in the codebase.
        $previous = $this->report->rows($lastMonth, $filters)->keyBy(fn ($row) => $row['item']->id);

        $lastPurchases = $this->lastPurchases($ids, $on);

        $out = [];

        foreach ($ids as $id) {
            $row = $current->get($id);
            $prev = $previous->get($id);
            $purchase = $lastPurchases->get($id);

            $out[$id] = [
                'stock_in_hand' => $row ? (float) $row['stock_as_on'] : null,
                'safety_stock' => $row && $row['safety'] !== null ? (float) $row['safety'] : null,
                'consumption_last_month' => $prev ? (float) $prev['consumption'] : null,
                'last_purchase_qty' => $purchase ? (float) $purchase->qty : null,
                'last_purchase_rate' => $purchase && $purchase->unit_price !== null ? (float) $purchase->unit_price : null,
                'last_purchase_date' => $purchase?->purchase_date,
            ];
        }

        return $out;
    }

    /**
     * Qty − Stock in Hand, floored at zero.
     *
     * Excel column N. Floored because a negative "to be procured" is not a
     * refund: if there is already more on the shelf than was asked for, the
     * quantity to buy is nothing.
     *
     * Safe to apply per stored line, because mergeLines() has already collapsed
     * a repeated item into ONE line carrying the combined quantity — so no two
     * stored lines ever subtract the same stock figure.
     */
    public function toBeProcured(float $qtyRequested, ?float $stockInHand): float
    {
        return max(0.0, $qtyRequested - (float) ($stockInHand ?? 0));
    }

    /** Separates the departments listed in one merged User Dept./Section cell. */
    public const DEPARTMENT_SEPARATOR = '/';

    /** Separates the remarks carried over from several merged entry lines. */
    private const REMARKS_SEPARATOR = ' / ';

    /** user_dept is varchar(255); a merged list is trimmed to fit rather than throwing. */
    private const DEPARTMENT_MAX = 255;

    /**
     * Collapse the entry form's lines into ONE line per item — the shape the
     * company's requisition workbook has always used.
     *
     * Why merge at all
     * ----------------
     * The form lets a requester add a line per department, which is how people
     * think while filling it in. The document itself does not work that way: a
     * requisition asks the store to buy an ITEM, and an item has one stock
     * figure, one shortfall and one line. Keeping a line per department made
     * every one of those figures appear once per department, so the same stock
     * was offered to each of them:
     *
     *      Store       qty 12   stock 15  ->  nothing to buy
     *      Production  qty 12   stock 15  ->  nothing to buy
     *
     * when 24 were actually needed against 15 and 9 had to be bought.
     *
     * What merging produces
     * ---------------------
     * One line per item, quantities summed, and the departments gathered into
     * the single "User Dept./ Section" cell the workbook uses —
     * "Production/Sample/Cad/Cutting" in the reference file. Stock, Safety,
     * Consumption and Last Purchase are then read once for that one line,
     * because there is only one line left to read them for.
     *
     * Nothing about how those figures are FETCHED changes. This only decides
     * what counts as a line before they are applied.
     *
     * Merged in line order, so the first mention of an item fixes its position
     * and the departments read in the order they were entered.
     *
     * @param  list<array<string, mixed>>  $lines  raw validated entry lines
     * @return list<array<string, mixed>>  one entry per unique stock_item_id
     */
    public function mergeLines(array $lines): array
    {
        /** @var array<int, array<string, mixed>> $merged keyed by stock item id */
        $merged = [];

        foreach ($lines as $line) {
            $itemId = (int) $line['stock_item_id'];

            if (! isset($merged[$itemId])) {
                $merged[$itemId] = [
                    'stock_item_id' => $itemId,
                    'qty_requested' => 0.0,
                    // Collected as lists first so duplicates and blanks can be
                    // dropped once, at the end, rather than on every pass.
                    'departments' => [],
                    'remarks_parts' => [],
                    'specification' => null,
                    'type' => null,
                    'rate_appx' => null,
                ];
            }

            $merged[$itemId]['qty_requested'] += (float) $line['qty_requested'];
            $merged[$itemId]['departments'][] = trim((string) ($line['user_dept'] ?? ''));
            $merged[$itemId]['remarks_parts'][] = trim((string) ($line['remarks'] ?? ''));

            // First non-empty wins for the single-valued fields: the earliest
            // line is the one that described the item, and a later blank must
            // not wipe it out.
            foreach (['specification', 'type', 'rate_appx'] as $field) {
                $value = $line[$field] ?? null;

                if ($merged[$itemId][$field] === null && $value !== null && $value !== '') {
                    $merged[$itemId][$field] = $value;
                }
            }
        }

        return array_values(array_map(function (array $line) {
            $line['user_dept'] = $this->joinDistinct(
                $line['departments'],
                self::DEPARTMENT_SEPARATOR,
                self::DEPARTMENT_MAX
            );

            $line['remarks'] = $this->joinDistinct($line['remarks_parts'], self::REMARKS_SEPARATOR);

            unset($line['departments'], $line['remarks_parts']);

            return $line;
        }, $merged));
    }

    /**
     * Join the distinct, non-empty values of $parts, keeping first-seen order.
     *
     * Distinct because two lines for the same department ("Store" twice) should
     * read "Store", not "Store/Store". Returns null rather than an empty string
     * so the column stays NULL when nothing was entered.
     *
     * Public so the monthly report can gather departments the same way when it
     * merges an item across several requisitions — one definition of what a
     * combined cell looks like, not two that could drift apart.
     *
     * @param  list<string>  $parts
     */
    public function joinDistinct(array $parts, string $separator, ?int $maxLength = null): ?string
    {
        $kept = array_values(array_unique(array_filter($parts, fn ($part) => $part !== '')));

        if ($kept === []) {
            return null;
        }

        $joined = implode($separator, $kept);

        // Trimmed rather than allowed to overflow the column: a requisition
        // naming more departments than the cell holds must still save.
        if ($maxLength !== null && mb_strlen($joined) > $maxLength) {
            $joined = rtrim(mb_substr($joined, 0, $maxLength - 1), $separator.' ').'…';
        }

        return $joined;
    }

    /**
     * The most recent purchase record for each item — the one row, so Qty, Rate
     * and Date on the printed requisition always describe the SAME challan.
     *
     * Not taken from GeneralStockReportService: that report sources its
     * unit_price from the latest challan carrying a price and its last-addition
     * date from the latest challan of any kind, which is right for a stock
     * valuation but would let this document print a quantity from one delivery
     * beside a rate from another.
     *
     * Read as a lean ordered list and reduced in PHP, matching how the report
     * resolves its prices — the general store's purchase volume is a few
     * thousand rows, so this is cheaper and far more portable than a correlated
     * per-item subquery.
     *
     * @param  list<int>  $ids
     * @return Collection<int, StockPurchase>
     */
    private function lastPurchases(array $ids, Carbon $on): Collection
    {
        return StockPurchase::query()
            ->whereIn('stock_item_id', $ids)
            ->whereNotNull('purchase_date')
            ->whereDate('purchase_date', '<=', $on->toDateString())
            ->orderByDesc('purchase_date')
            ->orderByDesc('id')
            ->get(['id', 'stock_item_id', 'qty', 'unit_price', 'purchase_date'])
            ->groupBy('stock_item_id')
            ->map(fn ($group) => $group->first());
    }

    /** Items offered on the requisition form — same shape the Issue form uses. */
    public function selectableItems(): Collection
    {
        return StockItem::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'brand', 'uom', 'item_category_id']);
    }

    /** @return array<string, null> */
    private function emptySnapshot(): array
    {
        return [
            'stock_in_hand' => null,
            'safety_stock' => null,
            'consumption_last_month' => null,
            'last_purchase_qty' => null,
            'last_purchase_rate' => null,
            'last_purchase_date' => null,
        ];
    }
}
