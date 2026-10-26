<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\PaymentRequest;
use App\Services\DepartmentActivityService;
use App\Services\MerchantDashboard;
use App\Support\Concerns\ScopesToAssignedBuyers;

/**
 * The Merchandising dashboard: orders, the material pipeline (booking, PI,
 * payment), PRAs, approvals and shipments, for the buyers this user may see,
 * plus their share of the BOM workspace.
 */
class DashboardController extends Controller
{
    use \App\Http\Controllers\Concerns\ShowsRequisitionSummary;
    use ScopesToAssignedBuyers;

    public function index(MerchantDashboard $dashboard)
    {
        $user = auth()->user();

        // Required-column progress for this department only, from the same
        // service the Admin Dashboard reads.
        $activity = app(DepartmentActivityService::class);
        $workspace = $activity->forRole('merchant') ?? $activity->emptyProgressFor('merchant');

        // A merchant confined to some buyers sees only theirs, as on every
        // other merchandising screen.
        $buyerIds = $user->isBuyerConfined() ? $user->merchantBuyerIds() : null;

        $data = $dashboard->build($user, $buyerIds);

        $pra = PaymentRequest::query();
        $this->applyBuyerScopeToPaymentRequests($pra);
        $data['kpis']['pra_pending'] = (clone $pra)->whereIn('status', [
            PaymentRequest::STATUS_PENDING_CHECK,
            PaymentRequest::STATUS_PENDING_APPROVAL,
        ])->count();
        $data['kpis']['pra_approved'] = (clone $pra)->where('status', PaymentRequest::STATUS_APPROVED)->count();

        return view('merchant.dashboard', $data + compact('workspace') + $this->requisitionSummary($user));
    }
}
