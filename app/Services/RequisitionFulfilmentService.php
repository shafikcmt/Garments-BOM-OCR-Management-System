<?php

namespace App\Services;

use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Requested vs Purchased vs Pending, per item.
 *
 * Answers the one question the two existing screens each answer half of: the
 * Purchase Requisition list says what was ASKED for, the Receiving report says
 * what ARRIVED, and nobody could see the gap between them without reading both
 * and subtracting by hand.
 *
 * Read-only. It writes nothing and owns no query of its own for receipts —
 * Purchased comes from ReceivingReportService, the same source the Receiving
 * report and the combined Store workbook read, so a figure here can never
 * disagree with the report it is quoted against.
 *
 * Scope follows PurchaseRequisition::visibleTo(): the Store team, Admin and
 * Management get the whole company, and a user granted the requisition
 * permissions from another department gets their own requisitions only. The
 * RECEIVED side is deliberately not scoped that way — see purchasedByItem().
 */
class RequisitionFulfilmentService
{
    public function __construct(
        private readonly ReceivingReportService $receiving,
        private readonly PurchaseRequisitionService $requisitions,
    ) {
    }

    /**
     * One row per item: what was requested, what has been received, what is
     * still outstanding.
     *
     * $companyWide: every requisition, whoever is asking — for the Stock
     * Summary card, whose access (store.requirement_issue.view) already means
     * "sees the whole company". Off by default: every other caller keeps the
     * per-user visibility it has always had.
     *
     * @param  array{month?: string|null, from?: string|null, to?: string|null}  $filters
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, float|int>, scoped: bool}
     */
    public function summary(?User $user, array $filters = [], bool $companyWide = false): array
    {
        $requested = $this->requestedByItem($user, $filters, $companyWide);
        $purchased = $this->purchasedByItem($filters);
        $onHand = $this->stockOnHand($requested->pluck('item_id')->all());

        $rows = $requested
            ->map(function (array $line) use ($purchased, $onHand) {
                // A line with no master item was never received against one
                // either, so it stays at zero rather than picking up whatever
                // happens to sit under a null key.
                $received = $line['in_master']
                    ? (float) ($purchased[$line['item_id']] ?? 0)
                    : 0.0;

                return $line + [
                    // What is on the shelf right now, from the same source the
                    // Stock Report prints. Null for a line naming an item that
                    // is not in the Item Master — there is nothing to count.
                    'stock_in_hand' => $line['in_master'] ? ($onHand[$line['item_id']] ?? null) : null,
                    'purchased' => $received,
                    // Floored at zero: a store that received more than was asked
                    // for has nothing outstanding, and a negative "pending"
                    // reads as a shortfall to anyone skimming the column.
                    'pending' => max(0.0, $line['requested'] - $received),
                    // Kept so the row can say so rather than hiding it.
                    'over_received' => max(0.0, $received - $line['requested']),
                ];
            })
            ->sortByDesc('pending')
            ->values();

        return [
            'rows' => $rows,
            'totals' => [
                'items' => $rows->count(),
                'requested' => (float) $rows->sum('requested'),
                'purchased' => (float) $rows->sum('purchased'),
                'pending' => (float) $rows->sum('pending'),
                'over_received' => (float) $rows->sum('over_received'),
                'stock_in_hand' => (float) $rows->sum('stock_in_hand'),
                // How many of these the Store has still to add to the Item
                // Master, called out so the widget can say it in one line.
                'not_in_master' => $rows->where('in_master', false)->count(),
            ],
            // Whether the figures describe this user's own requisitions or the
            // whole company, so the widget can say which it is showing.
            'scoped' => ! $companyWide && $user !== null && ! PurchaseRequisition::seesEveryRequisition($user),
        ];
    }

