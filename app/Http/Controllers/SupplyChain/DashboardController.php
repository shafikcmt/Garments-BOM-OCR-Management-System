<?php

namespace App\Http\Controllers\SupplyChain;

use App\Http\Controllers\Controller;
use App\Models\BookingPo;
use App\Models\EmailLog;
use App\Models\PaymentRequest;
use App\Services\DashboardMetricsService;
use App\Services\DepartmentActivityService;
use App\Support\Concerns\ScopesToAssignedBuyers;

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
        $workspace = $activity->forRole('supply_chain') ?? $activity->emptyProgressFor('supply_chain');

        // Every count on this card row is buyer-linked, so each one is built
        // from a scoped query rather than a bare count(). A dashboard number
        // that disagrees with the list it links to is a support call, and a
        // scoped user clicking "12 POs" must land on twelve POs.
        $trend = $metrics->monthlyTrend($this->scopedBookingPos());
        $delta = $metrics->deltaFor($trend);

        $stats = [
            'pos' => $this->scopedBookingPos()->count(),
            'pra_pending' => $this->scopedPaymentRequests()
                ->whereIn('status', [
                    PaymentRequest::STATUS_PENDING_CHECK,
                    PaymentRequest::STATUS_PENDING_APPROVAL,
                ])->count(),
            'pra_approved' => $this->scopedPaymentRequests()
                ->where('status', PaymentRequest::STATUS_APPROVED)
                ->count(),
            'emails' => $this->scopedEmailLogs()->count(),
        ];

        return view('supply-chain.dashboard', compact('stats', 'workspace', 'trend', 'delta') + $this->requisitionSummary(auth()->user()));
    }

    /** POs this viewer may see, through their BOM file's buyer. */
    private function scopedBookingPos()
    {
        $query = BookingPo::query();
        $this->applyBuyerScope($query);

        return $query;
    }

    /**
     * PRAs this viewer may see. The rule itself lives in the trait — this
     * dashboard, the PRA list it summarises and the Accounts dashboard all ask
     * it, and three copies of "who may see a PRA" is three chances to disagree.
     */
    private function scopedPaymentRequests()
    {
        $query = PaymentRequest::query();
        $this->applyBuyerScopeToPaymentRequests($query);

        return $query;
    }

    /** Sent emails this viewer may see, through the PO or PRA each belongs to. */
    private function scopedEmailLogs()
    {
        $query = EmailLog::query();
        $this->applyBuyerScopeToEmailLogs($query);

        return $query;
    }
}
