<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One standard material line of a product group (a buyer's own, or common to all buyers). */
class ConsumptionMaster extends Model
{
    protected $fillable = [
        'buyer_id', 'product_group', 'material_type', 'material_description', 'sap_code', 'material_color',
        'uom', 'consumption', 'wastage_percent', 'position', 'updated_by',
    ];

    protected $casts = ['consumption' => 'decimal:6', 'wastage_percent' => 'decimal:3'];

    public function buyer()
    {
        return $this->belongsTo(Buyer::class);
    }

    /**
     * The master rows that apply to a style, in the Create BOM form's shape.
     * A row applies when it is the style's buyer's or common to all buyers, and
     * its product group is the style's or empty. The buyer's own row beats a
     * common row for the same material.
     *
     * @return list<array<string, mixed>>
     */
    public static function rowsForStyle(MotherStyle $mother): array
    {
        $group = mb_strtolower(trim((string) $mother->product_group));

        return static::query()
            ->where(fn ($q) => $q->where('buyer_id', $mother->buyer_id)->orWhereNull('buyer_id'))
            ->orderBy('position')->orderBy('id')
            ->get()
            ->filter(function (self $r) use ($group) {
                $g = mb_strtolower(trim((string) $r->product_group));

                return $g === '' || $g === $group;
            })
            // Buyer's own rows first, so they win the de-duplication below.
            ->sortBy(fn (self $r) => $r->buyer_id === null ? 1 : 0)
            ->unique(fn (self $r) => static::materialKey($r->sap_code, $r->material_description))
            ->sortBy(['position', 'id'])
            ->map(fn (self $r) => [
                'type' => $r->material_type,
                'description' => $r->material_description,
                'sap_code' => $r->sap_code,
                'color' => $r->material_color,
                'uom' => $r->uom,
                'consumption' => (float) $r->consumption,
                'wastage' => $r->wastage_percent !== null ? (float) $r->wastage_percent : null,
            ])->values()->all();
    }

    public static function materialKey(?string $sapCode, ?string $description): string
    {
        return mb_strtolower(trim((string) (trim((string) $sapCode) !== '' ? $sapCode : $description)));
    }
}
