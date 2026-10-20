<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\BuyerSeason;
use App\Models\ChildStyle;
use App\Models\MotherStyle;
use App\Support\StyleCodeParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Buyer -> Season -> Mother Style -> Child Style.
 *
 * The merchant's one place to organise styles the way the buyer folders do, so
 * any buyer's Excel can be matched to the same structure. Buyer scoping follows
 * the rest of Merchandising: a confined user sees and edits only their buyers.
 */
class StyleLibraryController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $buyers = Buyer::active()
            ->when($user->isBuyerConfined(), fn ($q) => $q->whereIn('id', $user->merchantBuyerIds()))
            ->withCount(['seasons', 'motherStyles'])
            ->orderBy('buyer_name')
            ->get();

        $buyer = $buyers->firstWhere('id', (int) $request->query('buyer')) ?? $buyers->first();

        $seasons = $buyer
            ? $buyer->seasons()->with('motherStyles.children')->get()
            : collect();

        $season = $seasons->firstWhere('id', (int) $request->query('season')) ?? $seasons->first();

        $totals = [
            'buyers' => $buyers->count(),
            'seasons' => (int) $buyers->sum('seasons_count'),
            'mothers' => (int) $buyers->sum('mother_styles_count'),
            'children' => ChildStyle::whereHas('mother', fn ($q) => $q->whereIn('buyer_id', $buyers->pluck('id')))->count(),
        ];

        return view('merchant.styles', compact('buyers', 'buyer', 'seasons', 'season', 'totals'));
    }

    public function storeSeason(Request $request)
    {
        $data = $request->validate([
            'buyer_id' => ['required', 'integer', 'exists:buyers,id'],
            'code' => ['required', 'string', 'max:50'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);
        $this->authorizeBuyer($request, (int) $data['buyer_id']);

        $data['code'] = strtoupper(trim($data['code']));

        $season = BuyerSeason::firstOrCreate(
            ['buyer_id' => $data['buyer_id'], 'code' => $data['code']],
            ['name' => $data['name'] ?? null]
        );

        return redirect()->route('merchant.styles.index', ['buyer' => $season->buyer_id, 'season' => $season->id])
            ->with('success', $season->wasRecentlyCreated ? 'Season added.' : 'Season already exists.');
    }

    public function storeMother(Request $request, BuyerSeason $season)
    {
        $this->authorizeBuyer($request, $season->buyer_id);

        $data = $request->validate([
            'codes' => ['required', 'string', 'max:2000'],
            'product_group' => ['nullable', 'string', 'max:60'],
        ]);

        $rows = StyleCodeParser::parseList($data['codes']);

        if ($rows === []) {
            return back()->withErrors(['codes' => 'Enter at least one style number.']);
        }

        $created = 0;

        DB::transaction(function () use ($rows, $season, $data, $request, &$created) {
            foreach ($rows as $row) {
                $mother = MotherStyle::firstOrCreate(
                    ['buyer_season_id' => $season->id, 'style_no' => $row['style_no']],
                    [
                        'buyer_id' => $season->buyer_id,
                        'product_group' => $data['product_group'] ?? null,
                        'created_by' => $request->user()->id,
                    ]
                );
                $created += $mother->wasRecentlyCreated ? 1 : 0;

                if ($row['child'] !== null) {
                    ChildStyle::firstOrCreate(
                        ['mother_style_id' => $mother->id, 'style_no' => $row['child']],
                        ['kind' => $row['kind']]
                    );
                }
            }
        });

        return redirect()->route('merchant.styles.index', ['buyer' => $season->buyer_id, 'season' => $season->id])
            ->with('success', $created.' mother style(s) added.');
    }

    public function storeChild(Request $request, MotherStyle $mother)
    {
        $this->authorizeBuyer($request, $mother->buyer_id);

        $data = $request->validate([
            'style_no' => ['required', 'string', 'max:80'],
            'kind' => ['required', 'in:'.implode(',', array_keys(ChildStyle::KINDS))],
            'description' => ['nullable', 'string', 'max:160'],
        ]);

        ChildStyle::firstOrCreate(
            ['mother_style_id' => $mother->id, 'style_no' => trim($data['style_no'])],
            ['kind' => $data['kind'], 'description' => $data['description'] ?? null]
        );

        return redirect()->route('merchant.styles.index', ['buyer' => $mother->buyer_id, 'season' => $mother->buyer_season_id])
            ->with('success', 'Child style added.');
    }

    public function destroyMother(Request $request, MotherStyle $mother)
    {
        $this->authorizeBuyer($request, $mother->buyer_id);
        $mother->delete();

        return back()->with('success', 'Mother style deleted.');
    }

    public function destroyChild(Request $request, ChildStyle $child)
    {
        $this->authorizeBuyer($request, $child->mother->buyer_id);
        $child->delete();

        return back()->with('success', 'Child style deleted.');
    }

    private function authorizeBuyer(Request $request, int $buyerId): void
    {
        abort_unless($request->user()->hasBuyerAccess($buyerId), 403);
    }
}
