<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\BuyerSeason;
use App\Models\ChildStyle;
use App\Models\MotherStyle;
use App\Models\OrderLine;
use App\Models\FollowUpTask;
use App\Services\BomApprovals;
use App\Services\Costings;
use App\Services\FollowUps;
use App\Services\StyleRollup;
use App\Support\StyleCodeParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Buyer -> Season -> Mother Style -> Child Style.
 *
 * The merchant's one place for the style master: one standard structure for every
 * buyer, so any buyer's Excel can be matched to it by an import profile. Buyer scoping follows
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
            ? $buyer->seasons()->with(['motherStyles' => fn ($q) => $q->withCount('orderLines')->with('children')])->get()
            : collect();

        $season = $seasons->firstWhere('id', (int) $request->query('season')) ?? $seasons->first();

        $totals = [
            'buyers' => $buyers->count(),
            'seasons' => (int) $buyers->sum('seasons_count'),
            'mothers' => (int) $buyers->sum('mother_styles_count'),
            'children' => ChildStyle::whereHas('mother', fn ($q) => $q->whereIn('buyer_id', $buyers->pluck('id')))->count(),
        ];

        $stats = $season ? $this->styleStats($season->motherStyles) : [];

        return view('merchant.styles', compact('buyers', 'buyer', 'seasons', 'season', 'totals', 'stats'));
    }

    /**
     * The standard style-list figures of each style of a season, read in a few
     * queries for the whole list: colours, customer POs, order quantity, ship
     * date, material lines, final costing margin and samples. A colour / PO in two
     * files counts once (the largest quantity).
     *
     * @param  \Illuminate\Support\Collection<int, MotherStyle>  $mothers
     * @return array<int, array<string, mixed>>
     */
    private function styleStats($mothers): array
    {
        if ($mothers->isEmpty()) {
            return [];
        }
        $ids = $mothers->pluck('id');
        $today = \Illuminate\Support\Carbon::today();

        $lines = OrderLine::whereIn('mother_style_id', $ids)->get(['mother_style_id', 'gmts_color', 'customer_po_no', 'order_qty', 'ship_date'])->groupBy('mother_style_id');
        $materials = \Illuminate\Support\Facades\DB::table('bom_items')->join('order_lines', 'order_lines.id', '=', 'bom_items.order_line_id')
            ->whereIn('order_lines.mother_style_id', $ids)->selectRaw('order_lines.mother_style_id as mid, count(*) as n')->groupBy('order_lines.mother_style_id')->pluck('n', 'mid');
        $costings = app(Costings::class)->latestFinal($mothers);
        $samples = \App\Models\Sample::whereIn('mother_style_id', $ids)->get(['mother_style_id', 'status', 'required_date'])->groupBy('mother_style_id');
        $norm = fn ($v) => mb_strtolower(trim((string) $v));

        $out = [];
        foreach ($mothers as $m) {
            $l = $lines->get($m->id, collect());
            $dates = $l->pluck('ship_date')->filter();
            $upcoming = $dates->filter(fn ($d) => $d->gte($today))->min();
            $open = ($samples->get($m->id) ?? collect())->filter(fn ($s) => ! in_array($s->status, \App\Models\Sample::CLOSED, true));
            $costing = $costings->get($m->id);

            $out[$m->id] = [
                'colours' => $l->pluck('gmts_color')->map($norm)->filter()->unique()->count(),
                'pos' => $l->pluck('customer_po_no')->map($norm)->filter()->unique()->count(),
                'qty' => (float) $l->groupBy(fn ($x) => $norm($x->gmts_color) . '|' . $norm($x->customer_po_no))->sum(fn ($g) => (float) $g->max('order_qty')),
                'ship' => $upcoming ?? $dates->max(),
                'ship_passed' => $upcoming === null && $dates->isNotEmpty(),
                'materials' => (int) ($materials[$m->id] ?? 0),
                'margin' => $costing?->marginPct(),
                'has_costing' => $costing !== null,
                'open_samples' => $open->count(),
                'late_samples' => $open->filter(fn ($s) => $s->required_date && $s->required_date->lt($today))->count(),
            ];
        }

        return $out;
    }

    /** Where one mother style stands: orders, materials, PO, PI, payment, receipt, issue. */
    public function show(Request $request, MotherStyle $mother, StyleRollup $rollup, FollowUps $followUps, BomApprovals $approvals, Costings $costings)
    {
        $this->authorizeBuyer($request, $mother->buyer_id);

        $mother->load(['buyer', 'season']);
        $childId = $request->integer('child') ?: null;
        $data = $rollup->forMother($mother, $childId);

        // Admin / Management reach this page read-only from the status views and
        // have no Style Library to go back to.
        $isMerchant = $request->user()->hasRole('merchant');

        // Pre-costing: the latest sheet of each mode, with its summary.
        $costingCards = collect(array_keys(\App\Models\Costing::MODES))->mapWithKeys(function ($m) use ($mother, $costings) {
            $latest = $mother->costings()->where('mode', $m)->orderByDesc('revision')->first();

            return [$m => $latest ? ['costing' => $latest, 'summary' => $costings->summary($latest)] : null];
        });

        // BOM approval: where this style's BOM stands, and what this person may do.
        $bomApproval = $mother->bomApproval()->with(['steps.user:id,name', 'submitter:id,name'])->first();
        $bomState = $approvals->state($mother, $bomApproval);
        $canSubmitBom = $request->user()->hasAnyRole(['merchant', 'admin']);
        $canDecideBom = $bomApproval && $approvals->canDecide($request->user(), $bomApproval);

        // Follow-up (Time and Action): who has been asked to chase this style.
        $canFollowUp = $request->user()->hasAnyRole(['merchant', 'admin', 'management']);
        // TnA tasks have their own box (TnA Plan), so they are not listed twice.
        $followUpTasks = FollowUpTask::open()->where('mother_style_id', $mother->id)->where('source', '!=', \App\Services\TnaPlans::SOURCE)
            ->with('assignee:id,name')->orderBy('due_date')->get();
        $assignees = $canFollowUp ? $followUps->assigneesFor($mother) : collect();

        $tnaTasks = FollowUpTask::where('mother_style_id', $mother->id)->where('source', \App\Services\TnaPlans::SOURCE)
            ->with('assignee:id,name')->orderBy('due_date')->get();
        $criticalPath = app(\App\Services\TnaPlans::class)->criticalPath($tnaTasks);

        return view('merchant.style-show', [
            'mother' => $mother,
            'childId' => $childId,
            'showRoute' => $isMerchant ? 'merchant.styles.mothers.show' : 'merchant.status.style',
            'backUrl' => $isMerchant
                ? route('merchant.styles.index', ['buyer' => $mother->buyer_id, 'season' => $mother->buyer_season_id])
                : route('merchant.status.season', $mother->buyer_season_id),
            'backLabel' => $isMerchant ? 'Back to Style Library' : 'Back to Season Status',
            'homeUrl' => $isMerchant ? route('merchant.dashboard') : route('merchant.status.index'),
            'costingCards' => $costingCards,
            // Who changed the order (qty, ship date, PO ...) and when.
            'orderChanges' => app(\App\Services\OrderChanges::class)->forMother($mother, 50),
            // TnA: templates that may be applied, and the tasks already made from them.
            'documents' => $mother->documents()->with('uploader:id,name')->get(),
            'samples' => $mother->samples()->with(['type', 'childStyle', 'logs'])->orderByRaw('case when required_date is null then 1 else 0 end')->orderBy('required_date')->get(),
            'sampleTypes' => \App\Models\SampleType::active()->get(),
            'tnaTemplates' => \App\Models\TnaTemplate::forBuyer($mother->buyer_id)->orderBy('name')->get(),
            'tnaTasks' => $tnaTasks,
            'criticalPath' => $criticalPath,
            'bomApproval' => $bomApproval,
            'bomState' => $bomState,
            'canSubmitBom' => $canSubmitBom,
            'canDecideBom' => $canDecideBom,
            'canFollowUp' => $canFollowUp,
            'followUpTasks' => $followUpTasks,
            'assignees' => $assignees,
        ] + $data);
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
