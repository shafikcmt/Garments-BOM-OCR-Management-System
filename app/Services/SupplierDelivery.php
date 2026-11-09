<?php

namespace App\Services;

use App\Models\BookingPo;
use App\Models\ExcelCell;
use App\Models\MaterialReceiving;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Supplier delivery follow-up: for every Booking PO, the date the supplier
 * promised against what actually arrived, and a scorecard per supplier.
 *
 * THE PROMISED DATE is read from the PO's workspace row: Committed In-house,
 * else Committed ETA, else Committed Ex-mill (the first one filled). NOTHING
 * IS WRITTEN; this reads the workspace and Store's receipts only.
 *
 *   delivered   Actual In-house is filled, or Store has received 98% or more of
 *               the PO quantity (the date it arrived is Actual In-house, else the
 *               last GRN)
 *   on time     delivered on or before the promised date; late = after it
 *   overdue     not delivered and the promised date has passed
 *   no date     not delivered and nobody has entered a promised date
 *   open        not delivered, promised date still ahead
 *
 * A PO with no promised date and already delivered is counted as delivered but
 * not judged (no promise to measure it against).
 */
class SupplierDelivery
{
    public const DELIVERED_SHARE = 0.98;

    /** Canonical column key => label shown as the source of the promised date, in priority order. */
    private const PROMISE = ['committed_inhouse' => 'Committed in-house', 'committed_eta' => 'Committed ETA', 'committed_ex_mill' => 'Committed ex-mill'];

    public function __construct(private HeaderAliasResolver $headers, private BookingPoSourceService $source)
    {
    }

    /**
     * @param  Collection<int, BookingPo>  $pos
     * @return array{lines: Collection, suppliers: Collection, kpis: array<string, mixed>}
     */
    public function build(Collection $pos, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $pos = $pos->filter(fn (BookingPo $p) => $p->excel_row_id)->values();
        $rowIds = $pos->pluck('excel_row_id')->unique()->values()->all();

        $headerIds = [];
        foreach (array_merge(array_keys(self::PROMISE), ['actual_inhouse']) as $key) {
            foreach ($this->headers->headerIdsForCanonical($key) as $id) {
                $headerIds[$id] = $key;
            }
        }

        // cells[row][canonical] = first non-empty value
        $cells = [];
        if ($headerIds !== []) {
            foreach (array_chunk($rowIds, 900) as $chunk) {
                ExcelCell::whereIn('row_id', $chunk)->whereIn('header_id', array_keys($headerIds))->get(['row_id', 'header_id', 'value'])
                    ->each(function ($c) use (&$cells, $headerIds) {
                        $key = $headerIds[$c->header_id];
                        if (trim((string) $c->value) !== '' && ! isset($cells[$c->row_id][$key])) {
                            $cells[$c->row_id][$key] = $c->value;
                        }
                    });
            }
        }

        $receipts = collect();
        foreach (array_chunk($rowIds, 900) as $chunk) {
            $receipts = $receipts->merge(MaterialReceiving::whereIn('excel_row_id', $chunk)->get(['excel_row_id', 'qty', 'receive_date']));
        }
        $receiptsByRow = $receipts->groupBy('excel_row_id');

        $lines = $pos->map(function (BookingPo $po) use ($cells, $receiptsByRow, $today) {
            $row = $cells[$po->excel_row_id] ?? [];
            $promised = null;
            $basis = null;
            foreach (self::PROMISE as $key => $label) {
                if (isset($row[$key]) && ($d = $this->source->dateValue($row[$key]))) {
                    $promised = $d->startOfDay();
                    $basis = $label;
                    break;
                }
            }

            $rec = $receiptsByRow->get($po->excel_row_id, collect());
            $received = (float) $rec->sum('qty');
            $qty = (float) $po->qty;
            $actual = isset($row['actual_inhouse']) ? $this->source->dateValue($row['actual_inhouse'])?->startOfDay() : null;
            $lastReceipt = $rec->pluck('receive_date')->filter()->max();
            $deliveredOn = $actual ?? ($lastReceipt ? Carbon::parse($lastReceipt)->startOfDay() : null);
            $delivered = $actual !== null || ($qty > 0 && $received >= $qty * self::DELIVERED_SHARE);

            $status = match (true) {
                $delivered && $promised === null => 'delivered',
                $delivered && $deliveredOn !== null && $deliveredOn->gt($promised) => 'delivered_late',
                $delivered => 'on_time',
                $promised === null => 'no_date',
                $promised->lt($today) => 'overdue',
                default => 'open',
            };

            return [
                'po' => $po, 'vendor' => trim((string) $po->vendor_name) ?: 'No vendor', 'promised' => $promised, 'basis' => $basis,
                'received' => $received, 'qty' => $qty, 'delivered_on' => $delivered ? $deliveredOn : null, 'status' => $status,
                'days_late' => match ($status) {
                    'overdue' => (int) $promised->diffInDays($today),
                    'delivered_late' => (int) $promised->diffInDays($deliveredOn),
                    default => 0,
                },
                'partial' => ! $delivered && $received > 0,
            ];
        });

        $suppliers = $lines->groupBy(fn ($l) => mb_strtolower($l['vendor']))->map(function (Collection $g) {
            $judged = $g->whereIn('status', ['on_time', 'delivered_late']);
            $lateOnes = $g->where('status', 'delivered_late');

            return [
                'vendor' => $g->first()['vendor'],
                'lines' => $g->count(),
                'delivered' => $g->whereIn('status', ['delivered', 'on_time', 'delivered_late'])->count(),
                'overdue' => $g->where('status', 'overdue')->count(),
                'no_date' => $g->where('status', 'no_date')->count(),
                'open' => $g->where('status', 'open')->count(),
                'on_time_rate' => $judged->isEmpty() ? null : (int) round($judged->where('status', 'on_time')->count() * 100 / $judged->count()),
                'avg_days_late' => $lateOnes->isEmpty() ? null : round($lateOnes->avg('days_late'), 1),
                'max_overdue' => (int) $g->where('status', 'overdue')->max('days_late'),
            ];
        })->sortBy([['overdue', 'desc'], ['no_date', 'desc'], ['vendor', 'asc']])->values();

        $judged = $lines->whereIn('status', ['on_time', 'delivered_late']);

        return [
            'lines' => $lines,
            'suppliers' => $suppliers,
            'kpis' => [
                'lines' => $lines->count(),
                'overdue' => $lines->where('status', 'overdue')->count(),
                'no_date' => $lines->where('status', 'no_date')->count(),
                'open' => $lines->where('status', 'open')->count(),
                'on_time_rate' => $judged->isEmpty() ? null : (int) round($judged->where('status', 'on_time')->count() * 100 / $judged->count()),
                'suppliers' => $suppliers->count(),
            ],
        ];
    }
}
