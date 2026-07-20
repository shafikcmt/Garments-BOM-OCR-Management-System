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

        $metrics = app(\App\Services\DashboardMetricsService::class);
        $trend = $metrics->monthlyTrend(PaymentRequest::query());
        $delta = $metrics->deltaFor($trend);

        return view('management.dashboard', compact(
            'stats',
            'myPending',
            'recentActivity',
            'trend',
            'delta'
        ));
    }
}
