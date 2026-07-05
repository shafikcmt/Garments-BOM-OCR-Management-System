<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\PraApprover;
use App\Models\PraApproval;
use App\Models\User;
use App\Support\PraApprovalSettings;
use Illuminate\Http\Request;

/**
 * Admin management of the PRA approver pool, the approval-email toggle and the
 * full approval history (audit trail across every cycle).
 */
class PraApproverController extends Controller
{
    public function index()
    {
        $approvers = PraApprover::with(['user', 'addedBy'])
            ->join('users', 'users.id', '=', 'pra_approvers.user_id')
            ->orderBy('users.name')
            ->select('pra_approvers.*')
            ->get();

        $assignedUserIds = $approvers->pluck('user_id')->all();

        $availableUsers = User::whereNotIn('id', $assignedUserIds)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return view('admin.pra-approvers.index', [
            'approvers' => $approvers,
            'availableUsers' => $availableUsers,
            'mailEnabled' => PraApprovalSettings::mailEnabled(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        if (PraApprover::where('user_id', $validated['user_id'])->exists()) {
            return back()->with('warning', 'This user is already in the approver pool.');
        }

        $user = User::findOrFail($validated['user_id']);

        PraApprover::create([
            'user_id' => $user->id,
            'is_active' => true,
            'added_by' => auth()->id(),
        ]);

        $user->givePermissionTo('approve-pra');

        return back()->with('success', $user->name . ' has been added to the PRA approver pool.');
    }

    public function update(Request $request, PraApprover $praApprover)
    {
        $isActive = $request->boolean('is_active');
        $praApprover->update(['is_active' => $isActive]);

        $user = $praApprover->user;
        if ($user) {
            if ($isActive) {
                $user->givePermissionTo('approve-pra');
            } else {
                $user->revokePermissionTo('approve-pra');
            }
        }

        return back()->with('success', 'Approver status updated.');
    }

    public function destroy(PraApprover $praApprover)
    {
        $user = $praApprover->user;
        if ($user) {
            $user->revokePermissionTo('approve-pra');
        }

        $praApprover->delete();

        return back()->with('success', 'Approver removed from the pool. Existing approval history is preserved.');
    }

    public function updateSettings(Request $request)
    {
        AppSetting::put(PraApprovalSettings::KEY_MAIL_ENABLED, $request->boolean('pra_approval_mail_enabled') ? '1' : '0');

        return back()->with('success', 'Notification settings updated.');
    }

    public function history(Request $request)
    {
        $approvals = PraApproval::with(['paymentRequest.createdBy', 'approver'])
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.pra-approvers.history', [
            'approvals' => $approvals,
        ]);
    }
}
