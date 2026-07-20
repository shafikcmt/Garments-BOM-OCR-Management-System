<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\ExcelRow;
use App\Models\PaymentRequest;
use App\Services\DashboardMetricsService;

/**
 * Accounts works inside the shared BOM workspace and against the payment
 * request queue, so the dashboard reports on both: this role's share of the
 * sheet, and where PRAs currently stand.
 */
class DashboardController extends Controller
{
    public function index(DashboardMetricsService $metrics)
    {
        $workspace = $metrics->workspaceCompletionFor('account');

        $trend = $metrics->monthlyTrend(PaymentRequest::query());
        $delta = $metrics->deltaFor($trend);

        $stats = [
            'rows' => ExcelRow::count(),
            'pra_total' => PaymentRequest::count(),
            'pra_pending' => PaymentRequest::whereIn('status', [
                PaymentRequest::STATUS_PENDING_CHECK,
                PaymentRequest::STATUS_PENDING_APPROVAL,
            ])->count(),
            'pra_approved' => PaymentRequest::where('status', PaymentRequest::STATUS_APPROVED)->count(),
        ];

        return view('account.dashboard', compact('stats', 'workspace', 'trend', 'delta'));
    }
}