    /**
     * Qty requested per item, within this user's visibility.
     *
     * Only sheets in PurchaseRequisition::countedInTotals() count: no drafts,
     * and no department sheet still with its head or returned by one — the
     * store has not been told about that demand yet. The Monthly Requisition
     * report reads the same scope. A department line a final sheet of the
     * same month repeats counts once (PurchaseRequisitionItem::
     * notRepeatedOnFinalSheet()).
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function requestedByItem(?User $user, array $filters, bool $companyWide = false): Collection
    {
        $query = PurchaseRequisitionItem::query()
            ->join('purchase_requisitions', 'purchase_requisitions.id', '=', 'purchase_requisition_items.purchase_requisition_id')
            // LEFT join, so a line naming an item that is not in the Item
            // Master is not silently dropped. It is still demand somebody has
            // to answer — and it is the demand most at risk of being forgotten,
            // because nothing in the master will ever remind anyone of it.
            ->leftJoin('stock_items', 'stock_items.id', '=', 'purchase_requisition_items.stock_item_id')
            ->whereIn('purchase_requisitions.id', PurchaseRequisition::query()
                ->countedInTotals()
                ->unless($companyWide, fn ($q) => $q->visibleTo($user))
                ->select('id'))
            // Only for a viewer who also sees the final sheets: a department
            // user never sees the Store's consolidated sheet, so hiding their
            // line would make their own request vanish from their summary.
            ->when($companyWide || ($user && PurchaseRequisition::seesEveryRequisition($user)),
                fn ($q) => $q->notRepeatedOnFinalSheet());

        $this->applyPeriod($query, $filters, 'purchase_requisitions.requisition_date');

        return $query
            // Master lines group by item id; lines with no master item group by
            // the name that was typed, so two departments asking for the same
            // unlisted item still make one row.
            ->groupBy(
                'purchase_requisition_items.stock_item_id',
                'purchase_requisition_items.custom_item_name',
                'stock_items.name',
                'stock_items.sku',
                'stock_items.uom'
            )
            ->selectRaw('purchase_requisition_items.stock_item_id as item_id')
            ->selectRaw('purchase_requisition_items.custom_item_name as custom_item_name')
            ->selectRaw('stock_items.name as item_name, stock_items.sku as sku, stock_items.uom as uom')
            ->selectRaw('SUM(purchase_requisition_items.qty_requested) as requested_qty')
            ->get()
            ->map(fn ($row) => [
                'item_id' => $row->item_id === null ? null : (int) $row->item_id,
                'item_name' => (string) ($row->item_name ?? $row->custom_item_name ?? '—'),
                'sku' => $row->sku,
                'uom' => $row->uom,
                'requested' => (float) $row->requested_qty,
                // No master record behind it, so it has no stock figure and
                // nothing it could have been received against.
                'in_master' => $row->item_id !== null,
            ]);
    }

    /**
     * Stock in hand per item, as at today.
     *
     * Reuses PurchaseRequisitionService::snapshots(), which is what the
     * requisition form's own stock chips read and which itself goes through the
     * Stock Report service — so "Current Stock" here is the number the Stock
     * Report prints, not a third definition of it. Batched: one pass for every
     * item on the widget, not one query each.
     *
     * @param  list<int>  $itemIds
     * @return array<int, float|null>
     */
    private function stockOnHand(array $itemIds): array
    {
        $ids = array_values(array_filter($itemIds));

        if ($ids === []) {
            return [];
        }

        return array_map(
            fn (array $snapshot) => $snapshot['stock_in_hand'] ?? null,
            $this->requisitions->snapshots($ids)
        );
    }

    /**
     * Qty received per item over the same period, from the Receiving report.
     *
     * Reuses ReceivingReportService rather than querying stock_purchases again:
     * one source for "what arrived", so this widget and the Receiving report
     * can never quote different numbers for the same month.
     *
     * NOT narrowed to the user's own requisitions, and that is deliberate.
     * Receiving is recorded by the Store against a challan, not against a
     * requisition — there is no link from a receipt back to the request that
     * prompted it. So this is "what arrived of this item", and for a scoped
     * user it is the honest answer to "has my item come in", not a claim that
     * the delivery was raised on their behalf.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, float> stock_item_id => qty received
     */
    private function purchasedByItem(array $filters): array
    {
        $payload = $this->receiving->reportPayload(array_filter([
            'month' => $filters['month'] ?? null,
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''));

        $totals = [];

        // lines() is keyed by delivery; every line under every delivery is one
        // item receipt, which is exactly what has to be added up here.
        foreach ($payload['lines'] as $deliveryLines) {
            foreach ($deliveryLines as $line) {
                if (! $line->stock_item_id) {
                    continue;
                }

                $totals[(int) $line->stock_item_id] =
                    ($totals[(int) $line->stock_item_id] ?? 0) + (float) $line->qty;
            }
        }

        return $totals;
    }

    /**
     * The same period the Receiving side is given, applied to the requested
     * side, so the two halves of every row describe the same window.
     *
     * Month wins over from/to when both arrive, which is the order the
     * Receiving report resolves them in too.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyPeriod($query, array $filters, string $column): void
    {
        $month = $this->receiving->resolveMonth($filters['month'] ?? null);

        if ($month) {
            $query->whereYear($column, $month->year)->whereMonth($column, $month->month);

            return;
        }

        if ($from = ($filters['from'] ?? null)) {
            $query->whereDate($column, '>=', $from);
        }

        if ($to = ($filters['to'] ?? null)) {
            $query->whereDate($column, '<=', $to);
        }
    }
}
