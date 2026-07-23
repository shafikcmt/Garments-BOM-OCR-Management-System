<?php

namespace App\Http\Controllers\SupplyChain;

use App\Http\Controllers\Controller;
use App\Models\BookingPo;
use App\Models\EmailLog;
use App\Models\PaymentRequest;
use App\Services\DashboardMetricsService;
use App\Services\DepartmentActivityService;

class DashboardController extends Controller
{
    public function index(DashboardMetricsService $metrics)
    {
        // Required-column progress for this department only, from the same
        // service the Admin Dashboard reads — so a department and admin
        // never see two different numbers for the same work.
        $activity = app(DepartmentActivityService::class);
        $workspace = $activity->forRole('supply_chain') ?? $activity->emptyProgressFor('supply_chain');

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
