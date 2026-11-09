<?php

namespace App\Services;

use App\Models\BomItem;
use App\Models\MotherStyle;
use App\Models\OrderSizeQuantity;
use App\Models\StyleMaterialSize;
use Illuminate\Support\Collection;

/**
 * Size-wise BOM of a style.
 *
 * The workspace BOM has one consumption per material line, applied to the whole
 * order quantity. When sizes use different consumptions (a size 18 shell takes
 * more fabric than a size 6), the merchant keeps a consumption per size here and
 * the requirement is worked out size by size from the Size Breakdown:
 *
 *   requirement = sum over sizes of (size qty x size consumption) x wastage factor
 *
 * A size with no figure uses the BOM consumption. A colour / PO with no size
 * breakdown falls back to order quantity x BOM consumption and is flagged. The
 * workspace figures are never changed; the result is shown beside them.
 */
class SizeWiseBom
{
    public static function materialKey(?string $sapCode, ?string $description): string
    {
        $key = trim((string) $sapCode) !== '' ? $sapCode : $description;

        return mb_substr(mb_strtolower(trim((string) $key)), 0, 191);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function materials(MotherStyle $mother): Collection
    {
        $items = BomItem::whereHas('orderLine', fn ($q) => $q->where('mother_style_id', $mother->id))
            ->with('orderLine:id,gmts_color,customer_po_no,order_qty')->get();
        if ($items->isEmpty()) {
            return collect();
        }

        $sizeQty = OrderSizeQuantity::where('mother_style_id', $mother->id)->get()
            ->groupBy(fn ($q) => $this->lineKey($q->gmts_color, $q->customer_po_no));
        $overrides = StyleMaterialSize::where('mother_style_id', $mother->id)->get()->groupBy('material_key');

        return $items->groupBy(fn (BomItem $i) => self::materialKey($i->sap_code, $i->material_description))
            ->reject(fn ($g, $key) => $key === '')
            ->map(function (Collection $group, string $key) use ($sizeQty, $overrides) {
                $first = $group->first();
                $per = ($overrides->get($key) ?? collect())->mapWithKeys(fn ($o) => [(string) $o->size => (float) $o->consumption])->all();

                $sizeWise = 0.0;
                $workspace = 0.0;
                $fallback = 0;
                $baseSeen = [];
                foreach ($group as $item) {
                    $wf = $this->wastageFactor($item);
                    $base = $this->baseConsumption($item, $wf);
                    $line = $item->orderLine;
                    $qty = (float) ($line?->order_qty ?? 0);
                    $inclYy = $item->consumption_incl_yy !== null ? (float) $item->consumption_incl_yy : ($base !== null ? $base * $wf : null);
                    $workspace += $qty * (float) $inclYy;
                    if ($inclYy !== null) {
                        $baseSeen[] = $inclYy;
                    }

                    $sizes = $sizeQty->get($this->lineKey($line?->gmts_color, $line?->customer_po_no));
                    if (! $sizes || $sizes->isEmpty()) {
                        $sizeWise += $qty * (float) $inclYy;
                        $fallback++;

                        continue;
                    }
                    foreach ($sizes as $q) {
                        $cons = $per[(string) $q->size] ?? $base;
                        if ($cons !== null) {
                            $sizeWise += (float) $q->qty * $cons * $wf;
                        }
                    }
                }

                return [
                    'key' => $key,
                    'name' => (string) ($first->material_description ?: $first->sap_code),
                    'sap_code' => $first->sap_code,
                    'uom' => $first->uom,
                    'base' => $baseSeen === [] ? null : array_sum($baseSeen) / count($baseSeen),
                    'per_size' => $per,
                    'lines' => $group->count(),
                    'fallback_lines' => $fallback,
                    'size_wise_qty' => $sizeWise,
                    'workspace_qty' => $workspace,
                    'difference' => $sizeWise - $workspace,
                ];
            })->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /** Wastage as a multiplier: 1.05 for 5%. Uses the workspace's own figures first. */
    private function wastageFactor(BomItem $item): float
    {
        $initial = (float) $item->initial_consumption;
        $incl = (float) $item->consumption_incl_yy;
        if ($initial > 0 && $incl > 0) {
            return $incl / $initial;
        }
        $w = (float) $item->wastage_percent;

        // The workspace keeps wastage as a fraction (0.05); a typed 5 means 5%.
        return 1 + ($w > 1 ? $w / 100 : $w);
    }

    /** Consumption per garment before wastage. */
    private function baseConsumption(BomItem $item, float $wf): ?float
    {
        if ($item->initial_consumption !== null && (float) $item->initial_consumption > 0) {
            return (float) $item->initial_consumption;
        }
        if ($item->consumption_incl_yy !== null && (float) $item->consumption_incl_yy > 0 && $wf > 0) {
            return (float) $item->consumption_incl_yy / $wf;
        }

        return null;
    }

    private function lineKey(?string $color, ?string $po): string
    {
        return mb_strtolower(trim((string) $color)) . '|' . mb_strtolower(trim((string) $po));
    }
}
