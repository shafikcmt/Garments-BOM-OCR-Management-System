<?php

namespace App\Services;

use App\Models\BomItem;
use App\Models\MotherStyle;

/**
 * The material library of the BOM builder, learned from the BOMs already made.
 *
 * There is no list to maintain: every material that has been on a BOM of a buyer
 * (typed in the builder or imported from Excel) is a suggestion for the next one,
 * with the type, SAP code, colour, unit, consumption and wastage it last had.
 * Suggestions and copies stay within ONE buyer: SAP codes and specifications are
 * the buyer's own, and another buyer's BOM is not this user's to read.
 *
 * Rows come back in the shape the builder form uses.
 */
class BomMaterialLibrary
{
    public const SUGGEST_LIMIT = 10;

    /**
     * The materials of an earlier BOM, duplicates merged.
     *
     * @param  int|null  $orderLineId  one order line of that style, or null for all of them
     * @return list<array<string, mixed>>
     */
    public function materialsOf(MotherStyle $source, ?int $orderLineId = null): array
    {
        $items = BomItem::query()
            ->whereHas('orderLine', fn ($q) => $q->where('mother_style_id', $source->id)->when($orderLineId, fn ($l) => $l->where('id', $orderLineId)))
            ->orderBy('id')
            ->get();

        return $items
            ->unique(fn (BomItem $i) => $this->key($i, true))
            ->map(fn (BomItem $i) => $this->row($i))
            ->values()
            ->all();
    }

    /**
     * Past materials of the same buyer that match what is being typed.
     *
     * @return list<array<string, mixed>>
     */
    public function suggest(MotherStyle $target, string $term): array
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return [];
        }

        $like = '%' . addcslashes($term, '%_\\') . '%';

        return BomItem::query()
            ->whereHas('orderLine', fn ($q) => $q->where('buyer_id', $target->buyer_id))
            ->where(fn ($q) => $q->whereLike('material_description', $like)->orWhereLike('sap_code', $like))
            ->orderByDesc('id')
            ->limit(300)
            ->get()
            ->unique(fn (BomItem $i) => $this->key($i, false))   // newest of each material wins
            ->take(self::SUGGEST_LIMIT)
            ->map(fn (BomItem $i) => $this->row($i))
            ->values()
            ->all();
    }

    /**
     * Wastage as the builder shows it: a percentage. The workspace stores it as a
     * fraction (0.05) but older sheets hold 5; like the workspace, a value above 1
     * is already a percentage.
     */
    public static function percent(mixed $stored): ?float
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        $n = (float) $stored;

        return round($n > 1 ? $n : $n * 100, 3);
    }

    private function row(BomItem $item): array
    {
        $consumption = $item->initial_consumption !== null ? (float) $item->initial_consumption : null;

        return [
            'type' => $item->material_type,
            'description' => $item->material_description,
            'sap_code' => $item->sap_code,
            'color' => $item->material_color,
            'uom' => $item->uom,
            'consumption' => $consumption,
            'wastage' => self::percent($item->wastage_percent),
        ];
    }

    /** Two items are "the same material" when these match; $withNumbers also compares consumption and wastage. */
    private function key(BomItem $i, bool $withNumbers): string
    {
        $parts = [$i->material_description, $i->sap_code];

        if ($withNumbers) {
            $parts = array_merge($parts, [$i->material_type, $i->material_color, $i->uom, $i->initial_consumption, $i->wastage_percent]);
        }

        return mb_strtolower(implode('|', array_map(fn ($p) => trim((string) $p), $parts)));
    }
}
