<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\PaymentRequest;
use App\Models\PraApproval;

class DashboardController extends Controller
{
    use \App\Http\Controllers\Concerns\ShowsRequisitionSummary;

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

        // The cross-department picture for the top of the page. Each block is
        // read on its own and falls back to null, so one failing reading can
        // never take the whole dashboard down.
        $user = auth()->user();
        $safe = function (callable $read) {
            try {
                return $read();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Management dashboard block failed: ' . $e->getMessage());

                return null;
            }
        };

        $orders = $safe(fn () => app(\App\Services\MerchantDashboard::class)->build($user, null));
        $supply = $safe(fn () => app(\App\Services\SupplyChainDashboard::class)->build(null, true));
        $decisions = [
            'pra' => $myPending,
            'requisitions' => (int) ($safe(fn () => app(\App\Services\RequisitionWorkflow::class)->waitingForOnce($user)->count()) ?? 0),
            'bom' => (int) ($safe(fn () => app(\App\Services\BomApprovals::class)->waitingFor($user)->count()) ?? 0),
            'followups' => (int) ($safe(fn () => \App\Models\FollowUpTask::open()->where('assigned_to', $user->id)
                ->whereDate('due_date', '<=', now()->toDateString())->count()) ?? 0),
        ];

        $metrics = app(\App\Services\DashboardMetricsService::class);
        $trend = $metrics->monthlyTrend(PaymentRequest::query());
        $delta = $metrics->deltaFor($trend);

        return view('management.dashboard', compact(
            'stats',
            'myPending',
            'recentActivity',
            'trend',
            'delta',
            'orders',
            'supply',
            'decisions'
        // Stock Summary for the current month. Management holds every
        // requisition, so this comes back company-wide.
        ) + $this->requisitionSummary(auth()->user()));
    }
}
