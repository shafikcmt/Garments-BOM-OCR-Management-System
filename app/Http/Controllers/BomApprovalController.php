<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\BomApproval;
use App\Models\MotherStyle;
use App\Services\BomApprovals;
use Illuminate\Http\Request;

/**
 * BOM approval: the queue of BOMs waiting for a decision, and the actions on one
 * style's BOM (submit, approve, return). Who may decide is the service's rule;
 * this class only checks the buyer and the role of the person submitting.
 */
class BomApprovalController extends Controller
{
    public function index(Request $request, BomApprovals $approvals)
    {
        $user = $request->user();
        $canSeeAll = $user->hasAnyRole(['admin', 'management']);
        $tab = $request->query('tab') === 'all' && $canSeeAll ? 'all' : 'waiting';

        $waiting = $approvals->waitingFor($user);

        $all = $tab === 'all'
            ? BomApproval::with(['motherStyle.buyer', 'motherStyle.season', 'submitter:id,name'])
                ->tap(function ($q) use ($user) {
                    if ($user->isBuyerConfined()) {
                        $q->whereHas('motherStyle', fn ($m) => $m->whereIn('buyer_id', $user->merchantBuyerIds()));
                    }
                })
                ->latest('updated_at')->limit(200)->get()
                ->filter(fn ($a) => $a->motherStyle)->values()
            : collect();

        return view('bom-approvals.index', [
            'tab' => $tab,
            'waiting' => $waiting,
            'all' => $all,
            'canSeeAll' => $canSeeAll,
            'isAdmin' => $user->hasRole('admin'),
            'managementStage' => BomApprovals::managementStage(),
            'requiredForPo' => BomApprovals::requiredForPo(),
            'service' => $approvals,
        ]);
    }

    public function submit(Request $request, MotherStyle $mother, BomApprovals $approvals)
    {
        $user = $request->user();

        abort_unless($user->hasAnyRole(['merchant', 'admin']) && $user->hasBuyerAccess((int) $mother->buyer_id), 403);

        try {
            $approvals->submit($mother, $user);
        } catch (\DomainException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', 'BOM submitted for approval.');
    }

    public function approve(Request $request, BomApproval $approval, BomApprovals $approvals)
    {
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);

        try {
            $result = $approvals->approve($approval->load('motherStyle'), $request->user(), $data['comment'] ?? null);
        } catch (\DomainException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', $result->status === BomApproval::APPROVED
            ? 'BOM approved.'
            : 'Approved. It now waits for ' . $result->levelLabel() . '.');
    }

    public function returnBack(Request $request, BomApproval $approval, BomApprovals $approvals)
    {
        $data = $request->validate(['comment' => ['required', 'string', 'max:1000']], [
            'comment.required' => 'Write the reason for returning the BOM.',
        ]);

        try {
            $approvals->return($approval->load('motherStyle'), $request->user(), $data['comment']);
        } catch (\DomainException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', 'BOM returned to the merchant.');
    }

    /** Admin only: the two switches. */
    public function settings(Request $request)
    {
        abort_unless($request->user()->hasRole('admin'), 403);

        AppSetting::put(BomApprovals::KEY_MANAGEMENT_STAGE, $request->boolean('management_stage') ? 1 : 0);
        AppSetting::put(BomApprovals::KEY_REQUIRED_FOR_PO, $request->boolean('required_for_po') ? 1 : 0);

        return back()->with('success', 'BOM approval settings saved.');
    }
}
