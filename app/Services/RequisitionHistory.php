<?php

namespace App\Services;

use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionAction;
use App\Models\PurchaseRequisitionItem;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Writes a purchase requisition's history. Callers run it inside the same
 * transaction as the change it records, so the two commit or fail together.
 */
class RequisitionHistory
{
    /**
     * @param  array{stage?: ?string, from?: ?string, to?: ?string, comment?: ?string, meta?: ?array, user?: ?User}  $details
     */
    public function record(PurchaseRequisition $requisition, string $action, array $details = []): PurchaseRequisitionAction
    {
        $user = array_key_exists('user', $details) ? $details['user'] : auth()->user();
        $comment = trim((string) ($details['comment'] ?? ''));

        $cycle = (int) (PurchaseRequisitionAction::where('purchase_requisition_id', $requisition->id)->max('cycle') ?: 1);

        // A resubmission after a return opens the next round.
        if ($action === PurchaseRequisitionAction::RESUBMITTED) {
            $cycle++;
        }

        return PurchaseRequisitionAction::create([
            'purchase_requisition_id' => $requisition->id,
            'requisition_no' => $requisition->requisition_no,
            'action' => $action,
            'stage' => $details['stage'] ?? null,
            'from_status' => $details['from'] ?? null,
            'to_status' => array_key_exists('to', $details) ? $details['to'] : $requisition->status,
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'comment' => $comment === '' ? null : $comment,
            'cycle' => $cycle,
            'meta' => $details['meta'] ?? null,
            'created_at' => now(),
        ]);
    }

    /** The stage a head's decision is recorded under. */
    public static function headStage(PurchaseRequisition $requisition): string
    {
        return $requisition->review_level === RequisitionReviewChain::LEVEL_SECTION
            ? PurchaseRequisitionAction::STAGE_SECTION_HEAD
            : PurchaseRequisitionAction::STAGE_DEPARTMENT_HEAD;
    }

    /**
     * The quantity per item on a set of lines — what a Store correction is
     * compared on. Master items key on their id, typed items on their name.
     *
     * @param  Collection<int, PurchaseRequisitionItem>  $lines
     * @return array<string, array{item: string, qty: float}>
     */
    public function snapshotLines(Collection $lines): array
    {
        $snapshot = [];

        foreach ($lines as $line) {
            $key = $line->stock_item_id
                ? 'item:'.$line->stock_item_id
                : 'custom:'.mb_strtolower(trim((string) $line->custom_item_name));

            $snapshot[$key] ??= ['item' => $line->displayName(), 'qty' => 0.0];
            $snapshot[$key]['qty'] += (float) $line->qty_requested;
        }

        return $snapshot;
    }

    /**
     * Line-by-line difference between two snapshots: changed, added and
     * removed items only. The lines themselves are deleted and recreated on
     * save, so this is the only place the original quantities survive.
     *
     * @return list<array{item: string, change: string, old_qty: ?float, new_qty: ?float}>
     */
    public function diffLines(array $before, array $after): array
    {
        $changes = [];

        foreach ($before as $key => $old) {
            $new = $after[$key] ?? null;

            if ($new === null) {
                $changes[] = ['item' => $old['item'], 'change' => 'removed', 'old_qty' => $old['qty'], 'new_qty' => null];
            } elseif (abs($new['qty'] - $old['qty']) > 0.00001) {
                $changes[] = ['item' => $old['item'], 'change' => 'changed', 'old_qty' => $old['qty'], 'new_qty' => $new['qty']];
            }
        }

        foreach ($after as $key => $new) {
            if (! isset($before[$key])) {
                $changes[] = ['item' => $new['item'], 'change' => 'added', 'old_qty' => null, 'new_qty' => $new['qty']];
            }
        }

        return $changes;
    }
}
