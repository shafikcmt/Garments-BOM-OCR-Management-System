<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The Supply Chain dashboard's figures: where every material line stands from
 * booking to arrival, for the buyers a user may see.
 *
 * Built on StyleRollup (the reading behind Style Status and the Merchandising
 * dashboard), so a number here matches the page it relates to. On top of its
 * stages it reads the Supply Chain columns of the BOM workspace:
 *
 *   Booking   Material PO Number, PO Date, Order to be placed by
 *   PI        PI Status
 *   Payment   Payment Status, Payment Req'd Date
 *   Shipment  Committed ETD, ATD, Committed ETA, ATA
 *
 * A material line is:
 *   Booking pending   no PO yet           (late once its order-by date has passed)
 *   PI pending        PO placed, PI not received
 *   Payment pending   PI received, not paid
 *   In transit        ATD recorded, ATA not
 *   Arrival overdue   not arrived and Committed ETA has passed
 * "Arrived" means an ATA, or goods already received in store.
 */
class SupplyChainDashboard
{
    public const STAGES = ['Not ordered', 'Ordered', 'PI received', 'Paid', 'Received', 'Issued'];

    public const SOON_DAYS = 7;

    public const LIST_SIZE = 8;

    /** Workspace columns read besides StyleRollup's own, by canonical header key. */
    private const EXTRA = [
        'po_date', 'order_to_be_placed_by', 'committed_etd', 'atd', 'committed_eta', 'ata',
        'payment_reqd_date',
    ];

    public function __construct(private StyleRollup $rollup, private BookingPoSourceService $source)
    {
    }

    /**
     * @param  list<int>|null  $buyerIds  null = every buyer
     * @param  bool  $includeUntagged  keep order lines with no buyer (a confined viewer)
     */
    public function build(?array $buyerIds, bool $includeUntagged = false, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $data = $this->rollup->forScope($buyerIds, null, self::EXTRA, $today, $includeUntagged);

        /** @var Collection $orderLines */
        $orderLines = $data['orderLines'];
        $lineById = $orderLines->keyBy('id');
        $soonEnd = $today->copy()->addDays(self::SOON_DAYS);

        $rows = $data['materials']->map(fn (array $m) => $this->decorate($m, $lineById->get($m['order_line_id']), $today, $soonEnd))->values();

        $count = fn (callable $test) => $rows->filter($test)->count();
        $materials = $rows->count();

        $kpis = [
            'orders' => $orderLines->count(),
            'styles' => $orderLines->pluck('mother_style_id')->filter()->unique()->count(),
            'order_qty' => (float) $orderLines->sum('order_qty'),
            'materials' => $materials,
            'booking_pending' => $count(fn ($r) => ! $r['is_ordered']),
            'booking_late' => $count(fn ($r) => $r['light'] === 'red'),
            'booking_due_soon' => $count(fn ($r) => $r['light'] === 'amber'),
            'pi_pending' => $count(fn ($r) => $r['is_ordered'] && ! $r['is_pi_received']),
            'payment_pending' => $count(fn ($r) => $r['is_pi_received'] && ! $r['is_paid']),
            'payment_overdue' => $count(fn ($r) => $r['payment_overdue']),
            'in_transit' => $count(fn ($r) => $r['in_transit']),
            'eta_overdue' => $count(fn ($r) => $r['eta_overdue']),
            'eta_soon' => $count(fn ($r) => $r['eta_soon']),
            'arrived' => $count(fn ($r) => $r['is_ordered'] && $r['arrived']),
        ];

        return [
            'hasData' => $orderLines->isNotEmpty(),
            'kpis' => $kpis,
            'progress' => [
                'Booked' => $this->pct($kpis['materials'] - $kpis['booking_pending'], $materials),
                'PI received' => $this->pct($count(fn ($r) => $r['is_pi_received']), $materials),
                'Paid' => $this->pct($count(fn ($r) => $r['is_paid']), $materials),
                'Received' => $this->pct($count(fn ($r) => $r['is_received']), $materials),
            ],
            'pipeline' => collect(self::STAGES)->mapWithKeys(fn ($s) => [$s => $rows->where('stage', $s)->count()])->all(),
            'byBuyer' => $this->byBuyer($rows),
            'etaMonths' => $this->etaMonths($rows, $today),
            'byVendor' => $this->byVendor($rows),
            'lateBooking' => $this->lateBooking($rows, $today),
            'piPending' => $this->piPending($rows, $today),
            'arrivalOverdue' => $this->arrivalOverdue($rows, $today),
        ];
    }

    /** A material row with its dates read and the shipment / payment flags worked out. */
    private function decorate(array $m, $line, Carbon $today, Carbon $soonEnd): array
    {
        $x = $m['extra'];
        $date = fn (string $key) => $this->source->dateValue($x[$key] ?? null)?->startOfDay();

        $eta = $date('committed_eta');
        $ata = $date('ata');
        $atd = $date('atd');
        $reqd = $date('payment_reqd_date');

        $arrived = $ata !== null || $m['is_received'];
        $open = $m['is_ordered'] && ! $arrived;
        $payOpen = $m['is_pi_received'] && ! $m['is_paid'];

        return $m + [
            'style' => $line?->motherStyle?->style_no ?: $line?->style_no,
            'buyer' => $line?->buyer?->buyer_name ?: 'Unassigned',
            'po_date' => $date('po_date'),
            'order_by' => $date('order_to_be_placed_by'),
            'etd' => $date('committed_etd'),
            'atd' => $atd,
            'eta' => $eta,
            'ata' => $ata,
            'payment_reqd' => $reqd,
            'arrived' => $arrived,
            'in_transit' => $open && $atd !== null,
            'eta_overdue' => $open && $eta !== null && $eta->lt($today),
            'eta_soon' => $open && $eta !== null && $eta->betweenIncluded($today, $soonEnd),
            'payment_overdue' => $payOpen && $reqd !== null && $reqd->lt($today),
            'payment_soon' => $payOpen && $reqd !== null && $reqd->betweenIncluded($today, $soonEnd),
        ];
    }

