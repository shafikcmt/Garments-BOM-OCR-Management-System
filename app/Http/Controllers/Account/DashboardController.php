<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\ExcelRow;
use App\Models\PaymentRequest;
use App\Services\DashboardMetricsService;
use App\Services\DepartmentActivityService;
use App\Services\PaymentPosition;
use App\Support\Concerns\ScopesToAssignedBuyers;

/**
 * Accounts works inside the shared BOM workspace and against the payment
 * request queue, so the dashboard reports on both: this role's share of the
 * sheet, and where PRAs currently stand.
 */
class DashboardController extends Controller
{
    use \App\Http\Controllers\Concerns\ShowsRequisitionSummary;

    use ScopesToAssignedBuyers;

    public function index(DashboardMetricsService $metrics)
    {
        // Required-column progress for this department only, from the same
        // service the Admin Dashboard reads — so a department and admin
        // never see two different numbers for the same work.
        $activity = app(DepartmentActivityService::class);
        $workspace = $activity->forRole('account') ?? $activity->emptyProgressFor('account');

        // Every figure here is buyer-linked, so each is built from a scoped
        // query. The PRA scope is the trait's, which is the same one Supply
        // Chain's PRA list uses — Accounts and Supply Chain looking at the same
        // PRA queue must not disagree about who may see which document.
        $rows = ExcelRow::query();
        $this->applyBuyerScope($rows);

        $pras = fn () => tap(PaymentRequest::query(), fn ($q) => $this->applyBuyerScopeToPaymentRequests($q));

        $trend = $metrics->monthlyTrend($pras());
        $delta = $metrics->deltaFor($trend);

        $stats = [
            'rows' => $rows->count(),
            'pra_total' => $pras()->count(),
            'pra_pending' => $pras()->whereIn('status', [
                PaymentRequest::STATUS_PENDING_CHECK,
                PaymentRequest::STATUS_PENDING_APPROVAL,
            ])->count(),
            'pra_approved' => $pras()->where('status', PaymentRequest::STATUS_APPROVED)->count(),
        ];

        // Money side: the approved requests heading for payment, read the same way
        // the Payments screen reads them (PaymentPosition), so the two always agree.
        $payable = $pras()->whereIn('status', PaymentController::PAYABLE)
            ->withCount('items')
            ->withSum('items as lines_amount', 'pi_amount')
            ->withMin('items as required_date', 'payment_required_date')
            ->latest('id')->limit(2000)->get();
        $pay = app(PaymentPosition::class)->dashboard($payable);

        // Where every request stands, as one picture.
        $statusSegments = [
            ['label' => 'Pending approval', 'value' => $stats['pra_pending'], 'tone' => 'warning'],
            ['label' => 'Awaiting payment', 'value' => $pay['due_only_count'], 'tone' => 'danger'],
            ['label' => 'Part paid', 'value' => $pay['partial_count'], 'tone' => 'primary'],
            ['label' => 'Paid', 'value' => $pay['paid_count'], 'tone' => 'success'],
        ];

        return view('account.dashboard', compact('stats', 'workspace', 'trend', 'delta', 'pay', 'statusSegments') + $this->requisitionSummary(auth()->user()));
    }
}
