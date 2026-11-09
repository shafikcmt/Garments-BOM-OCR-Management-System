<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\BuyerOrder;
use App\Models\MotherStyle;
use App\Services\OrderApprovals;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Buyer orders (customer POs) of a style. Quantities, colours and ship dates
 * come from the workspace and are shown here; this screen keeps the destination,
 * contract reference, quotation link and remark, and runs order approval.
 * Merchandising and Admin edit, Management reads and may approve.
 */
class BuyerOrderController extends Controller
{
    public function show(Request $request, MotherStyle $mother, OrderApprovals $approvals)
    {
        abort_unless($request->user()->hasBuyerAccess((int) $mother->buyer_id), 403);
        $mother->load(['buyer', 'season']);
        $user = $request->user();

        $rows = $approvals->rows($mother)->map(function ($r) use ($approvals, $user) {
            $r['can_decide'] = $r['order'] && $approvals->canDecide($user, $r['order']);

            return $r;
        });

        return view('merchant.orders', [
            'mother' => $mother,
            'rows' => $rows,
            'costings' => $mother->costings()->where('status', 'final')->orderByDesc('id')->get(),
            'canEdit' => $user->hasAnyRole(['merchant', 'admin']),
            'backUrl' => $user->hasRole('merchant') ? route('merchant.styles.mothers.show', $mother) : route('merchant.status.style', $mother),
        ]);
    }

    public function update(Request $request, MotherStyle $mother, OrderApprovals $approvals)
    {
        $this->authorizeEdit($request, $mother);
        $data = $this->validated($request, $mother);
        $po = (string) ($request->input('po') ?? '');

        $approvals->save($mother, $po, $data, $request->user());

        return back()->with('success', 'Order details saved.');
    }

    public function submit(Request $request, MotherStyle $mother, OrderApprovals $approvals)
    {
        $this->authorizeEdit($request, $mother);
        $po = (string) ($request->input('po') ?? '');

        try {
            $approvals->save($mother, $po, $this->validated($request, $mother), $request->user());
            $approvals->submit($mother, $po, $request->user());
        } catch (\DomainException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', 'Order sent for approval.');
    }

    public function approve(Request $request, BuyerOrder $order, OrderApprovals $approvals)
    {
        abort_unless($request->user()->hasBuyerAccess((int) $order->motherStyle->buyer_id), 403);
        $comment = $request->validate(['comment' => ['nullable', 'string', 'max:1000']])['comment'] ?? null;

        try {
            $approvals->approve($order, $request->user(), $comment);
        } catch (\DomainException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', 'Order approved.');
    }

    public function returnOrder(Request $request, BuyerOrder $order, OrderApprovals $approvals)
    {
        abort_unless($request->user()->hasBuyerAccess((int) $order->motherStyle->buyer_id), 403);
        $comment = $request->validate(['comment' => ['required', 'string', 'max:1000']], ['comment.required' => 'Write why the order is returned.'])['comment'];

        try {
            $approvals->returnOrder($order, $request->user(), $comment);
        } catch (\DomainException $e) {
            return back()->with('warning', $e->getMessage());
        }

        return back()->with('success', 'Order returned to the merchant.');
    }

    private function validated(Request $request, MotherStyle $mother): array
    {
        return $request->validate([
            'destination' => ['nullable', 'string', 'max:160'],
            'contract_ref' => ['nullable', 'string', 'max:160'],
            'costing_id' => ['nullable', Rule::exists('costings', 'id')->where('mother_style_id', $mother->id)],
            'remark' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function authorizeEdit(Request $request, MotherStyle $mother): void
    {
        abort_unless($request->user()->hasAnyRole(['merchant', 'admin']) && $request->user()->hasBuyerAccess((int) $mother->buyer_id), 403);
    }
}