    /** Open work per buyer: booking, PI and payment pending lines; biggest first, tail folded into "Others". */
    private function byBuyer(Collection $rows): array
    {
        $per = $rows->groupBy('buyer')->map(fn (Collection $g) => [
            'Booking pending' => $g->where('is_ordered', false)->count(),
            'PI pending' => $g->filter(fn ($r) => $r['is_ordered'] && ! $r['is_pi_received'])->count(),
            'Payment pending' => $g->filter(fn ($r) => $r['is_pi_received'] && ! $r['is_paid'])->count(),
        ])->filter(fn ($s) => array_sum($s) > 0)->sortByDesc(fn ($s) => array_sum($s));

        $top = $per->take(6);
        $rest = $per->slice(6);

        if ($rest->isNotEmpty()) {
            $top->put('Others', collect(['Booking pending', 'PI pending', 'Payment pending'])
                ->mapWithKeys(fn ($k) => [$k => $rest->sum($k)])->all());
        }

        return [
            'labels' => $top->keys()->values()->all(),
            'booking' => $top->pluck('Booking pending')->values()->all(),
            'pi' => $top->pluck('PI pending')->values()->all(),
            'payment' => $top->pluck('Payment pending')->values()->all(),
        ];
    }

    /** Lines still to arrive, by Committed ETA month: the overdue ones first, then this month and the next five. */
    private function etaMonths(Collection $rows, Carbon $today): array
    {
        $open = $rows->filter(fn ($r) => $r['is_ordered'] && ! $r['arrived'] && $r['eta'] !== null);
        $months = collect(range(0, 5))->map(fn ($i) => $today->copy()->startOfMonth()->addMonths($i));

        return [
            'labels' => array_merge(['Overdue'], $months->map(fn (Carbon $m) => $m->format('M Y'))->all()),
            'values' => array_merge(
                [$open->filter(fn ($r) => $r['eta']->lt($today))->count()],
                $months->map(fn (Carbon $m) => $open->filter(fn ($r) => $r['eta']->gte($today) && $r['eta']->isSameMonth($m))->count())->all(),
            ),
        ];
    }

    /** Lines waiting on a vendor (ordered, not yet received), by vendor and how far each has got. */
    private function byVendor(Collection $rows): array
    {
        $waiting = $rows->filter(fn ($r) => $r['is_ordered'] && ! $r['is_received']);

        $per = $waiting->groupBy(fn ($r) => $r['vendor'] ?: 'No vendor')->map(fn (Collection $g) => [
            'PI pending' => $g->where('is_pi_received', false)->count(),
            'Payment pending' => $g->filter(fn ($r) => $r['is_pi_received'] && ! $r['is_paid'])->count(),
            'Paid, goods awaited' => $g->where('is_paid', true)->count(),
        ])->sortByDesc(fn ($s) => array_sum($s))->take(8);

        return [
            'labels' => $per->keys()->values()->all(),
            'pi' => $per->pluck('PI pending')->values()->all(),
            'payment' => $per->pluck('Payment pending')->values()->all(),
            'paid' => $per->pluck('Paid, goods awaited')->values()->all(),
        ];
    }

    /** Booking past its order-by date, the longest overdue first. */
    private function lateBooking(Collection $rows, Carbon $today): Collection
    {
        return $rows->filter(fn ($r) => $r['light'] === 'red' && $r['order_by'] !== null)
            ->sortBy(fn ($r) => $r['order_by']->timestamp)
            ->take(self::LIST_SIZE)
            ->map(fn ($r) => [
                'style' => $r['style'], 'buyer' => $r['buyer'], 'material' => $r['description'] ?: $r['material_type'],
                'date' => $r['order_by'], 'days' => (int) $r['order_by']->diffInDays($today),
            ])->values();
    }

    /** PO placed, PI not received: the oldest PO first. */
    private function piPending(Collection $rows, Carbon $today): Collection
    {
        return $rows->filter(fn ($r) => $r['is_ordered'] && ! $r['is_pi_received'])
            ->sortBy(fn ($r) => $r['po_date']?->timestamp ?? PHP_INT_MAX)
            ->take(self::LIST_SIZE)
            ->map(fn ($r) => [
                'po' => $r['po_no'], 'vendor' => $r['vendor'], 'material' => $r['description'] ?: $r['material_type'],
                'date' => $r['po_date'], 'days' => $r['po_date'] ? (int) $r['po_date']->diffInDays($today) : null,
            ])->values();
    }

    /** Not arrived and past Committed ETA: the longest overdue first. */
    private function arrivalOverdue(Collection $rows, Carbon $today): Collection
    {
        return $rows->filter(fn ($r) => $r['eta_overdue'])
            ->sortBy(fn ($r) => $r['eta']->timestamp)
            ->take(self::LIST_SIZE)
            ->map(fn ($r) => [
                'po' => $r['po_no'], 'vendor' => $r['vendor'], 'material' => $r['description'] ?: $r['material_type'],
                'date' => $r['eta'], 'days' => (int) $r['eta']->diffInDays($today), 'in_transit' => $r['in_transit'],
            ])->values();
    }

    private function pct(int|float $part, int|float $whole): int
    {
        return $whole > 0 ? (int) round($part / $whole * 100) : 0;
    }
}
