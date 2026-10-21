<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\BuyerSeason;
use App\Models\FollowUpTask;
use App\Services\StyleRollup;
use Illuminate\Http\Request;

/**
 * Season-level status: every style of a season at a glance, and every season of
 * a buyer. Read-only, shared by Merchandising and by Admin / Management, who
 * watch progress rather than edit styles. A buyer-confined user sees only the
 * buyers they hold.
 */
class StatusController extends Controller
{
    public function index(Request $request, StyleRollup $rollup)
    {
        $user = $request->user();

        $buyers = Buyer::active()
            ->when($user->isBuyerConfined(), fn ($q) => $q->whereIn('id', $user->merchantBuyerIds()))
            ->orderBy('buyer_name')
            ->get();

        $buyer = $buyers->firstWhere('id', (int) $request->query('buyer'));

        $seasons = BuyerSeason::with('buyer')
            ->whereIn('buyer_id', $buyer ? [$buyer->id] : $buyers->pluck('id'))
            ->orderByDesc('id')
            ->limit(60)
            ->get();

        $rows = $seasons->map(function (BuyerSeason $season) use ($rollup) {
            $data = $rollup->forSeason($season);

            return ['season' => $season, 'styles' => $data['styles']->count(), 'totals' => $data['totals']];
        });

        return view('merchant.status-index', compact('buyers', 'buyer', 'rows'));
    }

    public function season(Request $request, BuyerSeason $season, StyleRollup $rollup)
    {
        abort_unless($request->user()->hasBuyerAccess($season->buyer_id), 403);

        $season->load('buyer');
        $data = $rollup->forSeason($season);

        // Styles that need attention first: most late, then most due soon, then by number.
        $styles = $data['styles']->sort(function ($a, $b) {
            return [$b['kpis']['late'], $b['kpis']['due_soon'], $a['mother']->style_no]
                <=> [$a['kpis']['late'], $a['kpis']['due_soon'], $b['mother']->style_no];
        })->values();

        // Open follow-ups per style, so the table shows who is already chasing what.
        $openFollowUps = FollowUpTask::open()->whereIn('mother_style_id', $styles->pluck('mother.id'))
            ->selectRaw('mother_style_id, count(*) as total')->groupBy('mother_style_id')->pluck('total', 'mother_style_id');

        return view('merchant.status-season', ['season' => $season, 'styles' => $styles, 'totals' => $data['totals'], 'openFollowUps' => $openFollowUps]);
    }
}
