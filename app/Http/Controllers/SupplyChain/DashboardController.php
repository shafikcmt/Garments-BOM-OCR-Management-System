<?php

namespace App\Http\Controllers\SupplyChain;

use App\Http\Controllers\Controller;
use App\Models\BookingPo;
use App\Models\EmailLog;
use App\Models\PaymentRequest;
use App\Services\DashboardMetricsService;

class DashboardController extends Controller
{
    public function index(DashboardMetricsService $metrics)
    {
        $workspace = $metrics->workspaceCompletionFor('supply_chain');

        $trend = $metrics->monthlyTrend(BookingPo::query());
        $delta = $metrics->deltaFor($trend);

        $stats = [
            'pos' => BookingPo::count(),
            'pra_pending' => PaymentRequest::whereIn('status', [
                PaymentRequest::STATUS_PENDING_CHECK,
                PaymentRequest::STATUS_PENDING_APPROVAL,
            ])->count(),
            'pra_approved' => PaymentRequest::where('status', PaymentRequest::STATUS_APPROVED)->count(),
            'emails' => EmailLog::count(),
        ];

        return view('supply-chain.dashboard', compact('stats', 'workspace', 'trend', 'delta'));
    }
}
