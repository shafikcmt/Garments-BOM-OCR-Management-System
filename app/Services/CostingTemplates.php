<?php

namespace App\Services;

use App\Models\Costing;
use App\Models\CostingTemplate;
use App\Models\MotherStyle;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Costing templates: which ones fit a style, starting a costing from one, and
 * saving a costing as a template. Lines are copied both ways, so a template and
 * a costing never change each other afterwards.
 */
class CostingTemplates
{
    public function __construct(private Costings $costings)
    {
    }

    /**
     * Active templates that fit the style, best match first: the buyer's own for
     * the style's product group, then the group's for every buyer, then the
     * buyer's general ones, then the common general ones.
     *
     * @return Collection<int, CostingTemplate>
     */
    public function forStyle(MotherStyle $mother): Collection
    {
        $group = mb_strtolower(trim((string) $mother->product_group));

        return CostingTemplate::where('is_active', true)->withCount('lines')
            ->where(fn ($q) => $q->where('buyer_id', $mother->buyer_id)->orWhereNull('buyer_id'))
            ->orderBy('name')->get()
            ->filter(function (CostingTemplate $t) use ($group) {
                $g = mb_strtolower(trim((string) $t->product_group));

                return $g === '' || $g === $group;
            })
            ->sortBy(fn (CostingTemplate $t) => [
                (trim((string) $t->product_group) !== '' ? 0 : 2) + ($t->buyer_id !== null ? 0 : 1),
                $t->name,
            ])->values();
    }

    /**
     * Start the style's costing for a mode from a template (null = the standard rows).
     *
     * @throws \DomainException
     */
    public function start(MotherStyle $mother, string $mode, ?CostingTemplate $template, User $by): Costing
    {
        return DB::transaction(function () use ($mother, $mode, $template, $by) {
            $costing = $this->costings->start($mother, $mode, $by);

            if ($template) {
                $this->costings->save(
                    $costing,
                    ['currency' => $template->currency, 'basis' => $template->basis, 'quoted_price' => '', 'note' => null],
                    $template->lines->map(fn ($l) => [
                        'head' => $l->head, 'description' => $l->description, 'qty' => (float) $l->qty, 'unit' => $l->unit,
                        'wastage_pct' => (float) $l->wastage_pct, 'rate' => (float) $l->rate,
                    ])->all()
                );
            }

            return $costing->refresh();
        });
    }

    /** Save a costing's sheet as a template (quoted price and note are not kept). */
    public function saveFromCosting(Costing $costing, string $name, bool $forThisBuyer, User $by): CostingTemplate
    {
        $costing->loadMissing(['lines', 'motherStyle']);

        return DB::transaction(function () use ($costing, $name, $forThisBuyer, $by) {
            $template = CostingTemplate::create([
                'name' => $name, 'buyer_id' => $forThisBuyer ? $costing->motherStyle->buyer_id : null,
                'product_group' => $costing->motherStyle->product_group, 'currency' => $costing->currency, 'basis' => $costing->basis, 'created_by' => $by->id,
            ]);
            foreach ($costing->lines as $i => $l) {
                $template->lines()->create([
                    'head' => $l->head, 'description' => $l->description, 'qty' => $l->qty, 'unit' => $l->unit,
                    'wastage_pct' => $l->wastage_pct, 'rate' => $l->rate, 'position' => $i,
                ]);
            }

            return $template;
        });
    }
}
