<?php

namespace App\Services;

use App\Models\GsComparativeStatement;
use App\Models\GsPurchaseOrder;
use Illuminate\Support\Carbon;

/**
 * Figures for the General Stock dashboard's purchasing panel: what is waiting
 * to be bought, where each Comparative Statement and Internal PO stands, and
 * how much was ordered per month. Read-only; every number comes from the same
 * tables and services the purchasing screens use, so the dashboard cannot
 * disagree with the lists it links to.
 */
class PurchasingDashboardService
{
    public function __construct(private readonly PurchaseQueueService $queue)
    {
    }

    /** @return array<string, mixed> */
    public function summary(int $months = 6): array
    {
        $waiting = $this->queue->lines();

        $csCounts = GsComparativeStatement::query()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
        $poCounts = GsPurchaseOrder::query()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');

        // Ordered value per month, from authorised POs onwards (a draft is not yet an order).
        $from = Carbon::now()->startOfMonth()->subMonths($months - 1);
        $orders = GsPurchaseOrder::query()
            ->whereNotIn('status', [GsPurchaseOrder::STATUS_DRAFT, GsPurchaseOrder::STATUS_CHECKED, GsPurchaseOrder::STATUS_CANCELLED])
            ->where('po_date', '>=', $from->toDateString())
            ->get(['po_date', 'total_amount'])
            ->groupBy(fn ($o) => $o->po_date->format('Y-m'))
            ->map(fn ($rows) => (float) $rows->sum('total_amount'));

        $trend = [];
        for ($i = 0; $i < $months; $i++) {
            $m = $from->copy()->addMonths($i);
            $trend[] = ['label' => $m->format('M'), 'value' => round($orders[$m->format('Y-m')] ?? 0.0, 2)];
        }

        return [
            'waiting_items' => $waiting->count(),
            'waiting_by_category' => $waiting->groupBy('category_name')->map->count()->sortDesc()->take(8)->all(),
            'cs' => [
                'draft' => (int) ($csCounts[GsComparativeStatement::STATUS_DRAFT] ?? 0),
                'to_check' => (int) ($csCounts[GsComparativeStatement::STATUS_SUBMITTED] ?? 0),
                'to_approve' => (int) ($csCounts[GsComparativeStatement::STATUS_CHECKED] ?? 0),
                'approved' => (int) ($csCounts[GsComparativeStatement::STATUS_APPROVED] ?? 0),
            ],
            'po' => [
                'draft' => (int) ($poCounts[GsPurchaseOrder::STATUS_DRAFT] ?? 0),
                'to_authorise' => (int) ($poCounts[GsPurchaseOrder::STATUS_CHECKED] ?? 0),
                'issued' => (int) ($poCounts[GsPurchaseOrder::STATUS_ISSUED] ?? 0),
                'partial' => (int) ($poCounts[GsPurchaseOrder::STATUS_PARTIAL] ?? 0),
                'received' => (int) ($poCounts[GsPurchaseOrder::STATUS_RECEIVED] ?? 0),
            ],
            // Approved statements that have no purchase order yet: work for the desk.
            'cs_without_po' => GsComparativeStatement::query()->where('status', GsComparativeStatement::STATUS_APPROVED)->whereDoesntHave('purchaseOrders')->count(),
            'trend' => $trend,
            'ordered_this_month' => (float) end($trend)['value'],
            'recent_orders' => GsPurchaseOrder::query()->latest('id')->take(6)->get(['id', 'po_no', 'supplier_name', 'status', 'total_amount', 'po_date']),
        ];
    }
}
