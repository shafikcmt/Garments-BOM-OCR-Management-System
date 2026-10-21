<?php

namespace App\Services;

use App\Models\Costing;
use App\Models\CostingLine;
use App\Models\MotherStyle;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pre-costing of a style.
 *
 * A costing is one cost sheet in one MODE (what is quoted to the buyer, or what
 * the factory expects to spend) and one REVISION. Lines sit under a cost head;
 * every amount is qty x (1 + wastage %) x rate, worked out here so a screen can
 * never store a figure that disagrees with its inputs. A revision is edited while
 * it is a draft. Finalizing locks it; changing a final costing means starting a
 * new revision, which begins as a copy.
 *
 * Total cost, profit and margin are measured per the sheet's basis (piece or
 * dozen) and are the same ratio either way.
 */
class Costings
{
    /**
     * Cost heads: key => [label, group]. The group decides the sub-total it adds
     * to. Extend this list to add a head; nothing else needs to change.
     */
    public const HEADS = [
        'fabric' => ['Fabric', 'material'],
        'trims' => ['Trims and accessories', 'material'],
        'embellishment' => ['Embroidery / print', 'material'],
        'washing' => ['Washing', 'material'],
        'testing' => ['Testing / other', 'material'],
        'cm' => ['CM (cut and make)', 'conversion'],
        'overhead' => ['Overhead', 'overhead'],
        'commission' => ['Commission', 'commission'],
    ];

    public const GROUPS = [
        'material' => 'Material cost',
        'conversion' => 'CM',
        'overhead' => 'Overhead',
        'commission' => 'Commission',
    ];

    /** Rows a new costing starts with, so the sheet is never empty. */
    private const STARTER = [
        ['fabric', 'Main fabric', 'yds'],
        ['trims', 'Trims', null],
        ['cm', 'CM', null],
        ['overhead', 'Overhead', null],
        ['commission', 'Commission', null],
    ];

    public static function amount(float $qty, float $wastagePct, float $rate): float
    {
        return round($qty * (1 + $wastagePct / 100) * $rate, 4);
    }

    /** @throws \DomainException */
    public function start(MotherStyle $mother, string $mode, User $by): Costing
    {
        if (! array_key_exists($mode, Costing::MODES)) {
            throw new \DomainException('Unknown costing mode.');
        }

        if ($mother->costings()->where('mode', $mode)->exists()) {
            throw new \DomainException('This style already has a ' . Costing::MODES[$mode] . ' costing.');
        }

        return DB::transaction(function () use ($mother, $mode, $by) {
            $costing = Costing::create(['mother_style_id' => $mother->id, 'mode' => $mode, 'revision' => 1, 'created_by' => $by->id]);

            foreach (self::STARTER as $i => [$head, $description, $unit]) {
                $costing->lines()->create(['head' => $head, 'description' => $description, 'unit' => $unit, 'position' => $i]);
            }

            return $costing;
        });
    }

    /**
     * Replace the header fields and every line of a DRAFT costing.
     *
     * @param  array<string, mixed>  $header  currency, basis, quoted_price, note
     * @param  array<int, array<string, mixed>>  $lines
     * @throws \DomainException
     */
    public function save(Costing $costing, array $header, array $lines): Costing
    {
        $this->assertDraft($costing);

        return DB::transaction(function () use ($costing, $header, $lines) {
            $costing->lines()->delete();

            $total = 0.0;
            $position = 0;

            foreach ($lines as $line) {
                $qty = (float) ($line['qty'] ?? 1);
                $wastage = (float) ($line['wastage_pct'] ?? 0);
                $rate = (float) ($line['rate'] ?? 0);
                $description = trim((string) ($line['description'] ?? ''));

                // An untouched blank row is not a cost line.
                if ($description === '' && $rate == 0.0) {
                    continue;
                }

                $amount = self::amount($qty, $wastage, $rate);
                $total += $amount;

                $costing->lines()->create([
                    'head' => $line['head'], 'description' => $description !== '' ? $description : null,
                    'qty' => $qty, 'unit' => ($line['unit'] ?? null) ?: null, 'wastage_pct' => $wastage,
                    'rate' => $rate, 'amount' => $amount, 'position' => $position++,
                ]);
            }

            $costing->update([
                'currency' => $header['currency'] ?? $costing->currency,
                'basis' => $header['basis'] ?? $costing->basis,
                'quoted_price' => ($header['quoted_price'] ?? '') === '' ? null : $header['quoted_price'],
                'note' => $header['note'] ?? null,
                'total_cost' => round($total, 4),
            ]);

            return $costing->refresh();
        });
    }

