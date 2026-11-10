<?php

namespace App\Services;

use App\Models\ExcelCell;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Where the payable payment requests stand: due, part paid or paid.
 *
 * "Paid" is read from the workspace: a line is paid when its Pmt Doc No is
 * filled, and a request is paid when its payment reference is saved or every
 * line is paid. The Payments screen and the Accounts dashboard both read it
 * from here, so they can never disagree.
 *
 * Amounts are the PI amounts as entered; the system holds no currency on them,
 * so nothing here converts or adds different currencies knowingly.
 */
class PaymentPosition
{
    public function __construct(private HeaderAliasResolver $headers)
    {
    }

    /**
     * Set `paid_lines` and `payment_state` (due | partial | paid) on each request.
     * Expects `items_count` on the requests (withCount('items')).
     *
     * @param  Collection<int, PaymentRequest>  $requests
     */
    public function annotate(Collection $requests): Collection
    {
        $paidLines = $this->paidLineCounts($requests);

        return $requests->each(function (PaymentRequest $pra) use ($paidLines) {
            $paid = $paidLines[$pra->id] ?? 0;
            $pra->setAttribute('paid_lines', $paid);
            $pra->setAttribute('payment_state', match (true) {
                filled($pra->data['payment_reference'] ?? null) || ($pra->items_count > 0 && $paid >= $pra->items_count) => 'paid',
                $paid > 0 => 'partial',
                default => 'due',
            });
        });
    }

    public static function amountOf(PaymentRequest $pra): float
    {
        return (float) ($pra->total_pi_amount ?: ($pra->lines_amount ?? 0));
    }

    /**
     * The figures of the Accounts dashboard, from already scoped payable requests
     * (withCount items, withSum lines_amount, withMin required_date).
     *
     * @param  Collection<int, PaymentRequest>  $payable
     * @return array<string, mixed>
     */
    public function dashboard(Collection $payable, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $this->annotate($payable);

        $due = $payable->where('payment_state', '!=', 'paid');
        $required = fn (PaymentRequest $p) => $p->required_date ? Carbon::parse($p->required_date)->startOfDay() : null;
        $sum = fn (Collection $c) => (float) $c->sum(fn ($p) => self::amountOf($p));

        $overdue = $due->filter(fn ($p) => ($d = $required($p)) && $d->lt($today));
        $weekEnd = $today->copy()->addDays(6);
        $thisWeek = $due->filter(fn ($p) => ($d = $required($p)) && $d->gte($today) && $d->lte($weekEnd));

        // Amount to pay by when it is needed.
        $buckets = ['Overdue' => 0.0, 'This week' => 0.0, 'Next week' => 0.0, 'In 2 weeks' => 0.0, 'In 3 weeks' => 0.0, 'Later' => 0.0, 'No date' => 0.0];
        foreach ($due as $p) {
            $d = $required($p);
            $amount = self::amountOf($p);
            $key = match (true) {
                $d === null => 'No date',
                $d->lt($today) => 'Overdue',
                default => match ((int) floor($today->diffInDays($d) / 7)) {
                    0 => 'This week', 1 => 'Next week', 2 => 'In 2 weeks', 3 => 'In 3 weeks', default => 'Later',
                },
            };
            $buckets[$key] += $amount;
        }

        $suppliers = $due->groupBy(fn ($p) => trim((string) $p->supplier_name) ?: 'No supplier')->map(fn ($g, $name) => [
            'supplier' => $name, 'count' => $g->count(), 'amount' => $sum($g),
            'overdue' => $g->filter(fn ($p) => ($d = $required($p)) && $d->lt($today))->count(),
        ])->sortByDesc('amount')->values();

        return [
            'due_count' => $due->count(), 'due_amount' => $sum($due),
            'overdue_count' => $overdue->count(), 'overdue_amount' => $sum($overdue),
            'week_count' => $thisWeek->count(), 'week_amount' => $sum($thisWeek),
            'paid_count' => $payable->where('payment_state', 'paid')->count(), 'paid_amount' => $sum($payable->where('payment_state', 'paid')),
            'partial_count' => $payable->where('payment_state', 'partial')->count(),
            'due_only_count' => $payable->where('payment_state', 'due')->count(),
            'no_date_count' => $due->filter(fn ($p) => $required($p) === null)->count(),
            'buckets' => $buckets,
            'suppliers' => $suppliers->take(5)->all(),
            'supplier_total' => $suppliers->count(),
            'overdue_list' => $overdue->sortBy(fn ($p) => $required($p))->take(5)->map(fn ($p) => [
                'pra' => $p, 'amount' => self::amountOf($p), 'required' => $required($p), 'days' => (int) $required($p)->diffInDays($today),
            ])->values()->all(),
        ];
    }

    /**
     * Lines with a Pmt Doc No filled in, per PRA.
     *
     * @return array<int, int>
     */
    public function paidLineCounts(Collection $requests): array
    {
        $headerId = $this->headers->resolveHeaderId('pmt_doc_no');

        if (! $headerId || $requests->isEmpty()) {
            return [];
        }

        $rowsByPra = PaymentRequestItem::whereIn('payment_request_id', $requests->pluck('id'))
            ->whereNotNull('excel_row_id')
            ->get(['payment_request_id', 'excel_row_id'])
            ->groupBy('payment_request_id');

        $filled = collect();
        foreach ($rowsByPra->flatten(1)->pluck('excel_row_id')->unique()->chunk(900) as $chunk) {
            $filled = $filled->merge(
                ExcelCell::where('header_id', $headerId)->whereIn('row_id', $chunk->all())->whereNotNull('value')->where('value', '!=', '')->pluck('row_id')
            );
        }
        $filled = $filled->flip();

        return $rowsByPra->map(
            fn ($items) => $items->pluck('excel_row_id')->unique()->filter(fn ($id) => $filled->has($id))->count()
        )->all();
    }
}
