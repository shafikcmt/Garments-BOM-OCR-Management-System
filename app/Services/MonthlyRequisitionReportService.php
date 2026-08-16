<?php

namespace App\Services;

use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * General Stock (module A) — the monthly Purchase Requisition report: every
 * requisition raised in a month compiled into one list, plus the same list
 * split by item category.
 *
 * Replaces the hand-compiled "Month_Of_<Month>.xlsx" workbook, whose "ALL"
 * sheet held the month's items and whose remaining sheets held the same items
 * grouped by category.
 *
 * Reads the SNAPSHOTS stored on each requisition item rather than asking
 * GeneralStockReportService to recompute a month's worth of stock positions.
 * Two reasons, and both matter:
 *
 *   - Correctness. A requisition records the stock position that justified it
 *     on the day it was raised. Recomputing in December would redraw an August
 *     report with December's stock and the approvals would no longer describe
 *     anything that happened.
 *   - Cost. A month can carry hundreds of item lines; re-deriving each one's
 *     position from the purchase and issue history is work already done and
 *     already stored.
 */
class MonthlyRequisitionReportService
{
    /** Separates the Requisition Nos a merged monthly row was built from. */
    private const SOURCE_SEPARATOR = ', ';

    /**
     * Statuses that count as a real request.
     *
     * Draft is excluded on purpose: it is something someone started typing, not
     * something they asked for, and counting it would inflate the month.
     */
    public const COUNTED_STATUSES = [
        PurchaseRequisition::STATUS_SUBMITTED,
        PurchaseRequisition::STATUS_STORE_REVIEWED,
        PurchaseRequisition::STATUS_APPROVED,
        PurchaseRequisition::STATUS_PURCHASE_ACTION_TAKEN,
    ];

    public function __construct(private readonly PurchaseRequisitionService $requisitions)
    {
    }

