<?php

namespace App\Services;

use App\Models\BomItem;
use App\Models\Costing;
use App\Models\MaterialBulkIssue;
use App\Models\MaterialReceiving;
use App\Models\MotherStyle;
use Illuminate\Support\Collection;

/**
 * Material post-costing of a style: what the final costing said the materials
 * would cost against what Store actually received and issued.
 *
 *   budget          material cost of the latest FINAL buyer costing, per piece
 *                   (a dozen sheet is divided by 12) x the style's order quantity
 *   received value  every GRN of the style's BOM rows: physical qty x unit price
 *                   (the price Store booked, from the invoiced / PI rate)
 *   issued value    bulk issue qty x that row's average receiving price
 *   in stock        received value - issued value: bought, not yet used
 *
 * This is the part of post-costing the system holds real figures for. CM,
 * overhead, commission, claims and the quantity actually shipped are not
 * recorded anywhere yet, so they are NOT in this page and the profit is not
 * worked out. Receipt prices carry no currency; they are assumed to be in the
 * costing's currency.
 */
class PostCosting
{
    public function __construct(private Costings $costings)
    {
    }

    /**
     * @return array<string, mixed>|null  null when the style has no order lines
     */
    public function forStyle(MotherStyle $mother): ?array
    {
        $items = BomItem::whereHas('orderLine', fn ($q) => $q->where('mother_style_id', $mother->id))
            ->with('orderLine:id,gmts_color,customer_po_no,order_qty')->get();
        if ($items->isEmpty()) {
            return null;
        }

        $orderQty = (float) $items->pluck('orderLine')->unique('id')
            ->groupBy(fn ($l) => mb_strtolower(trim((string) $l->gmts_color)) . '|' . mb_strtolower(trim((string) $l->customer_po_no)))
            ->sum(fn ($g) => (float) $g->max('order_qty'));

        $costing = $this->costings->latestFinal(collect([$mother]))->get($mother->id);
        $perPiece = null;
        if ($costing) {
            $perUnit = $costing->basis === 'dz' ? 12 : 1;
            $perPiece = (float) $this->costings->summary($costing->loadMissing('lines'))['groups']['material'] / $perUnit;
        }

        $rowIds = $items->pluck('excel_row_id')->filter()->unique()->values()->all();
        $received = collect();
        $issued = collect();
        foreach (array_chunk($rowIds, 900) as $chunk) {
            $received = $received->merge(MaterialReceiving::whereIn('excel_row_id', $chunk)->get(['excel_row_id', 'qty', 'unit_price', 'invoice_value']));
            $issued = $issued->merge(MaterialBulkIssue::whereIn('excel_row_id', $chunk)->get(['excel_row_id', 'bulk_qty']));
        }
        $recByRow = $received->groupBy('excel_row_id');
        $issByRow = $issued->groupBy('excel_row_id');

        $materials = $items->groupBy(fn (BomItem $i) => SizeWiseBom::materialKey($i->sap_code, $i->material_description))
            ->reject(fn ($g, $key) => $key === '')
            ->map(function (Collection $group) use ($recByRow, $issByRow) {
                $first = $group->first();
                $qty = $value = $issQty = $issValue = 0.0;
                $unpriced = $noReceipt = false;

                foreach ($group as $item) {
                    $rec = $recByRow->get($item->excel_row_id, collect());
                    $rQty = (float) $rec->sum('qty');
                    $rValue = (float) $rec->sum(fn ($r) => $r->invoice_value !== null ? (float) $r->invoice_value : (float) $r->qty * (float) $r->unit_price);
                    $iQty = (float) $issByRow->get($item->excel_row_id, collect())->sum('bulk_qty');

                    $qty += $rQty;
                    $value += $rValue;
                    $issQty += $iQty;
                    if ($rQty > 0 && $rValue <= 0) {
                        $unpriced = true;
                    }
                    if ($iQty > 0 && $rQty <= 0) {
                        $noReceipt = true;
                    }
                    // Issued at the average price this row was received at.
                    $issValue += $rQty > 0 ? $iQty * ($rValue / $rQty) : 0.0;
                }

                return [
                    'name' => (string) ($first->material_description ?: $first->sap_code),
                    'uom' => $first->uom,
                    'received_qty' => $qty, 'received_value' => $value,
                    'issued_qty' => $issQty, 'issued_value' => $issValue,
                    'in_stock_value' => $value - $issValue,
                    'unpriced' => $unpriced, 'issued_without_receipt' => $noReceipt,
                ];
            })->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();

        $receivedValue = (float) $materials->sum('received_value');
        $issuedValue = (float) $materials->sum('issued_value');
        $budget = $perPiece !== null ? $perPiece * $orderQty : null;

        return [
            'costing' => $costing,
            'order_qty' => $orderQty,
            'per_piece_budget' => $perPiece,
            'budget' => $budget,
            'received_value' => $receivedValue,
            'issued_value' => $issuedValue,
            'in_stock_value' => $receivedValue - $issuedValue,
            'variance' => $budget !== null ? $receivedValue - $budget : null,
            'variance_pct' => $budget > 0 ? round(($receivedValue - $budget) / $budget * 100, 1) : null,
            'received_pct' => $budget > 0 ? round($receivedValue / $budget * 100, 1) : null,
            'issued_pct' => $budget > 0 ? round($issuedValue / $budget * 100, 1) : null,
            'materials' => $materials->all(),
            'unpriced' => $materials->where('unpriced', true)->count(),
            'issued_without_receipt' => $materials->where('issued_without_receipt', true)->count(),
            'has_receipts' => $received->isNotEmpty(),
        ];
    }
}
