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

        return view('management.dashboard', compact('stats', 'myPending', 'recentActivity'));
    }
}
