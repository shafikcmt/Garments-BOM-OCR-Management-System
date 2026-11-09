<?php

namespace App\Services;

use App\Models\BuyerSeason;
use App\Models\Inquiry;
use App\Models\MotherStyle;
use App\Models\User;
use App\Support\StyleCodeParser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Inquiry > pre-costing > quotation > won / lost.
 *
 *   new -> costing (style made in the Style Library, pre-costing started)
 *       -> quoted (price and date recorded) -> won | lost (reason)
 *   cancelled and reopen are allowed from any step that is not won.
 *
 * Costing is the existing pre-costing; nothing new is costed here. A won
 * inquiry does not create an order: the buyer's order comes in as before
 * (Import Buyer Excel), on the style this inquiry already made.
 */
class Inquiries
{
    public function __construct(private Costings $costings)
    {
    }

    public function create(array $data, User $by): Inquiry
    {
        return DB::transaction(function () use ($data, $by) {
            $inquiry = Inquiry::create($data + ['created_by' => $by->id, 'updated_by' => $by->id]);
            $inquiry->update(['reference' => 'INQ-' . Carbon::now()->format('y') . '-' . str_pad((string) $inquiry->id, 4, '0', STR_PAD_LEFT)]);

            return $inquiry;
        });
    }

    /**
     * Make (or find) the style in the Style Library and start the buyer pre-costing.
     *
     * @throws \DomainException
     */
    public function startCosting(Inquiry $inquiry, ?int $seasonId, ?string $newSeasonCode, User $by): MotherStyle
    {
        if (! in_array($inquiry->status, ['new', 'costing'], true)) {
            throw new \DomainException('Costing can be started only on a new inquiry.');
        }

        return DB::transaction(function () use ($inquiry, $seasonId, $newSeasonCode, $by) {
            $mother = $inquiry->motherStyle;

            if (! $mother) {
                $season = null;
                if (trim((string) $newSeasonCode) !== '') {
                    $season = BuyerSeason::firstOrCreate(['buyer_id' => $inquiry->buyer_id, 'code' => strtoupper(trim($newSeasonCode))]);
                } elseif ($seasonId) {
                    $season = BuyerSeason::where('buyer_id', $inquiry->buyer_id)->find($seasonId);
                }
                if (! $season) {
                    throw new \DomainException('Choose the season, or type a new season code.');
                }

                $row = StyleCodeParser::parseList($inquiry->style_ref)[0] ?? null;
                $styleNo = $row['style_no'] ?? trim($inquiry->style_ref);
                $mother = MotherStyle::firstOrCreate(
                    ['buyer_season_id' => $season->id, 'style_no' => $styleNo],
                    ['buyer_id' => $inquiry->buyer_id, 'product_group' => $inquiry->product_group, 'description' => $inquiry->description, 'created_by' => $by->id]
                );
                $inquiry->buyer_season_id = $season->id;
                $inquiry->mother_style_id = $mother->id;
            }

            // The pre-costing may already exist (the style had one); then it is simply reused.
            if (! $mother->costings()->where('mode', 'buyer')->exists()) {
                $this->costings->start($mother, 'buyer', $by);
            }

            $inquiry->fill(['status' => 'costing', 'updated_by' => $by->id])->save();

            return $mother;
        });
    }

    /** The price from the style's latest buyer costing, to pre-fill the quotation. */
    public function suggestedPrice(Inquiry $inquiry): ?array
    {
        $costing = $inquiry->motherStyle?->costings()->where('mode', 'buyer')->orderByDesc('revision')->first();
        if (! $costing || $costing->quoted_price === null) {
            return null;
        }

        return ['price' => (float) $costing->quoted_price, 'currency' => $costing->currency, 'revision' => $costing->revision];
    }

    /** @throws \DomainException */
    public function markQuoted(Inquiry $inquiry, float $price, string $currency, ?string $date, User $by): Inquiry
    {
        if (! in_array($inquiry->status, ['new', 'costing', 'quoted'], true)) {
            throw new \DomainException('This inquiry is already closed.');
        }
        $inquiry->update([
            'status' => 'quoted', 'quoted_price' => $price, 'currency' => $currency,
            'quoted_date' => $date ?: Carbon::today()->toDateString(), 'updated_by' => $by->id,
        ]);

        return $inquiry;
    }

    /** @throws \DomainException */
    public function markWon(Inquiry $inquiry, User $by): Inquiry
    {
        if ($inquiry->status !== 'quoted') {
            throw new \DomainException('Only a quoted inquiry can be marked won.');
        }
        $inquiry->update(['status' => 'won', 'decision_date' => Carbon::today()->toDateString(), 'lost_reason' => null, 'updated_by' => $by->id]);

        return $inquiry;
    }

    /** @throws \DomainException */
    public function markLost(Inquiry $inquiry, string $reason, User $by): Inquiry
    {
        if ($inquiry->status !== 'quoted') {
            throw new \DomainException('Only a quoted inquiry can be marked lost.');
        }
        $inquiry->update(['status' => 'lost', 'decision_date' => Carbon::today()->toDateString(), 'lost_reason' => $reason, 'updated_by' => $by->id]);

        return $inquiry;
    }

    /** @throws \DomainException */
    public function cancel(Inquiry $inquiry, User $by): Inquiry
    {
        if (! $inquiry->isOpen()) {
            throw new \DomainException('Only an open inquiry can be cancelled.');
        }
        $inquiry->update(['status' => 'cancelled', 'decision_date' => Carbon::today()->toDateString(), 'updated_by' => $by->id]);

        return $inquiry;
    }

    /** Back to the step the work had reached. @throws \DomainException */
    public function reopen(Inquiry $inquiry, User $by): Inquiry
    {
        if (! in_array($inquiry->status, ['lost', 'cancelled'], true)) {
            throw new \DomainException('Only a lost or cancelled inquiry can be reopened.');
        }
        $status = $inquiry->quoted_price !== null ? 'quoted' : ($inquiry->mother_style_id ? 'costing' : 'new');
        $inquiry->update(['status' => $status, 'decision_date' => null, 'lost_reason' => null, 'updated_by' => $by->id]);

        return $inquiry;
    }

    /**
     * @param  Collection<int, Inquiry>  $inquiries
     * @return array{open: int, late: int, awaiting: int, won: int, lost: int, win_rate: ?int}
     */
    public function kpis(Collection $inquiries, ?Carbon $today = null): array
    {
        $won = $inquiries->where('status', 'won')->count();
        $lost = $inquiries->where('status', 'lost')->count();

        return [
            'open' => $inquiries->filter(fn (Inquiry $i) => $i->isOpen())->count(),
            'late' => $inquiries->filter(fn (Inquiry $i) => $i->isQuoteLate($today))->count(),
            'awaiting' => $inquiries->where('status', 'quoted')->count(),
            'won' => $won,
            'lost' => $lost,
            'win_rate' => $won + $lost > 0 ? (int) round($won * 100 / ($won + $lost)) : null,
        ];
    }
}
