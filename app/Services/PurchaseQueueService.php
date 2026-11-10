<?php

namespace App\Services;

use App\Models\GsComparativeStatement;
use App\Models\GsCsItem;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use Illuminate\Support\Collection;

/**
 * The "To Purchase" queue: lines of APPROVED General Stock requisitions
 * (Final sheets and Individual sheets) that no Comparative Statement covers yet.
 *
 * Quantity to buy is the line's To Be Procured qty, the figure the approvers
 * finalised (RequisitionWorkflow rewrites it when an approver changes a qty).
 * A line already on a live CS, whatever that CS's stage, counts as taken;
 * cancelling the CS gives the quantity back to the queue.
 *
 * Read-only: nothing here writes.
 */
class PurchaseQueueService
{
    /** Requisition statuses that can still have something to buy. */
    private const STATUSES = [
        PurchaseRequisition::STATUS_APPROVED,
        PurchaseRequisition::STATUS_PURCHASE_ACTION_TAKEN,
    ];

    /**
     * @param  array{requisition?: int|null, type?: string|null, q?: string|null, category?: string|null}  $filters
     * @return Collection<int, PurchaseRequisitionItem> each with ->outstanding_qty and ->requisition loaded
     */
    public function lines(array $filters = []): Collection
    {
        $items = PurchaseRequisitionItem::query()
            ->with(['requisition:id,requisition_no,requisition_date,level,requisition_type,status,department_role,approved_at', 'stockItem:id,name,uom,sku,category', 'itemCategory'])
            ->where('to_be_procured_qty', '>', 0)
            ->whereHas('requisition', fn ($q) => $this->approvedSheets($q, $filters))
            ->orderBy('purchase_requisition_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $covered = $this->coveredQty($items->pluck('id')->all());
        $needle = mb_strtolower(trim((string) ($filters['q'] ?? '')));

        return $items
            ->each(fn (PurchaseRequisitionItem $i) => $i->setAttribute(
                'outstanding_qty',
                max(0.0, (float) $i->to_be_procured_qty - ($covered[$i->id] ?? 0.0))
            ))
            ->filter(fn (PurchaseRequisitionItem $i) => $i->outstanding_qty > 0)
            ->each(fn (PurchaseRequisitionItem $i) => $i->setAttribute('category_name', self::categoryOf($i)))
            ->when(! empty($filters['category']), fn (Collection $c) => $c->filter(fn (PurchaseRequisitionItem $i) => $i->category_name === $filters['category']))
            ->when($needle !== '', fn (Collection $c) => $c->filter(fn (PurchaseRequisitionItem $i) => str_contains(
                mb_strtolower($i->displayName().' '.($i->specification ?? '').' '.($i->requisition?->requisition_no ?? '')),
                $needle
            )))
            ->values();
    }

    /** The group a line belongs to: the requisition line's category, else the item master's, else "Uncategorised". */
    public static function categoryOf(PurchaseRequisitionItem $line): string
    {
        $name = trim((string) ($line->itemCategory?->name ?? $line->stockItem?->category ?? ''));

        return $name !== '' ? $name : 'Uncategorised';
    }

    /** Count for the sidebar badge / queue header. */
    public function outstandingCount(): int
    {
        return $this->lines()->count();
    }

    /**
     * One line, only if it is still purchasable (for the CS form).
     */
    public function find(int $id): ?PurchaseRequisitionItem
    {
        return $this->lines()->firstWhere('id', $id);
    }

    /**
     * Quantity per requisition line already on a live CS.
     *
     * @param  list<int>  $itemIds
     * @return array<int, float>
     */
    public function coveredQty(array $itemIds, ?int $exceptStatementId = null): array
    {
        if ($itemIds === []) {
            return [];
        }

        return GsCsItem::query()
            ->whereIn('purchase_requisition_item_id', $itemIds)
            ->whereHas('statement', fn ($q) => $q->where('status', '<>', GsComparativeStatement::STATUS_CANCELLED)
                ->when($exceptStatementId, fn ($w) => $w->where('id', '<>', $exceptStatementId)))
            ->get(['purchase_requisition_item_id', 'qty'])
            ->groupBy('purchase_requisition_item_id')
            ->map(fn (Collection $rows) => (float) $rows->sum('qty'))
            ->all();
    }

    private function approvedSheets($query, array $filters)
    {
        $query->whereIn('status', self::STATUSES)
            // A Final sheet, or an Individual one-off. A monthly department
            // sheet is merged into a Final sheet and is never bought from.
            ->where(fn ($q) => $q->where('level', PurchaseRequisition::LEVEL_FINAL)
                ->orWhere('requisition_type', PurchaseRequisition::TYPE_INDIVIDUAL))
            ->whereNull('merged_into_id');

        if (! empty($filters['requisition'])) {
            $query->where('id', (int) $filters['requisition']);
        }

        if (($filters['type'] ?? null) === 'individual') {
            $query->where('requisition_type', PurchaseRequisition::TYPE_INDIVIDUAL);
        } elseif (($filters['type'] ?? null) === 'final') {
            $query->where('level', PurchaseRequisition::LEVEL_FINAL)
                ->where(fn ($q) => $q->whereNull('requisition_type')->orWhere('requisition_type', '<>', PurchaseRequisition::TYPE_INDIVIDUAL));
        }

        return $query;
    }
}
