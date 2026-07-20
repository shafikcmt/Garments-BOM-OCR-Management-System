<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\PaymentRequest;
use App\Models\PraApproval;

class DashboardController extends Controller
{
    public function index()
    {
        $userId = auth()->id();

        $stats = [
            'pending' => PaymentRequest::whereIn('status', [PaymentRequest::STATUS_PENDING_CHECK, PaymentRequest::STATUS_PENDING_APPROVAL])->count(),
            'approved' => PaymentRequest::where('status', PaymentRequest::STATUS_APPROVED)->count(),
            'rejected' => PaymentRequest::where('status', PaymentRequest::STATUS_REJECTED)->count(),
        ];

        // PRAs currently waiting on this management user specifically, at the
        // stage (check or approve) that is actually active for them right now.
        $myPending = PaymentRequest::whereIn('status', [PaymentRequest::STATUS_PENDING_CHECK, PaymentRequest::STATUS_PENDING_APPROVAL])
            ->whereHas('approvals', fn ($q) => $q->where('approver_id', $userId)->where('status', PraApproval::STATUS_PENDING))
            ->with(['approvals'])
            ->get()
            ->filter(function (PaymentRequest $pr) use ($userId) {
                if ($pr->status === PaymentRequest::STATUS_PENDING_CHECK) {
                    $check = $pr->currentCheckApproval();

                    return $check && $check->approver_id === $userId && $check->isPending();
                }

                return $pr->currentApproveApprovals()
                    ->first(fn (PraApproval $a) => $a->approver_id === $userId && $a->isPending()) !== null;
            })
            ->count();

        $recentActivity = PraApproval::whereNotNull('acted_at')
            ->with(['paymentRequest', 'approver'])
            ->latest('acted_at')
            ->limit(8)
            ->get();

        // 'draft' is a real status in the data but PaymentRequest declares no
        // constant for it, unlike the other four — hence the literal.
        $stats['draft'] = PaymentRequest::where('status', 'draft')->count();
        $stats['total'] = PaymentRequest::count();

        $trend = $this->monthlyTrend();

        // Month-on-month change, only where last month actually had something to
        // compare against — a percentage against zero is noise, and this screen
        // is read by people making decisions on it.
        $thisMonth = end($trend)['value'];
        $lastMonth = count($trend) > 1 ? $trend[count($trend) - 2]['value'] : 0;
        $delta = $lastMonth > 0 ? round((($thisMonth - $lastMonth) / $lastMonth) * 100) : null;

        return view('management.dashboard', compact(
            'stats',
            'myPending',
            'recentActivity',
            'trend',
            'delta'
        ));
    }

    /**
     * PRAs raised per month for the last six months, oldest first, including
     * months with none so the shape of the trend is honest.
     *
     * @return array<int, array{label: string, value: int}>
     */
    private function monthlyTrend(int $months = 6): array
    {
        $start = now()->startOfMonth()->subMonths($months - 1);

        $counts = PaymentRequest::where('created_at', '>=', $start)
            ->get(['created_at'])
            ->groupBy(fn ($pr) => $pr->created_at->format('Y-m'))
            ->map->count();

        $trend = [];
        for ($i = 0; $i < $months; $i++) {
            $month = $start->copy()->addMonths($i);
            $trend[] = [
                'label' => $month->format('M'),
                'value' => (int) ($counts[$month->format('Y-m')] ?? 0),
            ];
        }

        return $trend;
    }
}