    /**
     * Normalise a "YYYY-MM" input into the first day of that month, falling
     * back to the current month for anything unparseable. Mirrors
     * GeneralStockReportService::resolveMonth so both reports read a month the
     * same way.
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
     * One row per requisition item line raised in the month.
     *
     * @param  array{category?: int|string|null, search?: string|null}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(Carbon $monthStart, array $filters = [], bool $merge = false): Collection
    {
        $lines = $this->lines($monthStart, $filters);

        $rows = $lines->map(fn (PurchaseRequisitionItem $line) => $this->row($line));

        if ($merge) {
            $rows = $this->mergeByItem($rows);
        }

        return $rows
            ->sortBy([
                fn ($a, $b) => strcasecmp($a['category'] ?? '', $b['category'] ?? ''),
                fn ($a, $b) => strcasecmp($a['item_name'] ?? '', $b['item_name'] ?? ''),
            ])
            ->values();
    }

    /**
     * The lines of ONE requisition, in the same row shape the monthly report
     * uses, so a single requisition can be printed with the same table.
     *
     * Document order is kept (sort_order, then id) rather than the monthly
     * report's category/name sort: this prints the requisition as it was
     * written, matching the view screen line for line.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function requisitionRows(PurchaseRequisition $requisition): Collection
    {
        $requisition->loadMissing([
            'items.stockItem', 'items.itemCategory',
            'indentSection', 'indentPerson',
        ]);

        return $requisition->items
            ->map(function (PurchaseRequisitionItem $line) use ($requisition) {
                // The relation is already loaded the other way round; setting it
                // here stops row() re-querying the parent for every line.
                $line->setRelation('requisition', $requisition);

                return $this->row($line);
            })
            ->values();
    }

    /**
     * The same rows split by category, keyed by category name and ordered
     * alphabetically.
     *
     * Only categories that actually carry a line appear — a month that used
     * five of the thirteen masters produces five groups, not thirteen with
     * eight empty ones.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    public function byCategory(Collection $rows): Collection
    {
        return $rows
            ->groupBy(fn ($row) => $row['category'] ?: 'Uncategorised')
            ->sortKeys();
    }

    /**
     * Headline figures for the month, and the same per category.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function summary(Collection $rows): array
    {
        $perCategory = $this->byCategory($rows)
            ->map(fn (Collection $group) => [
                'lines' => $group->count(),
                'items' => $group->pluck('stock_item_id')->unique()->count(),
                'qty_requested' => (float) $group->sum('qty_requested'),
                'to_be_procured' => (float) $group->sum('to_be_procured'),
                'amount' => (float) $group->sum('amount'),
            ]);

        return [
            'requisitions' => $rows->pluck('requisition_ids')->flatten()->unique()->count(),
            'lines' => $rows->count(),
            'items' => $rows->pluck('stock_item_id')->unique()->count(),
            'qty_requested' => (float) $rows->sum('qty_requested'),
            'to_be_procured' => (float) $rows->sum('to_be_procured'),
            'amount' => (float) $rows->sum('amount'),
            'per_category' => $perCategory,
        ];
    }

    /** Categories present in the month, for the filter dropdown. */
    public function categoriesInMonth(Carbon $monthStart): Collection
    {
        return $this->lines($monthStart)
            ->map(fn (PurchaseRequisitionItem $line) => optional($line->itemCategory)->name)
            ->filter()
            ->unique()
            ->sort()
            ->values();
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The month's item lines, already eager-loaded.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, PurchaseRequisitionItem>
     */
    private function lines(Carbon $monthStart, array $filters = []): Collection
    {
        return PurchaseRequisitionItem::query()
            ->with(['stockItem', 'itemCategory', 'requisition.indentSection', 'requisition.indentPerson'])
            ->whereHas('requisition', function ($q) use ($monthStart) {
                $q->whereYear('requisition_date', $monthStart->year)
                    ->whereMonth('requisition_date', $monthStart->month)
                    ->whereIn('status', self::COUNTED_STATUSES);
            })
            ->when($filters['category'] ?? null, fn ($q, $category) => $q->whereHas(
                'itemCategory',
                fn ($c) => $c->where('name', $category)
            ))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $like = '%'.$search.'%';
                // whereLike compiles to ILIKE on PostgreSQL, where a plain LIKE
                // is case-sensitive and quietly returns less than it should. A
                // miss here reads as a requisition that never happened.
                $q->where(fn ($w) => $w->whereLike('user_dept', $like)
                    ->orWhereLike('specification', $like)
                    ->orWhereHas('stockItem', fn ($i) => $i->whereLike('name', $like))
                    ->orWhereHas('requisition', fn ($r) => $r->whereLike('requisition_no', $like)));
            })
            ->get();
    }

    /**
     * One stored line, flattened into the shape both the screen and the export
     * read. Every figure is the snapshot frozen when the requisition was
     * raised — nothing here is recomputed.
     *
     * @return array<string, mixed>
     */
    private function row(PurchaseRequisitionItem $line): array
    {
        $requisition = $line->requisition;

        return [
            'stock_item_id' => $line->stock_item_id,
            'item_name' => optional($line->stockItem)->name ?? '—',
            'uom' => $line->uom_snapshot,
            'category' => optional($line->itemCategory)->name,
            'specification' => $line->specification,
            'type' => $line->type,
            'user_dept' => $line->user_dept,
            'qty_requested' => (float) $line->qty_requested,
            'stock_in_hand' => $line->stock_in_hand_snapshot === null ? null : (float) $line->stock_in_hand_snapshot,
            'safety_stock' => $line->safety_stock_snapshot === null ? null : (float) $line->safety_stock_snapshot,
            'consumption_last_month' => $line->consumption_last_month_snapshot === null ? null : (float) $line->consumption_last_month_snapshot,
            'last_purchase_qty' => $line->last_purchase_qty_snapshot === null ? null : (float) $line->last_purchase_qty_snapshot,
            'last_purchase_rate' => $line->last_purchase_rate_snapshot === null ? null : (float) $line->last_purchase_rate_snapshot,
            'last_purchase_date' => $line->last_purchase_date_snapshot,
            'to_be_procured' => (float) $line->to_be_procured_qty,
            'rate_appx' => $line->rate_appx === null ? null : (float) $line->rate_appx,
            'amount' => (float) $line->amount,
            'store_pending_qty' => $line->store_pending_qty,
            'accounts_pending_qty' => $line->accounts_pending_qty,
            'remarks' => $line->remarks,

            // Where the line came from. Kept as lists so a merged row can carry
            // several without a second shape.
            'requisition_no' => $requisition?->requisition_no,
            'requisition_nos' => array_filter([$requisition?->requisition_no]),
            'requisition_ids' => array_filter([$requisition?->id]),
            'requisition_date' => $requisition?->requisition_date,
            'section' => optional($requisition?->indentSection)->name,
        ];
    }

    /**
     * Break every value in $values on $separator and return the flat list of
     * trimmed, non-empty parts.
     *
     * Needed because a stored cell can itself be a merged list; see the note in
     * mergeByItem().
     *
     * @param  Collection<int, string|null>  $values
     * @return list<string>
     */
    private function explodeAll(Collection $values, string $separator): array
    {
        return $values
            ->flatMap(fn ($value) => explode($separator, (string) $value))
            ->map(fn ($part) => trim($part))
            ->filter(fn ($part) => $part !== '')
            ->values()
            ->all();
    }

    /**
     * Collapse the month's rows to one per item.
     *
     * The same reasoning as merging inside a single requisition, one level up:
     * an item has one stock figure for the month, so listing it once per
     * requisition would show that figure several times and overstate what is
     * left to buy. Quantities are summed, departments and source Requisition
     * Nos gathered into single cells.
     *
     * The stock, safety, consumption and last-purchase figures are taken from
     * the LATEST requisition to name the item — the most recent reading of a
     * position that moved during the month, and the one the buyer should act
     * on.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function mergeByItem(Collection $rows): Collection
    {
        return $rows
            ->groupBy('stock_item_id')
            ->map(function (Collection $group) {
                // Oldest first, so "first non-empty" means the earliest
                // description and the newest snapshot is easy to reach.
                $ordered = $group->sortBy([
                    fn ($a, $b) => ($a['requisition_date']?->timestamp ?? 0) <=> ($b['requisition_date']?->timestamp ?? 0),
                    fn ($a, $b) => ($a['requisition_ids'][0] ?? 0) <=> ($b['requisition_ids'][0] ?? 0),
                ])->values();

                $latest = $ordered->last();
                $qty = (float) $ordered->sum('qty_requested');
                $stock = $latest['stock_in_hand'];

                // Recomputed from the combined quantity against ONE stock
                // figure — not the sum of the per-requisition shortfalls, which
                // would each have subtracted that same stock again.
                $toBeProcured = $this->requisitions->toBeProcured($qty, $stock);

                $rate = $ordered->pluck('rate_appx')->first(fn ($value) => $value !== null);

                return array_merge($latest, [
                    'qty_requested' => $qty,
                    'to_be_procured' => $toBeProcured,
                    'rate_appx' => $rate,
                    'amount' => $rate !== null ? $toBeProcured * $rate : 0.0,

                    'specification' => $ordered->pluck('specification')->first(fn ($v) => $v !== null && $v !== ''),
                    'type' => $ordered->pluck('type')->first(fn ($v) => $v !== null && $v !== ''),

                    // Split before joining. Each stored value may ALREADY be a
                    // merged cell ("Store/Production", written when one
                    // requisition served two departments), so treating it as a
                    // single name would produce "Store/Production/Store" the
                    // moment another requisition mentions Store again. Breaking
                    // every cell back into its parts is what makes the
                    // department list distinct.
                    'user_dept' => $this->requisitions->joinDistinct(
                        $this->explodeAll($ordered->pluck('user_dept'), PurchaseRequisitionService::DEPARTMENT_SEPARATOR),
                        PurchaseRequisitionService::DEPARTMENT_SEPARATOR
                    ),
                    'remarks' => $this->requisitions->joinDistinct(
                        $this->explodeAll($ordered->pluck('remarks'), '/'),
                        ' / '
                    ),

                    'requisition_no' => $this->requisitions->joinDistinct(
                        $ordered->pluck('requisition_no')->map(fn ($v) => trim((string) $v))->all(),
                        self::SOURCE_SEPARATOR
                    ),
                    'requisition_nos' => $ordered->pluck('requisition_nos')->flatten()->unique()->values()->all(),
                    'requisition_ids' => $ordered->pluck('requisition_ids')->flatten()->unique()->values()->all(),

                    'store_pending_qty' => $ordered->sum('store_pending_qty') ?: null,
                    'accounts_pending_qty' => $ordered->sum('accounts_pending_qty') ?: null,
                ]);
            })
            ->values();
    }
}
