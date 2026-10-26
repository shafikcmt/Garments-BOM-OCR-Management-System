<?php

namespace App\Services;

use App\Models\BomApproval;
use App\Models\FollowUpTask;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The Merchandising dashboard's figures, for the buyers a user may see.
 *
 * Orders and the material pipeline come from StyleRollup — the same reading
 * the Style and Season status pages use — so a number here always matches the
 * page it links to. A material line's stage:
 *
 *   Booking pending  no PO yet            (late: its order-by date has passed)
 *   PI pending       PO placed, no PI received
 *   Payment pending  PI received, payment not done
 *
 * PRA counts are added by the controller, which owns the PRA buyer scope.
 */
class MerchantDashboard
{
    /** Stages in pipeline order, as StyleRollup names them. */
    public const STAGES = ['Not ordered', 'Ordered', 'PI received', 'Paid', 'Received', 'Issued'];

    public const UPCOMING_DAYS = 30;

    public function __construct(private StyleRollup $rollup)
    {
    }

    /**
     * @param  list<int>|null  $buyerIds  null = every buyer
     */
    public function build(User $user, ?array $buyerIds, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $data = $this->rollup->forBuyers($buyerIds, $today);

        /** @var Collection $orderLines */
        $orderLines = $data['orderLines'];
        $materials = $data['materials'];
        $kpis = $data['kpis'];
        $lineById = $orderLines->keyBy('id');

        $upcomingEnd = $today->copy()->addDays(self::UPCOMING_DAYS);

        return [
            'hasOrders' => $orderLines->isNotEmpty(),
            'kpis' => [
                'orders' => $kpis['order_lines'],
                'customer_pos' => $orderLines->pluck('customer_po_no')->filter()->unique()->count(),
                'styles' => $orderLines->pluck('mother_style_id')->filter()->unique()->count(),
                'order_qty' => $kpis['order_qty'],
                'materials' => $kpis['materials'],
                'booking_pending' => $materials->where('is_ordered', false)->count(),
                'booking_late' => $kpis['late'],
                'booking_due_soon' => $kpis['due_soon'],
                'pi_pending' => $materials->filter(fn ($m) => $m['is_ordered'] && ! $m['is_pi_received'])->count(),
                'payment_pending' => $materials->filter(fn ($m) => $m['is_pi_received'] && ! $m['is_paid'])->count(),
                'received' => $kpis['received'],
                'shipping_soon' => $orderLines->filter(fn ($l) => $l->ship_date && $l->ship_date->betweenIncluded($today, $upcomingEnd))->count(),
                'bom_waiting' => $this->bomApprovalsPending($buyerIds),
                'followups_due' => FollowUpTask::open()->where('assigned_to', $user->id)
                    ->whereDate('due_date', '<=', $today->toDateString())->count(),
            ],
            'pipeline' => collect(self::STAGES)->mapWithKeys(fn ($s) => [$s => $materials->where('stage', $s)->count()])->all(),
            'byBuyer' => $this->byBuyer($orderLines),
            'shipMonths' => $this->shipMonths($orderLines, $today),
            'upcoming' => $this->upcoming($data['lines'], $lineById, $today, $upcomingEnd),
            'attention' => $this->attention($materials, $lineById),
        ];
    }

    /** BOM approvals still pending for these buyers' styles. */
    private function bomApprovalsPending(?array $buyerIds): int
    {
        return BomApproval::where('status', BomApproval::PENDING)
            ->when($buyerIds !== null, fn ($q) => $q->whereHas('motherStyle', fn ($m) => $m->whereIn('buyer_id', $buyerIds)))
            ->count();
    }

    /** Order quantity per buyer, biggest first; the tail folded into "Others". */
    private function byBuyer(Collection $orderLines): array
    {
        $totals = $orderLines->groupBy(fn ($l) => $l->buyer?->buyer_name ?: 'Unassigned')
            ->map(fn ($g) => (float) $g->sum('order_qty'))
            ->filter(fn ($qty) => $qty > 0)
            ->sortDesc();

        $top = $totals->take(6);
        $rest = $totals->slice(6)->sum();

        if ($rest > 0) {
            $top->put('Others', $rest);
        }

        return ['labels' => $top->keys()->values()->all(), 'values' => $top->values()->all()];
    }

    /** Order quantity by ship month: this month and the next five. */
    private function shipMonths(Collection $orderLines, Carbon $today): array
    {
        $months = collect(range(0, 5))->map(fn ($i) => $today->copy()->startOfMonth()->addMonths($i));

        return [
            'labels' => $months->map(fn (Carbon $m) => $m->format('M Y'))->all(),
            'values' => $months->map(fn (Carbon $m) => (float) $orderLines
                ->filter(fn ($l) => $l->ship_date && $l->ship_date->isSameMonth($m))
                ->sum('order_qty'))->all(),
        ];
    }

    /** Order lines shipping in the next UPCOMING_DAYS, soonest first. */
    private function upcoming(Collection $lineRows, Collection $lineById, Carbon $today, Carbon $end): Collection
    {
        return $lineRows
            ->filter(fn ($r) => $r['ship_date'] && $r['ship_date']->betweenIncluded($today, $end))
            ->sortBy(fn ($r) => $r['ship_date']->timestamp)
            ->take(8)
            ->map(function ($r) use ($lineById, $today) {
                $line = $lineById->get($r['id']);

                return $r + [
                    'style' => $line?->motherStyle?->style_no ?: $line?->style_no,
                    'mother_id' => $line?->mother_style_id,
                    'buyer' => $line?->buyer?->buyer_name,
                    'days_left' => (int) $today->diffInDays($r['ship_date']),
                    'ready_pct' => $r['materials'] > 0 ? (int) round($r['received'] / $r['materials'] * 100) : null,
                ];
            })
            ->values();
    }

    /** Styles with material past its order-by date, most late first. */
    private function attention(Collection $materials, Collection $lineById): Collection
    {
        return $materials->where('light', 'red')
            ->groupBy(fn ($m) => $lineById->get($m['order_line_id'])?->mother_style_id ?: 0)
            ->map(function ($group, $motherId) use ($lineById) {
                $line = $lineById->get($group->first()['order_line_id']);

                return [
                    'mother_id' => $motherId ?: null,
                    'style' => $line?->motherStyle?->style_no ?: $line?->style_no,
                    'buyer' => $line?->buyer?->buyer_name,
                    'late' => $group->count(),
                ];
            })
            ->sortByDesc('late')
            ->take(6)
            ->values();
    }
}