    /** @throws \DomainException */
    public function finalize(Costing $costing, User $by): Costing
    {
        $this->assertDraft($costing);

        if ((float) $costing->total_cost <= 0) {
            throw new \DomainException('Enter at least one cost before finalizing.');
        }

        $costing->update(['status' => Costing::FINAL, 'finalized_by' => $by->id, 'finalized_at' => now()]);

        return $costing->refresh();
    }

    /**
     * A new draft revision that starts as a copy of the latest one.
     *
     * @throws \DomainException
     */
    public function revise(MotherStyle $mother, string $mode, User $by): Costing
    {
        $latest = $mother->costings()->where('mode', $mode)->orderByDesc('revision')->first();

        if (! $latest) {
            throw new \DomainException('There is no costing to revise yet.');
        }

        if ($latest->isDraft()) {
            throw new \DomainException('Revision ' . $latest->revision . ' is still a draft. Finalize it first.');
        }

        return DB::transaction(function () use ($latest, $by) {
            $copy = Costing::create([
                'mother_style_id' => $latest->mother_style_id, 'mode' => $latest->mode, 'revision' => $latest->revision + 1,
                'currency' => $latest->currency, 'basis' => $latest->basis, 'quoted_price' => $latest->quoted_price,
                'total_cost' => $latest->total_cost, 'note' => $latest->note, 'created_by' => $by->id,
            ]);

            foreach ($latest->lines as $line) {
                $copy->lines()->create($line->only(['head', 'description', 'qty', 'unit', 'wastage_pct', 'rate', 'amount', 'position']));
            }

            return $copy;
        });
    }

    /**
     * Sub-totals by cost head and group, total, profit and margin.
     *
     * @return array{heads: array<string, float>, groups: array<string, float>, total: float, quoted: ?float, profit: ?float, margin: ?float}
     */
    public function summary(Costing $costing): array
    {
        $lines = $costing->relationLoaded('lines') ? $costing->lines : $costing->lines()->get();

        $heads = collect(array_keys(self::HEADS))->mapWithKeys(fn ($h) => [$h => round((float) $lines->where('head', $h)->sum('amount'), 4)])->all();
        $groups = collect(self::GROUPS)->mapWithKeys(function ($label, $group) use ($heads) {
            return [$group => round(collect(self::HEADS)->filter(fn ($def) => $def[1] === $group)->keys()->sum(fn ($h) => $heads[$h]), 4)];
        })->all();

        $total = round(array_sum($heads), 4);
        $quoted = $costing->quoted_price !== null ? (float) $costing->quoted_price : null;

        return [
            'heads' => $heads,
            'groups' => $groups,
            'total' => $total,
            'quoted' => $quoted,
            'profit' => $quoted !== null ? round($quoted - $total, 4) : null,
            'margin' => $quoted > 0 ? round(($quoted - $total) / $quoted * 100, 1) : null,
        ];
    }

    /**
     * The latest FINAL costing of each style, for one mode: what is "the" costing.
     *
     * @param  Collection<int, MotherStyle>  $mothers
     * @return Collection<int, Costing>  keyed by style id
     */
    public function latestFinal(Collection $mothers, string $mode = 'buyer'): Collection
    {
        return Costing::whereIn('mother_style_id', $mothers->pluck('id'))->where('mode', $mode)->where('status', Costing::FINAL)
            ->orderBy('revision')->get()->keyBy('mother_style_id');
    }

    private function assertDraft(Costing $costing): void
    {
        if (! $costing->isDraft()) {
            throw new \DomainException('Revision ' . $costing->revision . ' is final and cannot be edited. Start a new revision.');
        }
    }
}
